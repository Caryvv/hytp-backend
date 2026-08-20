<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\models\WalletTransaction;

/**
 * 会员开通/续费。用同袍币购买：每月 30 元 = 3000 币。
 * 会员权益（免费试衣次数、超额单价）落在各业务处（见 TryonService::pricing）。
 * 币制 1 币 = 0.01 元（WalletService::COIN_PER_YUAN）。
 */
class MembershipService
{
    // 套餐：月费 30 元、年费 300 元（省 60）。key → [币价, 顺延时长, 展示名]。
    private const PLANS = [
        'month' => [3000, '+1 month', '包月'],
        'year' => [30000, '+1 year', '包年'],
    ];

    /**
     * 会员套餐列表 + 当前状态（App 会员页展示）。
     *
     * @return array{plans:array<int,array{key:string,priceCoin:int,priceYuan:string,durationText:string}>, isPremium:bool, memberExpireAt:int|null}
     */
    public function plan(int $userId): array
    {
        $user = $this->user($userId);
        $plans = [];
        foreach (self::PLANS as $key => [$coin, , $name]) {
            $plans[] = [
                'key' => $key,
                'priceCoin' => $coin,
                'priceYuan' => WalletService::coinToYuan($coin),
                'durationText' => $name,
            ];
        }
        return [
            'plans' => $plans,
            'isPremium' => $user->isPremiumActive(),
            'memberExpireAt' => $user->member_expire_at !== null ? (int) $user->member_expire_at : null,
        ];
    }

    /**
     * 购买/续费会员。扣对应套餐币价，会员到期从 max(now, 原到期) 顺延套餐时长。
     * 已是有效会员则续期不吞未用时长；过期/普通用户从现在起算。
     *
     * ponytail: 读到期→算新到期→save 非原子，双击并发可能扣两次币只顺延一次（亏的是用户，非白嫖）。
     *           App 提交时禁用按钮即可，价值低不上锁；要严格再对 user 加锁（见 TryonService::withUserLock）。
     *
     * @param string $planKey 'month' | 'year'
     * @return array{memberLevel:int, memberExpireAt:int, priceCoin:int}
     * @throws BizException
     */
    public function purchase(int $userId, string $planKey = 'month'): array
    {
        if (!isset(self::PLANS[$planKey])) {
            throw new BizException(ErrorCode::PARAM_INVALID, '会员套餐不存在');
        }
        [$priceCoin, $duration] = self::PLANS[$planKey];
        $user = $this->user($userId);

        // 先扣币：余额不足抛 BALANCE_NOT_ENOUGH（debit 内乐观扣减，原子）
        $wallet = new WalletService();
        $wallet->debit($userId, $priceCoin, WalletTransaction::TYPE_CONSUME, [
            'refType' => 'membership',
            'remark' => "开通/续费会员（{$planKey}）",
        ]);

        try {
            $base = $user->isPremiumActive() ? (int) $user->member_expire_at : time();
            $user->member_level = User::MEMBER_PREMIUM;
            $user->member_expire_at = (int) strtotime($duration, $base);
            if (!$user->save(false, ['member_level', 'member_expire_at'])) {
                throw new BizException(ErrorCode::INTERNAL_ERROR, '会员开通失败');
            }
        } catch (\Throwable $e) {
            // 已扣币但开通失败 → 退款
            $wallet->credit($userId, $priceCoin, WalletTransaction::TYPE_REFUND, [
                'refType' => 'membership_refund',
                'remark' => '会员开通失败退款',
            ]);
            throw $e;
        }

        return [
            'memberLevel' => (int) $user->member_level,
            'memberExpireAt' => (int) $user->member_expire_at,
            'priceCoin' => $priceCoin,
        ];
    }

    private function user(int $userId): User
    {
        $user = User::findOne(['id' => $userId]);
        if ($user === null) {
            throw new BizException(ErrorCode::USER_NOT_FOUND);
        }
        return $user;
    }
}
