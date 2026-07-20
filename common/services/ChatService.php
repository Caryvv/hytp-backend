<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\ChatConversation;
use common\models\ChatMessage;
use common\models\User;
use Yii;

/**
 * 私信（用户端，需登录 aud=app）。轮询拉取，afterId 增量。
 *
 * 会话用 (user_a<user_b) 有序对唯一，避免 A→B / B→A 重复会话。
 */
class ChatService
{
    /**
     * 打开/创建与某用户的会话（返回会话 id + 对方资料）。
     */
    public function openConversation(int $userId, int $targetId): array
    {
        if ($targetId === $userId) {
            throw new BizException(ErrorCode::PARAM_INVALID, '不能与自己私信');
        }
        $target = User::findOne(['id' => $targetId, 'status' => User::STATUS_ACTIVE]);
        if ($target === null) {
            throw new BizException(ErrorCode::USER_NOT_FOUND);
        }
        $conv = $this->findOrCreate($userId, $targetId);
        return array_merge($conv->toArray(), [
            'target' => $target->toPublicArray(),
        ]);
    }

    /**
     * 会话列表（附对方资料 + 未读数），按 last_at 倒序。
     *
     * @param array<string,mixed> $in page, pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function conversations(int $userId, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = ChatConversation::find()
            ->where(['or', ['user_a' => $userId], ['user_b' => $userId]]);
        $total = (int) $query->count();
        $rows = $query->orderBy(['last_at' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        // 批量查对方资料
        $otherIds = array_map(static fn (ChatConversation $c): int => $c->otherUserId($userId), $rows);
        $authors = $this->userViews($otherIds);

        $list = [];
        foreach ($rows as $c) {
            /** @var ChatConversation $c */
            $otherId = $c->otherUserId($userId);
            $unread = (int) ChatMessage::find()
                ->where(['conversation_id' => $c->getId(), 'to_user' => $userId, 'is_read' => 0])
                ->count();
            $view = $c->toArray();
            $view['target'] = $authors[$otherId] ?? null;
            $view['unread'] = $unread;
            $list[] = $view;
        }

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 会话历史消息（afterId 增量拉取，正序）。拉取后把发给我的标记已读。
     *
     * @param array<string,mixed> $in afterId?, limit?
     * @return array{list:array<int,array>}
     */
    public function messages(int $userId, int $conversationId, array $in): array
    {
        $conv = $this->requireOwnConversation($userId, $conversationId);
        $afterId = (int) ($in['afterId'] ?? 0);
        $limit = min(100, max(1, (int) ($in['limit'] ?? 50)));

        $query = ChatMessage::find()->where(['conversation_id' => $conv->getId()]);
        if ($afterId > 0) {
            $query->andWhere(['>', 'id', $afterId]);
        }
        $rows = $query->orderBy(['id' => SORT_ASC])->limit($limit)->all();

        // 标记发给我的为已读
        ChatMessage::updateAll(
            ['is_read' => 1],
            ['conversation_id' => $conv->getId(), 'to_user' => $userId, 'is_read' => 0]
        );

        return ['list' => array_map(static fn (ChatMessage $m): array => $m->toArray(), $rows)];
    }

    /**
     * 发私信（更新会话 last_msg/last_at）。
     *
     * @param array<string,mixed> $in content
     */
    public function sendMessage(int $userId, int $conversationId, array $in): array
    {
        $conv = $this->requireOwnConversation($userId, $conversationId);
        $content = trim((string) ($in['content'] ?? ''));
        if ($content === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '消息内容不能为空');
        }
        $toUser = $conv->otherUserId($userId);

        $tx = ChatConversation::getDb()->beginTransaction();
        try {
            $msg = new ChatMessage();
            $msg->conversation_id = $conv->getId();
            $msg->from_user = $userId;
            $msg->to_user = $toUser;
            $msg->content = $content;
            $msg->msg_type = ChatMessage::TYPE_TEXT;
            if (!$msg->save()) {
                throw new BizException(ErrorCode::PARAM_INVALID, '发送失败');
            }
            $conv->last_msg = mb_substr($content, 0, 100);
            $conv->last_at = time();
            $conv->save(false);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        return $msg->toArray();
    }

    // ---------------- 内部 ----------------

    private function findOrCreate(int $userId, int $targetId): ChatConversation
    {
        $a = min($userId, $targetId);
        $b = max($userId, $targetId);
        $conv = ChatConversation::findOne(['user_a' => $a, 'user_b' => $b]);
        if ($conv === null) {
            $conv = new ChatConversation();
            $conv->user_a = $a;
            $conv->user_b = $b;
            $conv->last_at = time();
            $conv->save(false);
        }
        return $conv;
    }

    private function requireOwnConversation(int $userId, int $conversationId): ChatConversation
    {
        $conv = ChatConversation::findOne(['id' => $conversationId]);
        if ($conv === null) {
            throw new BizException(ErrorCode::CONVERSATION_NOT_FOUND);
        }
        if ((int) $conv->user_a !== $userId && (int) $conv->user_b !== $userId) {
            throw new BizException(ErrorCode::FORBIDDEN);
        }
        return $conv;
    }

    /**
     * @param array<int,int> $userIds
     * @return array<int,array>
     */
    private function userViews(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter($userIds)));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach (User::find()->where(['id' => $ids])->all() as $u) {
            /** @var User $u */
            $map[$u->getId()] = $u->toPublicArray();
        }
        return $map;
    }
}
