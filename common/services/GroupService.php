<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\GroupMember;
use common\models\GroupMessage;
use common\models\SocialGroup;
use common\models\User;
use Yii;

/**
 * 社群（用户端，需登录 aud=app）。创建/加入/退出/群聊，群聊轮询 afterId 增量。
 */
class GroupService
{
    /**
     * 创建社群（群主自动入群 role=2，member_count=1）。
     *
     * @param array<string,mixed> $in name(必填), type?, avatar?, intro?, city?
     */
    public function create(int $userId, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '群名称不能为空');
        }

        $tx = Yii::$app->db->beginTransaction();
        try {
            $group = new SocialGroup();
            $group->name = $name;
            $group->type = (int) ($in['type'] ?? SocialGroup::TYPE_REGION);
            $group->owner_id = $userId;
            $group->avatar = (string) ($in['avatar'] ?? '');
            $group->intro = (string) ($in['intro'] ?? '');
            $group->city = (string) ($in['city'] ?? '');
            $group->member_count = 1;
            $group->status = SocialGroup::STATUS_ACTIVE;
            if (!$group->save()) {
                throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($group) ?? '建群失败');
            }
            $this->addMember($group->getId(), $userId, GroupMember::ROLE_OWNER);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        return $this->decorate($userId, $group);
    }

    /**
     * 社群列表（?type=&city=，附是否已加入）。
     *
     * @param array<string,mixed> $in type, city, page, pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function list(int $userId, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = SocialGroup::find()->where(['status' => SocialGroup::STATUS_ACTIVE]);
        if (!empty($in['type'])) {
            $query->andWhere(['type' => (int) $in['type']]);
        }
        if (!empty($in['city'])) {
            $query->andWhere(['city' => (string) $in['city']]);
        }
        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        // 批量查我加入了哪些
        $groupIds = array_map(static fn (SocialGroup $g): int => $g->getId(), $rows);
        $joinedSet = [];
        if ($groupIds !== []) {
            $joined = GroupMember::find()->select('group_id')
                ->where(['user_id' => $userId, 'group_id' => $groupIds])->column();
            $joinedSet = array_flip(array_map('intval', $joined));
        }

        $list = [];
        foreach ($rows as $g) {
            /** @var SocialGroup $g */
            $view = $g->toArray();
            $view['isJoined'] = isset($joinedSet[$g->getId()]);
            $list[] = $view;
        }

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 社群详情（附我的角色 + 是否加入）。
     */
    public function detail(int $userId, int $groupId): array
    {
        $group = $this->requireGroup($groupId);
        return $this->decorate($userId, $group);
    }

    /**
     * 加入社群（幂等）。
     */
    public function join(int $userId, int $groupId): array
    {
        $group = $this->requireGroup($groupId);
        $exists = GroupMember::findOne(['group_id' => $groupId, 'user_id' => $userId]);
        if ($exists === null) {
            $tx = Yii::$app->db->beginTransaction();
            try {
                $this->addMember($groupId, $userId, GroupMember::ROLE_MEMBER);
                SocialGroup::updateAllCounters(['member_count' => 1], ['id' => $groupId]);
                $tx->commit();
            } catch (\Throwable $e) {
                $tx->rollBack();
                throw $e;
            }
            $group->refresh();
        }
        return $this->decorate($userId, $group);
    }

    /**
     * 退出社群。群主退出即解散（状态置解散）。
     */
    public function quit(int $userId, int $groupId): array
    {
        $group = $this->requireGroup($groupId);
        $member = GroupMember::findOne(['group_id' => $groupId, 'user_id' => $userId]);
        if ($member === null) {
            throw new BizException(ErrorCode::NOT_GROUP_MEMBER);
        }

        $tx = Yii::$app->db->beginTransaction();
        try {
            if ((int) $member->role === GroupMember::ROLE_OWNER) {
                // 群主退出 → 解散群
                $group->status = SocialGroup::STATUS_DISBANDED;
                $group->save(false);
                GroupMember::deleteAll(['group_id' => $groupId]);
            } else {
                $member->delete();
                SocialGroup::updateAllCounters(['member_count' => -1], ['and', ['id' => $groupId], ['>=', 'member_count', 1]]);
            }
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
        $group->refresh();
        return $this->decorate($userId, $group);
    }

    /**
     * 群成员列表。
     *
     * @param array<string,mixed> $in page, pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function members(int $userId, int $groupId, array $in): array
    {
        $this->requireGroup($groupId);
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = GroupMember::find()->where(['group_id' => $groupId]);
        $total = (int) $query->count();
        $rows = $query->orderBy(['role' => SORT_DESC, 'id' => SORT_ASC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        $userViews = $this->userViews(array_map(static fn (GroupMember $m): int => (int) $m->user_id, $rows));
        $list = [];
        foreach ($rows as $m) {
            /** @var GroupMember $m */
            $view = $userViews[(int) $m->user_id] ?? ['id' => (int) $m->user_id];
            $view['role'] = (int) $m->role;
            $list[] = $view;
        }

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 群消息（afterId 增量，正序）。需为群成员。
     *
     * @param array<string,mixed> $in afterId?, limit?
     * @return array{list:array<int,array>}
     */
    public function groupMessages(int $userId, int $groupId, array $in): array
    {
        $this->requireGroup($groupId);
        $this->requireMember($groupId, $userId);
        $afterId = (int) ($in['afterId'] ?? 0);
        $limit = min(100, max(1, (int) ($in['limit'] ?? 50)));

        $query = GroupMessage::find()->where(['group_id' => $groupId]);
        if ($afterId > 0) {
            $query->andWhere(['>', 'id', $afterId]);
        }
        $rows = $query->orderBy(['id' => SORT_ASC])->limit($limit)->all();

        // 批量查发送者
        $senders = $this->userViews(array_map(static fn (GroupMessage $m): int => (int) $m->from_user, $rows));
        $list = [];
        foreach ($rows as $m) {
            /** @var GroupMessage $m */
            $view = $m->toArray();
            $view['sender'] = $senders[(int) $m->from_user] ?? null;
            $list[] = $view;
        }
        return ['list' => $list];
    }

    /**
     * 发群消息（需成员）。
     *
     * @param array<string,mixed> $in content
     */
    public function sendGroupMessage(int $userId, int $groupId, array $in): array
    {
        $this->requireGroup($groupId);
        $this->requireMember($groupId, $userId);
        $content = trim((string) ($in['content'] ?? ''));
        if ($content === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '消息内容不能为空');
        }
        $msg = new GroupMessage();
        $msg->group_id = $groupId;
        $msg->from_user = $userId;
        $msg->content = $content;
        $msg->msg_type = GroupMessage::TYPE_TEXT;
        if (!$msg->save()) {
            throw new BizException(ErrorCode::PARAM_INVALID, '发送失败');
        }
        $view = $msg->toArray();
        $view['sender'] = $this->userViews([$userId])[$userId] ?? null;
        return $view;
    }

    // ---------------- 内部 ----------------

    private function addMember(int $groupId, int $userId, int $role): void
    {
        $m = new GroupMember();
        $m->group_id = $groupId;
        $m->user_id = $userId;
        $m->role = $role;
        $m->joined_at = time();
        $m->save(false);
    }

    private function requireGroup(int $groupId): SocialGroup
    {
        $group = SocialGroup::findOne(['id' => $groupId]);
        if ($group === null || (int) $group->status !== SocialGroup::STATUS_ACTIVE) {
            throw new BizException(ErrorCode::GROUP_NOT_FOUND);
        }
        return $group;
    }

    private function requireMember(int $groupId, int $userId): GroupMember
    {
        $member = GroupMember::findOne(['group_id' => $groupId, 'user_id' => $userId]);
        if ($member === null) {
            throw new BizException(ErrorCode::NOT_GROUP_MEMBER);
        }
        return $member;
    }

    /**
     * 群视图 + 当前用户角色/是否加入。
     */
    private function decorate(int $userId, SocialGroup $group): array
    {
        $member = GroupMember::findOne(['group_id' => $group->getId(), 'user_id' => $userId]);
        return array_merge($group->toArray(), [
            'isJoined' => $member !== null,
            'myRole' => $member !== null ? (int) $member->role : null,
        ]);
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

    private function firstError(SocialGroup $group): ?string
    {
        foreach ($group->getFirstErrors() as $msg) {
            return $msg;
        }
        return null;
    }
}
