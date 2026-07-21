<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\TradeActiveRecord;

/**
 * Payment model —— 支付流水（对齐 docs/dev/02-数据库设计 §3.8）。
 * 本轮 Mock 通道：pay 建单 → mockConfirm 置成功并改订单。真实通道换成 notify 验签。
 *
 * @property int $id
 * @property string $pay_no
 * @property int $order_id
 * @property int $channel
 * @property string $amount
 * @property int $status
 * @property string $trade_no
 * @property int|null $notify_at
 * @property int $created_at
 * @property int $updated_at
 */
class Payment extends TradeActiveRecord
{
    // 支付状态
    public const STATUS_PENDING = 0; // 待支付
    public const STATUS_PAID = 1;    // 已支付
    public const STATUS_FAILED = 2;  // 失败
    public const STATUS_REFUNDED = 3; // 已退款

    // 支付渠道
    public const CHANNEL_COIN = 1;   // 代币
    public const CHANNEL_WECHAT = 2; // 微信（预留）
    public const CHANNEL_ALIPAY = 3; // 支付宝（预留）

    public static function tableName(): string
    {
        return '{{%payment}}';
    }

    public function behaviors(): array
    {
        return [
            TimestampBehavior::class,
        ];
    }

    public function rules(): array
    {
        return [
            [['pay_no', 'order_id'], 'required'],
            [['order_id', 'notify_at'], 'integer'],
            [['pay_no'], 'string', 'max' => 32],
            [['trade_no'], 'string', 'max' => 64],
            [['amount'], 'number', 'min' => 0],
            [['channel'], 'in', 'range' => [self::CHANNEL_COIN, self::CHANNEL_WECHAT, self::CHANNEL_ALIPAY]],
            [['channel'], 'default', 'value' => self::CHANNEL_COIN],
            [['status'], 'in', 'range' => [
                self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_FAILED, self::STATUS_REFUNDED,
            ]],
            [['status'], 'default', 'value' => self::STATUS_PENDING],
        ];
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => $this->getId(),
            'payNo' => $this->pay_no,
            'orderId' => (int) $this->order_id,
            'channel' => (int) $this->channel,
            'amount' => $this->amount,
            'status' => (int) $this->status,
        ];
    }
}
