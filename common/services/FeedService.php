<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Feed;
use common\models\FeedComment;
use common\models\FeedFavorite;
use common\models\FeedLike;
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
        $feed->status = Feed::STATUS_NORMAL;

        $tx = Yii::$app->db->beginTransaction();
        try {
            if (!$feed->save()) {
                throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($feed) ?? '发布失败');
            }
            User::updateAllCounters(['feed_count' => 1], ['id' => $userId]);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

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
        return $this->paginate($userId, $query, $in);
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

        $tx = Yii::$app->db->beginTransaction();
        try {
            FeedLike::deleteAll(['feed_id' => $feedId]);
            FeedFavorite::deleteAll(['feed_id' => $feedId]);
            FeedComment::deleteAll(['feed_id' => $feedId]);
            $feed->delete();
            User::updateAllCounters(['feed_count' => -1], ['and', ['id' => $userId], ['>=', 'feed_count', 1]]);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        return ['id' => $feedId];
    }

    // ---------------- 互动 ----------------

    /** 点赞（幂等）。 */
    public function like(int $userId, int $feedId): array
    {
        $feed = $this->requireFeed($feedId);
        $exists = FeedLike::findOne(['feed_id' => $feedId, 'user_id' => $userId]);
        if ($exists === null) {
            $tx = Yii::$app->db->beginTransaction();
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
            $tx = Yii::$app->db->beginTransaction();
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

        $tx = Yii::$app->db->beginTransaction();
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
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    private function paginate(int $userId, \yii\db\ActiveQuery $query, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
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
