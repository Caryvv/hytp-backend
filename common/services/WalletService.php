<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\models\WalletTransaction;
use yii\db\Transaction;

/**
 * 同袍币钱包服务 —— 统一入账/出账与流水记账。
 *
 * 币制：1 同袍币 = 0.01 元 = 1 分。user.balance 存 DECIMAL(10,2) 元（hytp 库），
 * 流水以同袍币整数记账（hytp_trade 库）。两库不同连接，故用 saga（非单事务）：
 *   - credit：先写 pending 流水（幂等锚点）→ 加余额 → 回填 balance_after 置 DONE。
 *     崩溃留 pending，可对账补偿，绝不重复加钱（充值按 ref 幂等）。
 *   - debit：乐观扣减（>= 校验）成功后记流水；流水写失败则回滚余额。
 *
 * 二期任务系统直接调 credit(TYPE_TASK_REWARD) 即可，无需改动。
 */
class WalletService
{
    public const COIN_PER_YUAN = 100; // 100 同袍币 = 1 元

    /** 同袍币 → 元字符串（DECIMAL(10,2)）。coins 为整数，结果精确到 2 位。 */
    public static function coinToYuan(int $coin): string
    {
        return bcdiv((string) $coin, (string) self::COIN_PER_YUAN, 2);
    }

    /** 元字符串 → 同袍币整数（余额恒为 0.01 的整数倍）。 */
    public static function yuanToCoin(string $yuan): int
    {
        return (int) bcmul($yuan, (string) self::COIN_PER_YUAN, 0);
    }

    /**
     * 入账（充值 / 任务奖励 / 系统赠送 / 退款）。
     *
     * @param array{channel?:int, refType?:string, refId?:string, remark?:string} $opts
     */
    public function credit(int $userId, int $coin, int $type, array $opts = []): WalletTransaction
    {
        if ($coin <= 0) {
            throw new BizException(ErrorCode::PARAM_INVALID, '入账金额必须为正');
        }
        $refType = (string) ($opts['refType'] ?? '');
        $refId = (string) ($opts['refId'] ?? '');

        // 幂等：同 ref 已到账则直接返回（充值防重复到账）
        if ($refType !== '' && $refId !== '') {
            $done = WalletTransaction::findOne([
                'ref_type' => $refType, 'ref_id' => $refId, 'status' => WalletTransaction::STATUS_DONE,
            ]);
            if ($done !== null) {
                return $done;
            }
        }

        // 1) 先落 pending 流水作幂等锚点
        $txn = new WalletTransaction();
        $txn->txn_no = $this->genTxnNo();
        $txn->user_id = $userId;
        $txn->type = $type;
        $txn->amount = $coin;
        $txn->balance_after = 0;
        $txn->channel = (int) ($opts['channel'] ?? 0);
        $txn->ref_type = $refType;
        $txn->ref_id = $refId;
        $txn->remark = (string) ($opts['remark'] ?? '');
        $txn->status = WalletTransaction::STATUS_PENDING;
        if (!$txn->save()) {
            throw new BizException(ErrorCode::INTERNAL_ERROR, '流水创建失败');
        }

        // 2) 加余额（入账不会失败于约束）
        User::updateAllCounters(['balance' => self::coinToYuan($coin)], ['id' => $userId]);

        // 3) 回填 balance_after 并置已到账
        // ponytail: 并发下 balance_after 快照可能非严格顺序，审计足够；要精确顺序再加行锁
        $txn->balance_after = $this->currentCoin($userId);
        $txn->status = WalletTransaction::STATUS_DONE;
        $txn->save(false);

        return $txn;
    }

    /**
     * 出账（消费）。乐观扣减，余额不足抛 BALANCE_NOT_ENOUGH。
     *
     * @param array{channel?:int, refType?:string, refId?:string, remark?:string} $opts
     */
    public function debit(int $userId, int $coin, int $type, array $opts = []): WalletTransaction
    {
        if ($coin <= 0) {
            throw new BizException(ErrorCode::PARAM_INVALID, '出账金额必须为正');
        }
        $yuan = self::coinToYuan($coin);

        $affected = User::updateAllCounters(
            ['balance' => '-' . $yuan],
            ['and', ['id' => $userId], ['>=', 'balance', $yuan]],
        );
        if ($affected < 1) {
            throw new BizException(ErrorCode::BALANCE_NOT_ENOUGH);
        }

        $txn = $this->buildLog(
            $userId,
            $type,
            -$coin,
            $this->currentCoin($userId),
            $opts,
        );
        if (!$txn->save()) {
            // 流水写失败 → 回滚余额
            User::updateAllCounters(['balance' => $yuan], ['id' => $userId]);
            throw new BizException(ErrorCode::INTERNAL_ERROR, '流水创建失败');
        }
        return $txn;
    }

    /**
     * 纯记流水（不改余额）——供调用方已自行完成余额变更的场景（如 PaymentService 代币支付/退款）。
     * 可选传入调用方的 hytp_trade 事务，使流水与业务单据同事务落库。
     *
     * @param array{channel?:int, refType?:string, refId?:string, remark?:string} $opts
     */
    public function log(int $userId, int $type, int $signedCoin, int $balanceAfterCoin, array $opts = [], ?Transaction $tx = null): WalletTransaction
    {
        $txn = $this->buildLog($userId, $type, $signedCoin, $balanceAfterCoin, $opts);
        if (!$txn->save()) {
            throw new BizException(ErrorCode::INTERNAL_ERROR, '流水创建失败');
        }
        return $txn;
    }

    /**
     * 充值（Mock：直接到账）。真实通道时改为建 pending 流水返回支付参数，由回调 confirm 到账。
     *
     * @return array{rechargeNo:string, coin:int, amountYuan:string, balanceCoin:int, mock:bool}
     */
    public function recharge(int $userId, int $coin, int $channel = 0): array
    {
        if ($coin <= 0) {
            throw new BizException(ErrorCode::RECHARGE_AMOUNT_INVALID);
        }
        $rechargeNo = $this->genTxnNo();
        $txn = $this->credit($userId, $coin, WalletTransaction::TYPE_RECHARGE, [
            'channel' => $channel,
            'refType' => 'recharge',
            'refId' => $rechargeNo,
            'remark' => 'Mock 充值',
        ]);

        return [
            'rechargeNo' => $rechargeNo,
            'coin' => $coin,
            'amountYuan' => self::coinToYuan($coin),
            'balanceCoin' => (int) $txn->balance_after,
            'mock' => true,
        ];
    }

    /**
     * 钱包流水列表（倒序分页）。App 钱包明细页展示充值/消费/退款/提现等记录。
     *
     * @param array<string,mixed> $in page, pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function transactions(int $userId, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = WalletTransaction::find()->where(['user_id' => $userId]);
        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => array_map(static fn (WalletTransaction $t): array => $t->toArray(), $rows),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 充值确认（Mock 幂等查询 / 真实通道回调到账入口）。
     *
     * @return array{rechargeNo:string, coin:int, balanceCoin:int, done:bool}
     */
    public function rechargeConfirm(int $userId, string $rechargeNo): array
    {
        $txn = WalletTransaction::findOne(['ref_type' => 'recharge', 'ref_id' => $rechargeNo]);
        if ($txn === null) {
            throw new BizException(ErrorCode::RECHARGE_ORDER_NOT_FOUND);
        }
        // Mock 已在 recharge() 到账，这里仅幂等返回；真实通道在此加余额置 DONE
        return [
            'rechargeNo' => $rechargeNo,
            'coin' => (int) $txn->amount,
            'balanceCoin' => $txn->status === WalletTransaction::STATUS_DONE
                ? (int) $txn->balance_after
                : $this->currentCoin($userId),
            'done' => (int) $txn->status === WalletTransaction::STATUS_DONE,
        ];
    }

    /**
     * 提现（Mock：即时扣减到账）。复用 debit 乐观扣减，余额不足抛 BALANCE_NOT_ENOUGH。
     * ponytail: Mock 即时扣减，与充值即时到账对称。真实通道需建提现单表 + 管理端审核 + 打款回调，
     *           届时改为建 pending 提现单、审核通过后 debit 并回调。
     *
     * @return array{withdrawNo:string, coin:int, amountYuan:string, balanceCoin:int, mock:bool}
     */
    public function withdraw(int $userId, int $coin, int $channel = 0): array
    {
        if ($coin <= 0) {
            throw new BizException(ErrorCode::WITHDRAW_AMOUNT_INVALID);
        }
        $withdrawNo = $this->genTxnNo();
        $txn = $this->debit($userId, $coin, WalletTransaction::TYPE_WITHDRAW, [
            'channel' => $channel,
            'refType' => 'withdraw',
            'refId' => $withdrawNo,
            'remark' => 'Mock 提现',
        ]);

        return [
            'withdrawNo' => $withdrawNo,
            'coin' => $coin,
            'amountYuan' => self::coinToYuan($coin),
            'balanceCoin' => (int) $txn->balance_after,
            'mock' => true,
        ];
    }

    /** 当前余额（同袍币）。 */
    public function currentCoin(int $userId): int
    {
        $user = User::findOne(['id' => $userId]);
        return $user !== null ? self::yuanToCoin((string) $user->balance) : 0;
    }

    /** @param array{channel?:int, refType?:string, refId?:string, remark?:string} $opts */
    private function buildLog(int $userId, int $type, int $signedCoin, int $balanceAfterCoin, array $opts): WalletTransaction
    {
        $txn = new WalletTransaction();
        $txn->txn_no = $this->genTxnNo();
        $txn->user_id = $userId;
        $txn->type = $type;
        $txn->amount = $signedCoin;
        $txn->balance_after = $balanceAfterCoin;
        $txn->channel = (int) ($opts['channel'] ?? 0);
        $txn->ref_type = (string) ($opts['refType'] ?? '');
        $txn->ref_id = (string) ($opts['refId'] ?? '');
        $txn->remark = (string) ($opts['remark'] ?? '');
        $txn->status = WalletTransaction::STATUS_DONE;
        return $txn;
    }

    private function genTxnNo(): string
    {
        return 'W' . date('YmdHis') . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
