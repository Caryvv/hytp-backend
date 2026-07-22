<?php

declare(strict_types=1);

namespace common\models;

use common\base\TradeActiveRecord;
use yii\behaviors\TimestampBehavior;

/**
 * 同袍币钱包流水（hytp_trade 库）。
 * amount/balance_after 以同袍币整数记账（1 同袍币 = 0.01 元），+入账 -出账。
 *
 * @property int $id
 * @property string $txn_no
 * @property int $user_id
 * @property int $type
 * @property int $amount
 * @property int $balance_after
 * @property int $channel
 * @property string $ref_type
 * @property string $ref_id
 * @property string $remark
 * @property int $status
 * @property int $created_at
 * @property int $updated_at
 */
class WalletTransaction extends TradeActiveRecord
{
    // 流水类型
    public const TYPE_RECHARGE = 1;    // 充值
    public const TYPE_TASK_REWARD = 2; // 任务奖励（二期）
    public const TYPE_CONSUME = 3;     // 消费
    public const TYPE_REFUND = 4;      // 退款
    public const TYPE_GIFT = 5;        // 系统赠送

    // 到账状态
    public const STATUS_PENDING = 0; // 待到账（真实通道下单未回调）
    public const STATUS_DONE = 1;    // 已到账
    public const STATUS_FAILED = 2;  // 失败

    public static function tableName(): string
    {
        return '{{%wallet_transaction}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['txn_no', 'user_id', 'type', 'amount', 'balance_after'], 'required'],
            [['user_id', 'type', 'amount', 'balance_after', 'channel', 'status'], 'integer'],
            [['txn_no'], 'string', 'max' => 32],
            [['ref_type'], 'string', 'max' => 32],
            [['ref_id'], 'string', 'max' => 64],
            [['remark'], 'string', 'max' => 255],
            [['channel'], 'default', 'value' => 0],
            [['status'], 'default', 'value' => self::STATUS_DONE],
        ];
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => (int) $this->id,
            'txnNo' => $this->txn_no,
            'type' => (int) $this->type,
            'amount' => (int) $this->amount,
            'balanceAfter' => (int) $this->balance_after,
            'channel' => (int) $this->channel,
            'refType' => $this->ref_type,
            'refId' => $this->ref_id,
            'remark' => $this->remark,
            'status' => (int) $this->status,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
