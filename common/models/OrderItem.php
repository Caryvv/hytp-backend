<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\TradeActiveRecord;

/**
 * OrderItem model —— 订单明细（下单快照，对齐 docs/dev/02-数据库设计 §3.6）。
 * 仅 created_at（无 updated_at）。
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property int|null $sku_id
 * @property string $title_snapshot
 * @property array|null $spec_snapshot
 * @property string $price
 * @property int $qty
 * @property string $image_snapshot
 * @property int $created_at
 */
class OrderItem extends TradeActiveRecord
{
    public static function tableName(): string
    {
        return '{{%order_item}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'updatedAtAttribute' => false,
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['order_id', 'product_id'], 'required'],
            [['order_id', 'product_id', 'sku_id', 'qty'], 'integer'],
            [['title_snapshot', 'image_snapshot'], 'string', 'max' => 255],
            [['spec_snapshot'], 'safe'],
            [['price'], 'number', 'min' => 0],
            [['qty'], 'default', 'value' => 1],
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
            'productId' => (int) $this->product_id,
            'skuId' => $this->sku_id !== null ? (int) $this->sku_id : null,
            'title' => $this->title_snapshot,
            'spec' => $this->spec_snapshot ?? [],
            'price' => $this->price,
            'qty' => (int) $this->qty,
            'image' => $this->image_snapshot,
        ];
    }
}
