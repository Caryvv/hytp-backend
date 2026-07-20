<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Follow;
use common\models\User;
use Yii;

/**
 * 关注关系 + 同袍公开主页（用户端，需登录 aud=app）。
 *
 * 关注/取关双向计数(following_count/follower_count)用事务，取消带防负，重复关注幂等。
 */
class FollowService
{
    /** 关注（幂等）。 */
    public function follow(int $userId, int $targetId): array
    {
        if ($targetId === $userId) {
            throw new BizException(ErrorCode::FOLLOW_SELF);
        }
        $target = User::findOne(['id' => $targetId, 'status' => User::STATUS_ACTIVE]);
        if ($target === null) {
            throw new BizException(ErrorCode::USER_NOT_FOUND);
        }

        $exists = Follow::findOne(['user_id' => $userId, 'follow_user_id' => $targetId]);
        if ($exists === null) {
            // 关系写社交库（唯一键防重）；双向计数在账号库，提交后最终一致更新
            $f = new Follow();
            $f->user_id = $userId;
            $f->follow_user_id = $targetId;
            $f->save(false);
            User::updateAllCounters(['following_count' => 1], ['id' => $userId]);
            User::updateAllCounters(['follower_count' => 1], ['id' => $targetId]);
            $target->refresh();
        }
        return ['followed' => true, 'followerCount' => (int) $target->follower_count];
    }

    /** 取关。 */
    public function unfollow(int $userId, int $targetId): array
    {
        $target = User::findOne(['id' => $targetId]);
        if ($target === null) {
            throw new BizException(ErrorCode::USER_NOT_FOUND);
        }
        $affected = Follow::deleteAll(['user_id' => $userId, 'follow_user_id' => $targetId]);
        if ($affected > 0) {
            User::updateAllCounters(['following_count' => -1], ['and', ['id' => $userId], ['>=', 'following_count', 1]]);
            User::updateAllCounters(['follower_count' => -1], ['and', ['id' => $targetId], ['>=', 'follower_count', 1]]);
            $target->refresh();
        }
        return ['followed' => false, 'followerCount' => (int) $target->follower_count];
    }

    /**
     * 同袍公开主页（资料 + 统计 + 关注态）。
     */
    public function profile(int $userId, int $targetId): array
    {
        $target = User::findOne(['id' => $targetId, 'status' => User::STATUS_ACTIVE]);
        if ($target === null) {
            throw new BizException(ErrorCode::USER_NOT_FOUND);
        }
        $isFollowed = $targetId !== $userId
            && Follow::find()->where(['user_id' => $userId, 'follow_user_id' => $targetId])->exists();

        return array_merge($target->toPublicArray(), [
            'isFollowed' => $isFollowed,
            'isSelf' => $targetId === $userId,
        ]);
    }
}
