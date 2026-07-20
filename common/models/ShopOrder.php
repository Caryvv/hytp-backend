<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * ShopOrder model —— 订单主表（表名 shop_order 避开保留字 order）。
 * 对齐 docs/dev/02-数据库设计 §3.5、状态机见 docs/dev/08-用户端-汉服交易区 §4。
 *
 * 状态机：
 *   待付款(0) ─支付回调→ 待发货(1) ─发货→ 待收货(2) ─确认收货→ 已完成(4)
 *      └─取消→ 已取消(5)                              售后→ 退款(6)
 *
 * @property int $id
 * @property string $order_no
 * @property int $user_id
 * @property int $shop_id
 * @property int $type
 * @property string $total_amount
 * @property string $pay_amount
 * @property string $commission
 * @property int $status
 * @property int|null $rent_start
 * @property int|null $rent_end
 * @property int|null $address_id
 * @property array|null $address_snapshot
 * @property string $remark
 * @property string $express_company
 * @property string $express_no
 * @property int|null $paid_at
 * @property int|null $shipped_at
 * @property int|null $finished_at
 * @property int $created_at
 * @property int $updated_at
 */
class ShopOrder extends ActiveRecord
{
    // 订单状态
    public const STATUS_UNPAID = 0;    // 待付款
    public const STATUS_UNSHIP = 1;    // 待发货（已支付）
    public const STATUS_SHIPPED = 2;   // 待收货（已发货）
    public const STATUS_FINISHED = 4;  // 已完成（确认收货）
    public const STATUS_CANCELLED = 5; // 已取消
    public const STATUS_REFUND = 6;    // 退款/售后

    // 订单类型
    public const TYPE_BUY = 1;     // 购买
    public const TYPE_RENT = 2;    // 租赁
    public const TYPE_CUSTOM = 3;  // 定制
    public const TYPE_TRAVEL = 4;  // 文旅
    public const TYPE_SERVICE = 5; // 服务

    public static function tableName(): string
    {
        return '{{%shop_order}}';
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
            [['order_no', 'user_id', 'shop_id'], 'required'],
            [['user_id', 'shop_id', 'address_id', 'rent_start', 'rent_end', 'paid_at', 'shipped_at', 'finished_at'], 'integer'],
            [['order_no'], 'string', 'max' => 32],
            [['remark'], 'string', 'max' => 255],
            [['express_company', 'express_no'], 'string', 'max' => 50],
            [['express_company', 'express_no'], 'default', 'value' => ''],
            [['total_amount', 'pay_amount', 'commission'], 'number', 'min' => 0],
            [['address_snapshot'], 'safe'],
            [['type'], 'default', 'value' => self::TYPE_BUY],
            [['status'], 'default', 'value' => self::STATUS_UNPAID],
            [['commission'], 'default', 'value' => 0],
        ];
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }

    /** 是否可支付。 */
    public function isPayable(): bool
    {
        return (int) $this->status === self::STATUS_UNPAID;
    }

    /** 是否可取消（仅未发货前）。 */
    public function isCancellable(): bool
    {
        return in_array((int) $this->status, [self::STATUS_UNPAID, self::STATUS_UNSHIP], true);
    }

    /** 是否可确认收货。 */
    public function isConfirmable(): bool
    {
        return (int) $this->status === self::STATUS_SHIPPED;
    }

    /** 是否可发货（商家端，仅待发货状态）。 */
    public function isShippable(): bool
    {
        return (int) $this->status === self::STATUS_UNSHIP;
    }

    // ---------------- 输出 ----------------

    public function toListArray(): array
    {
        return [
            'id' => $this->getId(),
            'orderNo' => $this->order_no,
            'shopId' => (int) $this->shop_id,
            'type' => (int) $this->type,
            'totalAmount' => $this->total_amount,
            'payAmount' => $this->pay_amount,
            'status' => (int) $this->status,
            'remark' => $this->remark,
            'paidAt' => $this->paid_at !== null ? (int) $this->paid_at : null,
            'shippedAt' => $this->shipped_at !== null ? (int) $this->shipped_at : null,
            'finishedAt' => $this->finished_at !== null ? (int) $this->finished_at : null,
            'createdAt' => (int) $this->created_at,
        ];
    }

    public function toDetailArray(): array
    {
        return array_merge($this->toListArray(), [
            'userId' => (int) $this->user_id,
            'commission' => $this->commission,
            'addressId' => $this->address_id !== null ? (int) $this->address_id : null,
            'address' => $this->address_snapshot,
            'expressCompany' => $this->express_company,
            'expressNo' => $this->express_no,
            'updatedAt' => (int) $this->updated_at,
        ]);
    }
}
