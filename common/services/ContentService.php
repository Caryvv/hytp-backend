<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Content;
use common\models\ContentFavorite;
use common\models\ContentLike;
use common\models\ContentSignup;

/**
 * 文旅 + 文化传承 内容业务。
 *
 * 用户端：只读浏览（仅上线内容）+ 点赞/收藏/报名预约。
 * 管理端：运营录入 CRUD（create/update/toggle/delete）。
 *
 * 计数一致性：关系表(唯一键防重/deleteAll受影响行数) + updateAllCounters，
 * 跨表操作用事务；取消/减法带 ['>=','xxx_count',1] 防负；重复点赞/收藏幂等不报错。
 * 列表接口批量查当前用户 isLiked/isFavorited/isSignedUp 防 N+1。
 */
class ContentService
{
    private const SORT_MAP = [
        'latest' => ['created_at' => SORT_DESC, 'id' => SORT_DESC],
        'hot' => ['like_count' => SORT_DESC, 'id' => SORT_DESC],
    ];

    // ---------------- 用户端只读 ----------------

    /**
     * 内容列表（仅上线，按 type/city/category 筛选）。
     *
     * @param array<string,mixed> $in type, city, category, sort, page, pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function list(int $userId, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = $this->onlineQuery();
        if (!empty($in['type'])) {
            $query->andWhere(['type' => (int) $in['type']]);
        }
        if (!empty($in['city'])) {
            $query->andWhere(['city' => (string) $in['city']]);
        }
        if (!empty($in['category'])) {
            $query->andWhere(['category' => (string) $in['category']]);
        }
        $order = self::SORT_MAP[(string) ($in['sort'] ?? 'latest')] ?? self::SORT_MAP['latest'];

        $total = (int) $query->count();
        $rows = $query->orderBy($order)
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => $this->decorate($userId, $rows, false),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 内容详情（含当前用户互动态）。
     */
    public function detail(int $userId, int $contentId): array
    {
        $content = Content::findOne(['id' => $contentId]);
        if ($content === null) {
            throw new BizException(ErrorCode::CONTENT_NOT_FOUND);
        }
        if ((int) $content->status !== Content::STATUS_ON) {
            throw new BizException(ErrorCode::CONTENT_STATUS_INVALID, '内容不可见');
        }
        return $this->decorate($userId, [$content], true)[0];
    }

    // ---------------- 互动 ----------------

    /** 点赞（幂等）。 */
    public function like(int $userId, int $contentId): array
    {
        $content = $this->requireContent($contentId);
        $exists = ContentLike::findOne(['content_id' => $contentId, 'user_id' => $userId]);
        if ($exists === null) {
            $tx = Content::getDb()->beginTransaction();
            try {
                $like = new ContentLike();
                $like->content_id = $contentId;
                $like->user_id = $userId;
                $like->save(false);
                Content::updateAllCounters(['like_count' => 1], ['id' => $contentId]);
                $tx->commit();
            } catch (\Throwable $e) {
                $tx->rollBack();
                throw $e;
            }
            $content->refresh();
        }
        return ['liked' => true, 'likeCount' => (int) $content->like_count];
    }

    /** 取消点赞。 */
    public function unlike(int $userId, int $contentId): array
    {
        $content = $this->requireContent($contentId);
        $affected = ContentLike::deleteAll(['content_id' => $contentId, 'user_id' => $userId]);
        if ($affected > 0) {
            Content::updateAllCounters(['like_count' => -1], ['and', ['id' => $contentId], ['>=', 'like_count', 1]]);
            $content->refresh();
        }
        return ['liked' => false, 'likeCount' => (int) $content->like_count];
    }

    /** 收藏（幂等）。 */
    public function favorite(int $userId, int $contentId): array
    {
        $content = $this->requireContent($contentId);
        $exists = ContentFavorite::findOne(['content_id' => $contentId, 'user_id' => $userId]);
        if ($exists === null) {
            $tx = Content::getDb()->beginTransaction();
            try {
                $fav = new ContentFavorite();
                $fav->content_id = $contentId;
                $fav->user_id = $userId;
                $fav->save(false);
                Content::updateAllCounters(['favorite_count' => 1], ['id' => $contentId]);
                $tx->commit();
            } catch (\Throwable $e) {
                $tx->rollBack();
                throw $e;
            }
            $content->refresh();
        }
        return ['favorited' => true, 'favoriteCount' => (int) $content->favorite_count];
    }

    /** 取消收藏。 */
    public function unfavorite(int $userId, int $contentId): array
    {
        $content = $this->requireContent($contentId);
        $affected = ContentFavorite::deleteAll(['content_id' => $contentId, 'user_id' => $userId]);
        if ($affected > 0) {
            Content::updateAllCounters(['favorite_count' => -1], ['and', ['id' => $contentId], ['>=', 'favorite_count', 1]]);
            $content->refresh();
        }
        return ['favorited' => false, 'favoriteCount' => (int) $content->favorite_count];
    }

    /**
     * 报名预约。唯一键 (content_id,user_id)：首次 create，已取消则重新激活。已在报名中则报错。
     *
     * @param array<string,mixed> $in name(必填), phone(必填), quantity?
     */
    public function signup(int $userId, int $contentId, array $in): array
    {
        $content = $this->requireContent($contentId);
        $name = trim((string) ($in['name'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        $quantity = max(1, (int) ($in['quantity'] ?? 1));
        if ($name === '' || $phone === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '请填写报名人姓名和手机号');
        }

        $signup = ContentSignup::findOne(['content_id' => $contentId, 'user_id' => $userId]);
        if ($signup !== null && (int) $signup->status === ContentSignup::STATUS_ACTIVE) {
            throw new BizException(ErrorCode::SIGNUP_ALREADY_EXISTS);
        }

        $tx = Content::getDb()->beginTransaction();
        try {
            if ($signup === null) {
                $signup = new ContentSignup();
                $signup->content_id = $contentId;
                $signup->user_id = $userId;
            }
            $signup->name = $name;
            $signup->phone = $phone;
            $signup->quantity = $quantity;
            $signup->status = ContentSignup::STATUS_ACTIVE;
            if (!$signup->save()) {
                throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($signup) ?? '报名失败');
            }
            Content::updateAllCounters(['signup_count' => 1], ['id' => $contentId]);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
        $content->refresh();
        return ['enrolled' => true, 'signupCount' => (int) $content->signup_count];
    }

    /** 取消报名。 */
    public function cancelSignup(int $userId, int $contentId): array
    {
        $content = $this->requireContent($contentId);
        $signup = ContentSignup::findOne(['content_id' => $contentId, 'user_id' => $userId]);
        if ($signup !== null && (int) $signup->status === ContentSignup::STATUS_ACTIVE) {
            $tx = Content::getDb()->beginTransaction();
            try {
                $signup->status = ContentSignup::STATUS_CANCELLED;
                $signup->save(false);
                Content::updateAllCounters(['signup_count' => -1], ['and', ['id' => $contentId], ['>=', 'signup_count', 1]]);
                $tx->commit();
            } catch (\Throwable $e) {
                $tx->rollBack();
                throw $e;
            }
            $content->refresh();
        }
        return ['enrolled' => false, 'signupCount' => (int) $content->signup_count];
    }

    // ---------------- 管理端写操作 ----------------

    /**
     * 管理端内容列表（含下架，按 type 筛选）。
     *
     * @param array<string,mixed> $in type, status, page, pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function adminList(array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = Content::find();
        if (!empty($in['type'])) {
            $query->andWhere(['type' => (int) $in['type']]);
        }
        if (isset($in['status']) && $in['status'] !== '') {
            $query->andWhere(['status' => (int) $in['status']]);
        }

        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => array_map(static fn (Content $c): array => $c->toAdminArray(), $rows),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 某内容的报名名单（默认只列报名中，分页）。
     *
     * @param array<string,mixed> $in page, pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function signupList(int $contentId, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = ContentSignup::find()
            ->where(['content_id' => $contentId, 'status' => ContentSignup::STATUS_ACTIVE]);
        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => array_map(static fn (ContentSignup $s): array => $s->toArray(), $rows),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 新建内容。
     *
     * @param array<string,mixed> $in
     */
    public function create(array $in): array
    {
        $content = new Content();
        $content->type = (int) ($in['type'] ?? Content::TYPE_TRAVEL);
        $this->fill($content, $in);
        if (!$content->save()) {
            throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($content) ?? '内容创建失败');
        }
        return $content->toAdminArray();
    }

    /**
     * 编辑内容。
     *
     * @param array<string,mixed> $in
     */
    public function update(int $contentId, array $in): array
    {
        $content = $this->requireAny($contentId);
        if (isset($in['type'])) {
            $content->type = (int) $in['type'];
        }
        $this->fill($content, $in);
        if (!$content->save()) {
            throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($content) ?? '内容保存失败');
        }
        return $content->toAdminArray();
    }

    /** 上/下线切换。 */
    public function toggle(int $contentId): array
    {
        $content = $this->requireAny($contentId);
        $content->status = (int) $content->status === Content::STATUS_ON
            ? Content::STATUS_OFF
            : Content::STATUS_ON;
        if (!$content->save(false)) {
            throw new BizException(ErrorCode::INTERNAL_ERROR, '操作失败');
        }
        return $content->toListArray();
    }

    /** 删除内容（连带 like/favorite/signup）。 */
    public function delete(int $contentId): array
    {
        $content = $this->requireAny($contentId);
        $tx = Content::getDb()->beginTransaction();
        try {
            ContentLike::deleteAll(['content_id' => $contentId]);
            ContentFavorite::deleteAll(['content_id' => $contentId]);
            ContentSignup::deleteAll(['content_id' => $contentId]);
            $content->delete();
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
        return ['id' => $contentId];
    }

    // ---------------- 内部 ----------------

    /**
     * @return \yii\db\ActiveQuery<Content>
     */
    private function onlineQuery(): \yii\db\ActiveQuery
    {
        return Content::find()->where(['status' => Content::STATUS_ON]);
    }

    private function requireContent(int $contentId): Content
    {
        $content = Content::findOne(['id' => $contentId]);
        if ($content === null) {
            throw new BizException(ErrorCode::CONTENT_NOT_FOUND);
        }
        if ((int) $content->status !== Content::STATUS_ON) {
            throw new BizException(ErrorCode::CONTENT_STATUS_INVALID);
        }
        return $content;
    }

    private function requireAny(int $contentId): Content
    {
        $content = Content::findOne(['id' => $contentId]);
        if ($content === null) {
            throw new BizException(ErrorCode::CONTENT_NOT_FOUND);
        }
        return $content;
    }

    /**
     * 从入参填充可编辑字段（白名单）。
     *
     * @param array<string,mixed> $in
     */
    private function fill(Content $content, array $in): void
    {
        if (isset($in['title'])) {
            $content->title = (string) $in['title'];
        }
        if (isset($in['cover'])) {
            $content->cover = (string) $in['cover'];
        }
        if (array_key_exists('images', $in)) {
            $content->images = is_array($in['images']) ? $in['images'] : null;
        }
        if (isset($in['detail'])) {
            $content->detail = (string) $in['detail'];
        }
        if (isset($in['city'])) {
            $content->city = (string) $in['city'];
        }
        if (isset($in['category'])) {
            $content->category = (string) $in['category'];
        }
        if (isset($in['status'])) {
            $content->status = (int) $in['status'];
        }
    }

    /**
     * 给一批内容附当前用户 isLiked/isFavorited/isSignedUp（批量查防 N+1）。
     *
     * @param array<int,Content> $contents
     * @param bool $detail true 用 toDetailArray（含正文/图集），false 用 toListArray
     * @return array<int,array>
     */
    private function decorate(int $userId, array $contents, bool $detail): array
    {
        if ($contents === []) {
            return [];
        }
        $ids = array_map(static fn (Content $c): int => $c->getId(), $contents);

        $likedSet = array_flip(array_map('intval', ContentLike::find()
            ->select('content_id')->where(['user_id' => $userId, 'content_id' => $ids])->column()));
        $favSet = array_flip(array_map('intval', ContentFavorite::find()
            ->select('content_id')->where(['user_id' => $userId, 'content_id' => $ids])->column()));
        $signedSet = array_flip(array_map('intval', ContentSignup::find()
            ->select('content_id')
            ->where(['user_id' => $userId, 'content_id' => $ids, 'status' => ContentSignup::STATUS_ACTIVE])
            ->column()));

        $list = [];
        foreach ($contents as $c) {
            $view = $detail ? $c->toDetailArray() : $c->toListArray();
            $view['isLiked'] = isset($likedSet[$c->getId()]);
            $view['isFavorited'] = isset($favSet[$c->getId()]);
            $view['isSignedUp'] = isset($signedSet[$c->getId()]);
            $list[] = $view;
        }
        return $list;
    }

    private function firstError(\yii\db\ActiveRecord $model): ?string
    {
        foreach ($model->getFirstErrors() as $msg) {
            return $msg;
        }
        return null;
    }
}
