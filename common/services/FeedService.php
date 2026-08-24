<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Feed;
use common\models\FeedComment;
use common\models\FeedFavorite;
use common\models\FeedLike;
use common\models\FeedReport;
use common\models\Follow;
use common\models\User;
use Yii;

/**
 * 动态 + 互动（用户端，需登录 aud=app）。
 *
 * 计数一致性：关系表(唯一键防重/deleteAll受影响行数) + updateAllCounters，
 * 跨表操作用事务；取消/减法带 ['>=','xxx_count',1] 防负；重复点赞/收藏幂等不报错。
 * 列表接口批量查作者 + 当前用户 isLiked/isFavorited 防 N+1。
 */
class FeedService
{
    /**
     * 发布动态（本轮默认直接发布 status=1）。
     *
     * @param array<string,mixed> $in content(必填), media?, tags?, productIds?, city?, mediaType?
     */
    public function publish(int $userId, array $in): array
    {
        $content = trim((string) ($in['content'] ?? ''));
        if ($content === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '动态内容不能为空');
        }

        $feed = new Feed();
        $feed->user_id = $userId;
        $feed->content = $content;
        $feed->media_type = (int) ($in['mediaType'] ?? Feed::MEDIA_IMAGE);
        $feed->media = isset($in['media']) && is_array($in['media']) ? $in['media'] : [];
        $feed->tags = isset($in['tags']) && is_array($in['tags']) ? $in['tags'] : [];
        $feed->product_ids = isset($in['productIds']) && is_array($in['productIds']) ? $in['productIds'] : [];
        $feed->city = (string) ($in['city'] ?? '');
        // 文字敏感词命中 或 图片机审不过 → 转待审进人工队列；否则先发后审直接正常。
        // 图片审核走 AI 网关（阿里云内容安全），服务异常时放行不阻塞发布（见 ContentAuditService）。
        $textHit = (new SensitiveWordService())->hasHit($content);
        $imageBad = !$textHit && !(new ContentAuditService())->imagesPass($feed->media);
        $feed->status = ($textHit || $imageBad)
            ? Feed::STATUS_AUDITING
            : Feed::STATUS_NORMAL;

        $tx = Feed::getDb()->beginTransaction();
        try {
            if (!$feed->save()) {
                throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($feed) ?? '发布失败');
            }
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
        // 跨库计数（账号库 User）：提交后最终一致更新，失败不影响发布主流程
        User::updateAllCounters(['feed_count' => 1], ['id' => $userId]);
        (new TaskService())->award($userId, TaskService::TASK_PUBLISH_FEED); // 每日发动态奖励，吞异常不影响主流程

        return $this->decorate($userId, [$feed])[0];
    }

    /**
     * 推荐流（本轮纯时间倒序，不接 AI）。
     *
     * @param array<string,mixed> $in page, pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function recommendFeed(int $userId, array $in): array
    {
        $query = Feed::find()->where(['status' => Feed::STATUS_NORMAL]);
        // 热度加权（HN 风格重力衰减）：(互动加权 + 1) / (发布小时数 + 2)^1.5，同分再按 id 倒序。
        // 权重 赞1/评论2/藏2/转发3/打赏4（打赏是真金白银，信号最强）；+1 基底让零互动新帖仍按新鲜度排。
        // ponytail: 纯规则排序，不接 AI；协同过滤/个性化召回是后续 P1（doc 13 §4.2）。
        // $now 为服务端整数，内联安全（无注入），省去 orderBy 中 Expression 参数绑定。
        $now = time();
        $order = new \yii\db\Expression(
            "(like_count + comment_count * 2 + favorite_count * 2 + share_count * 3 + tip_count * 4 + 1)"
            . " / POWER(($now - created_at) / 3600 + 2, 1.5) DESC, id DESC",
        );
        return $this->paginate($userId, $query, $in, $order);
    }

    /**
     * 关注流：当前用户关注的人发的动态。
     *
     * @param array<string,mixed> $in page, pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function followingFeed(int $userId, array $in): array
    {
        $targetIds = Follow::find()->select('follow_user_id')->where(['user_id' => $userId]);
        $query = Feed::find()
            ->where(['status' => Feed::STATUS_NORMAL])
            ->andWhere(['user_id' => $targetIds]);
        return $this->paginate($userId, $query, $in);
    }

    /**
     * 某用户的动态列表（同袍主页用）。
     *
     * @param array<string,mixed> $in page, pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function feedsByUser(int $userId, int $targetId, array $in): array
    {
        $query = Feed::find()
            ->where(['status' => Feed::STATUS_NORMAL])
            ->andWhere(['user_id' => $targetId]);
        return $this->paginate($userId, $query, $in);
    }

    /**
     * 动态详情（含作者 + 当前用户互动态）。
     */
    public function detail(int $userId, int $feedId): array
    {
        $feed = Feed::findOne(['id' => $feedId]);
        if ($feed === null) {
            throw new BizException(ErrorCode::FEED_NOT_FOUND);
        }
        // 下架动态仅作者本人可见
        if ((int) $feed->status !== Feed::STATUS_NORMAL && (int) $feed->user_id !== $userId) {
            throw new BizException(ErrorCode::FEED_STATUS_INVALID, '动态不可见');
        }
        return $this->decorate($userId, [$feed])[0];
    }

    /**
     * 举报动态。同一用户对同一动态只能举报一次（唯一键 feed_id+user_id）。
     * 提交后进管理端待处理队列，复用 feed:audit 权限处置。
     *
     * @param array<string,mixed> $in reason(必填 1~5), detail?
     */
    public function report(int $userId, int $feedId, array $in): array
    {
        $feed = Feed::findOne(['id' => $feedId]);
        if ($feed === null) {
            throw new BizException(ErrorCode::FEED_NOT_FOUND);
        }
        if ((int) $feed->user_id === $userId) {
            throw new BizException(ErrorCode::FORBIDDEN, '不能举报自己的动态');
        }

        $report = new FeedReport();
        $report->feed_id = $feedId;
        $report->user_id = $userId;
        $report->reason = (int) ($in['reason'] ?? 0);
        $report->detail = trim((string) ($in['detail'] ?? ''));
        if (!$report->validate()) {
            throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($report) ?? '举报参数有误');
        }
        if (!$report->save(false)) {
            // 唯一键冲突 = 已举报过（save(false) 跳过校验，靠 DB 唯一键兜底）
            throw new BizException(ErrorCode::REPORT_ALREADY, '你已举报过该动态');
        }
        return $report->toArray();
    }

    /**
     * 删除自己的动态（硬删，连带 like/favorite/comment）。
     */
    public function deleteOwn(int $userId, int $feedId): array
    {
        $feed = Feed::findOne(['id' => $feedId]);
        if ($feed === null) {
            throw new BizException(ErrorCode::FEED_NOT_FOUND);
        }
        if ((int) $feed->user_id !== $userId) {
            throw new BizException(ErrorCode::FORBIDDEN, '只能删除自己的动态');
        }

        $tx = Feed::getDb()->beginTransaction();
        try {
            FeedLike::deleteAll(['feed_id' => $feedId]);
            FeedFavorite::deleteAll(['feed_id' => $feedId]);
            FeedComment::deleteAll(['feed_id' => $feedId]);
            $feed->delete();
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
        // 跨库计数（账号库 User）：提交后最终一致更新
        User::updateAllCounters(['feed_count' => -1], ['and', ['id' => $userId], ['>=', 'feed_count', 1]]);

        return ['id' => $feedId];
    }

    // ---------------- 互动 ----------------

    /** 点赞（幂等）。 */
    public function like(int $userId, int $feedId): array
    {
        $feed = $this->requireFeed($feedId);
        $exists = FeedLike::findOne(['feed_id' => $feedId, 'user_id' => $userId]);
        if ($exists === null) {
            $tx = Feed::getDb()->beginTransaction();
            try {
                $like = new FeedLike();
                $like->feed_id = $feedId;
                $like->user_id = $userId;
                $like->save(false);
                Feed::updateAllCounters(['like_count' => 1], ['id' => $feedId]);
                $tx->commit();
            } catch (\Throwable $e) {
                $tx->rollBack();
                throw $e;
            }
            $feed->refresh();
        }
        return ['liked' => true, 'likeCount' => (int) $feed->like_count];
    }

    /** 取消点赞。 */
    public function unlike(int $userId, int $feedId): array
    {
        $feed = $this->requireFeed($feedId);
        $affected = FeedLike::deleteAll(['feed_id' => $feedId, 'user_id' => $userId]);
        if ($affected > 0) {
            Feed::updateAllCounters(['like_count' => -1], ['and', ['id' => $feedId], ['>=', 'like_count', 1]]);
            $feed->refresh();
        }
        return ['liked' => false, 'likeCount' => (int) $feed->like_count];
    }

    /** 收藏（幂等）。 */
    public function favorite(int $userId, int $feedId): array
    {
        $feed = $this->requireFeed($feedId);
        $exists = FeedFavorite::findOne(['feed_id' => $feedId, 'user_id' => $userId]);
        if ($exists === null) {
            $tx = Feed::getDb()->beginTransaction();
            try {
                $fav = new FeedFavorite();
                $fav->feed_id = $feedId;
                $fav->user_id = $userId;
                $fav->save(false);
                Feed::updateAllCounters(['favorite_count' => 1], ['id' => $feedId]);
                $tx->commit();
            } catch (\Throwable $e) {
                $tx->rollBack();
                throw $e;
            }
            $feed->refresh();
        }
        return ['favorited' => true, 'favoriteCount' => (int) $feed->favorite_count];
    }

    /** 取消收藏。 */
    public function unfavorite(int $userId, int $feedId): array
    {
        $feed = $this->requireFeed($feedId);
        $affected = FeedFavorite::deleteAll(['feed_id' => $feedId, 'user_id' => $userId]);
        if ($affected > 0) {
            Feed::updateAllCounters(['favorite_count' => -1], ['and', ['id' => $feedId], ['>=', 'favorite_count', 1]]);
            $feed->refresh();
        }
        return ['favorited' => false, 'favoriteCount' => (int) $feed->favorite_count];
    }

    /** 转发（本轮仅计数）。 */
    public function share(int $userId, int $feedId): array
    {
        $feed = $this->requireFeed($feedId);
        Feed::updateAllCounters(['share_count' => 1], ['id' => $feedId]);
        $feed->refresh();
        return ['shareCount' => (int) $feed->share_count];
    }

    /**
     * 评论列表（分页，含评论者）。
     *
     * @param array<string,mixed> $in page, pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function comments(int $userId, int $feedId, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = FeedComment::find()->where(['feed_id' => $feedId]);
        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        $authors = $this->authorViews(array_map(static fn (FeedComment $c): int => (int) $c->user_id, $rows));
        $list = [];
        foreach ($rows as $c) {
            /** @var FeedComment $c */
            $view = $c->toArray();
            $view['author'] = $authors[(int) $c->user_id] ?? null;
            $list[] = $view;
        }

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 发表评论。
     *
     * @param array<string,mixed> $in content(必填), parentId?
     */
    public function addComment(int $userId, int $feedId, array $in): array
    {
        $this->requireFeed($feedId);
        $content = trim((string) ($in['content'] ?? ''));
        if ($content === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '评论内容不能为空');
        }
        // 评论无审核状态字段且高频，命中敏感词直接拒绝（不进队列）
        if ((new SensitiveWordService())->hasHit($content)) {
            throw new BizException(ErrorCode::CONTENT_SENSITIVE);
        }
        $parentId = isset($in['parentId']) && $in['parentId'] !== '' ? (int) $in['parentId'] : null;
        if ($parentId !== null) {
            $parent = FeedComment::findOne(['id' => $parentId, 'feed_id' => $feedId]);
            if ($parent === null) {
                throw new BizException(ErrorCode::COMMENT_NOT_FOUND);
            }
        }

        $comment = new FeedComment();
        $comment->feed_id = $feedId;
        $comment->user_id = $userId;
        $comment->parent_id = $parentId;
        $comment->content = $content;

        $tx = Feed::getDb()->beginTransaction();
        try {
            if (!$comment->save()) {
                throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($comment) ?? '评论失败');
            }
            Feed::updateAllCounters(['comment_count' => 1], ['id' => $feedId]);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        $view = $comment->toArray();
        $view['author'] = $this->authorViews([$userId])[$userId] ?? null;
        return $view;
    }

    // ---------------- 内部 ----------------

    private function requireFeed(int $feedId): Feed
    {
        $feed = Feed::findOne(['id' => $feedId]);
        if ($feed === null) {
            throw new BizException(ErrorCode::FEED_NOT_FOUND);
        }
        if ((int) $feed->status !== Feed::STATUS_NORMAL) {
            throw new BizException(ErrorCode::FEED_STATUS_INVALID);
        }
        return $feed;
    }

    /**
     * 分页 + 装饰（作者 + 当前用户互动态），批量查防 N+1。
     *
     * @param \yii\db\ActiveQuery<Feed> $query
     * @param array<string,mixed> $in
     * @param array<string,int>|\yii\db\Expression $orderBy 排序（默认 id 倒序）；推荐流传热度表达式。
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    private function paginate(int $userId, \yii\db\ActiveQuery $query, array $in, array|\yii\db\Expression $orderBy = ['id' => SORT_DESC]): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $total = (int) $query->count();
        $rows = $query->orderBy($orderBy)
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => $this->decorate($userId, $rows),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 给一批动态附作者信息 + 当前用户 isLiked/isFavorited（批量查）。
     *
     * @param array<int,Feed> $feeds
     * @return array<int,array>
     */
    private function decorate(int $userId, array $feeds): array
    {
        if ($feeds === []) {
            return [];
        }
        $feedIds = array_map(static fn (Feed $f): int => $f->getId(), $feeds);
        $authorIds = array_map(static fn (Feed $f): int => (int) $f->user_id, $feeds);
        $authors = $this->authorViews($authorIds);

        $likedSet = FeedLike::find()
            ->select('feed_id')
            ->where(['user_id' => $userId, 'feed_id' => $feedIds])
            ->column();
        $likedSet = array_flip(array_map('intval', $likedSet));
        $favSet = FeedFavorite::find()
            ->select('feed_id')
            ->where(['user_id' => $userId, 'feed_id' => $feedIds])
            ->column();
        $favSet = array_flip(array_map('intval', $favSet));

        $list = [];
        foreach ($feeds as $f) {
            $view = $f->toDetailArray();
            $view['author'] = $authors[(int) $f->user_id] ?? null;
            $view['isLiked'] = isset($likedSet[$f->getId()]);
            $view['isFavorited'] = isset($favSet[$f->getId()]);
            $list[] = $view;
        }
        return $list;
    }

    /**
     * 批量查作者公开信息。
     *
     * @param array<int,int> $userIds
     * @return array<int,array> userId => publicArray
     */
    private function authorViews(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter($userIds)));
        if ($ids === []) {
            return [];
        }
        $users = User::find()->where(['id' => $ids])->all();
        $map = [];
        foreach ($users as $u) {
            /** @var User $u */
            $map[$u->getId()] = $u->toPublicArray();
        }
        return $map;
    }

    private function firstError(\yii\db\ActiveRecord $model): ?string
    {
        foreach ($model->getFirstErrors() as $msg) {
            return $msg;
        }
        return null;
    }
}
