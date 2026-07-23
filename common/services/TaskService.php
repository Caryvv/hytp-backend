<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\UserTask;
use common\models\WalletTransaction;

/**
 * 任务系统 —— 通过行为赚同袍币。
 *
 * 任务定义写死常量（v1 仅 4 个固定任务，不建定义表）：
 *   signin       每日签到    主动领取（claim）
 *   publish_feed 发布动态    行为触发（award，FeedService 埋点）
 *   follow_user  关注同袍    行为触发（award，FollowService 埋点）
 *   first_order  首次下单    行为触发（award，PaymentService 埋点，一次性）
 *
 * 幂等三重保障：
 *   ① user_task 唯一键 (user_id, task_key, period_key) DB 级防重
 *   ② WalletService::credit 按 refType/refId 幂等
 *   ③ grant 前先查 user_task 是否存在
 * 发奖统一走 WalletService::credit(TYPE_TASK_REWARD)，余额加在 hytp 库，
 * 流水/记录在 hytp_trade 库（跨库，沿用 WalletService 的 saga）。
 */
class TaskService
{
    public const TASK_SIGNIN = 'signin';
    public const TASK_PUBLISH_FEED = 'publish_feed';
    public const TASK_FOLLOW_USER = 'follow_user';
    public const TASK_FIRST_ORDER = 'first_order';

    /** key => [name, reward(同袍币), daily(每日/一次性)]。 */
    public const TASKS = [
        self::TASK_SIGNIN => ['name' => '每日签到', 'reward' => 10, 'daily' => true],
        self::TASK_PUBLISH_FEED => ['name' => '发布动态', 'reward' => 20, 'daily' => true],
        self::TASK_FOLLOW_USER => ['name' => '关注同袍', 'reward' => 5, 'daily' => true],
        self::TASK_FIRST_ORDER => ['name' => '首次下单', 'reward' => 100, 'daily' => false],
    ];

    /**
     * 任务列表（含各任务当前周期是否已完成 + 奖励），供 App 任务页。
     *
     * @return array{list:array<int,array{key:string,name:string,reward:int,daily:bool,claimable:bool,done:bool}>}
     */
    public function list(int $userId): array
    {
        $list = [];
        foreach (self::TASKS as $key => $def) {
            $done = UserTask::find()
                ->where(['user_id' => $userId, 'task_key' => $key, 'period_key' => $this->periodKey($key)])
                ->exists();
            $list[] = [
                'key' => $key,
                'name' => $def['name'],
                'reward' => $def['reward'],
                'daily' => $def['daily'],
                'claimable' => $key === self::TASK_SIGNIN, // 仅签到主动领取
                'done' => $done,
            ];
        }
        return ['list' => $list];
    }

    /**
     * 主动领取（仅签到）。已领取抛 TASK_ALREADY_DONE，非法 key 抛 TASK_NOT_FOUND。
     *
     * @return array{taskKey:string, reward:int, balanceCoin:int}
     */
    public function claim(int $userId, string $taskKey): array
    {
        if (!isset(self::TASKS[$taskKey])) {
            throw new BizException(ErrorCode::TASK_NOT_FOUND);
        }
        // v1 仅签到可主动领取，其余为行为触发
        if ($taskKey !== self::TASK_SIGNIN) {
            throw new BizException(ErrorCode::TASK_NOT_FOUND, '该任务不支持主动领取');
        }

        $txn = $this->grant($userId, $taskKey);
        return [
            'taskKey' => $taskKey,
            'reward' => (int) self::TASKS[$taskKey]['reward'],
            'balanceCoin' => (int) $txn->balance_after,
        ];
    }

    /**
     * 行为触发发奖（发动态/关注/首单）。吞异常——已完成或任何失败都静默返回，
     * 绝不影响业务主流程（发布/关注/支付本身必须成功）。
     */
    public function award(int $userId, string $taskKey): void
    {
        try {
            $this->grant($userId, $taskKey);
        } catch (\Throwable $e) {
            // 已完成/并发/发奖失败均静默；不回滚业务主流程
        }
    }

    /**
     * 发奖公共逻辑：写 user_task（唯一键防重）→ credit 发同袍币 → 回填 txn_no。
     * 已完成抛 TASK_ALREADY_DONE（claim 透传给前端，award try/catch 吞掉）。
     */
    private function grant(int $userId, string $taskKey): WalletTransaction
    {
        $def = self::TASKS[$taskKey];
        $period = $this->periodKey($taskKey);

        $exists = UserTask::find()
            ->where(['user_id' => $userId, 'task_key' => $taskKey, 'period_key' => $period])
            ->exists();
        if ($exists) {
            throw new BizException(ErrorCode::TASK_ALREADY_DONE);
        }

        // 先占坑（唯一键挡并发重复领取）
        $record = new UserTask();
        $record->user_id = $userId;
        $record->task_key = $taskKey;
        $record->period_key = $period;
        $record->reward_coin = (int) $def['reward'];
        if (!$record->save()) {
            // 并发下唯一键冲突会走到这里
            throw new BizException(ErrorCode::TASK_ALREADY_DONE);
        }

        // 发奖（credit 内部对 refType/refId 亦幂等）
        $txn = (new WalletService())->credit(
            $userId,
            (int) $def['reward'],
            WalletTransaction::TYPE_TASK_REWARD,
            [
                'refType' => 'task',
                'refId' => $period === '' ? $taskKey : $taskKey . ':' . $period,
                'remark' => '任务奖励·' . $def['name'],
            ],
        );

        $record->txn_no = $txn->txn_no;
        $record->save(false);

        return $txn;
    }

    /** 周期键：每日任务取当天 Ymd，一次性任务取空串。 */
    private function periodKey(string $taskKey): string
    {
        return (self::TASKS[$taskKey]['daily'] ?? false) ? date('Ymd') : '';
    }
}
