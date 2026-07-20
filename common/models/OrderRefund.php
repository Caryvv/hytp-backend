<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\TradeActiveRecord;

/**
 * OrderRefund model —— 售后/退款（对齐 docs/dev/02-数据库设计 §3.7）。
 *
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property string $reason
 * @property string $amount
 * @property int $status
 * @property array|null $evidence
 * @property string $handle_remark
 * @property int $created_at
 * @property int $updated_at
 */
class OrderRefund extends TradeActiveRecord
{
    // 售后状态
    public const STATUS_APPLYING = 0; // 申请中
    public const STATUS_AGREED = 1;   // 同意
    public const STATUS_REJECTED = 2; // 拒绝
    public const STATUS_DONE = 3;     // 已完成

    public static function tableName(): string
    {
        return '{{%order_refund}}';
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
            [['order_id', 'user_id'], 'required'],
            [['order_id', 'user_id'], 'integer'],
            [['reason', 'handle_remark'], 'string', 'max' => 255],
            [['amount'], 'number', 'min' => 0],
            [['evidence'], 'safe'],
            [['status'], 'in', 'range' => [
                self::STATUS_APPLYING, self::STATUS_AGREED, self::STATUS_REJECTED, self::STATUS_DONE,
            ]],
            [['status'], 'default', 'value' => self::STATUS_APPLYING],
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
            'orderId' => (int) $this->order_id,
            'reason' => $this->reason,
            'amount' => $this->amount,
            'status' => (int) $this->status,
            'evidence' => $this->evidence ?? [],
            'handleRemark' => $this->handle_remark,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
