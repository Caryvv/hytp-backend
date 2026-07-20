<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\TradeActiveRecord;

/**
 * DepositClaim model —— 品质保障金赔付记录（对齐 docs/dev/02-数据库设计 §3.11）。
 *
 * 用户对订单发起"山品/质量不符"索赔 → 管理端判定 → 成立则平台先行赔付+扣商家保证金。
 *
 * @property int $id
 * @property int $order_id
 * @property int $shop_id
 * @property int $user_id
 * @property string $amount
 * @property string $reason
 * @property array|null $evidence
 * @property int $status
 * @property string $handle_remark
 * @property int|null $admin_id
 * @property int $created_at
 * @property int $updated_at
 */
class DepositClaim extends TradeActiveRecord
{
    public const STATUS_PENDING = 0;  // 待判定
    public const STATUS_APPROVED = 1; // 成立赔付
    public const STATUS_REJECTED = 2; // 驳回

    public static function tableName(): string
    {
        return '{{%deposit_claim}}';
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
            [['order_id', 'shop_id', 'user_id'], 'required'],
            [['order_id', 'shop_id', 'user_id', 'admin_id'], 'integer'],
            [['amount'], 'number', 'min' => 0],
            [['amount'], 'default', 'value' => 0],
            [['reason', 'handle_remark'], 'string', 'max' => 255],
            [['evidence'], 'safe'],
            [['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED]],
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
            'orderId' => (int) $this->order_id,
            'shopId' => (int) $this->shop_id,
            'userId' => (int) $this->user_id,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'evidence' => $this->evidence ?? [],
            'status' => (int) $this->status,
            'handleRemark' => $this->handle_remark,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
