<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\TradeActiveRecord;

/**
 * ProductSku model —— 商品规格（尺码/颜色，对齐 docs/dev/02-数据库设计 §3.4）。
 *
 * @property int $id
 * @property int $product_id
 * @property array|null $spec_json 规格：尺码/颜色
 * @property string $price
 * @property int $stock
 * @property string $sku_code
 * @property int $created_at
 * @property int $updated_at
 */
class ProductSku extends TradeActiveRecord
{
    public static function tableName(): string
    {
        return '{{%product_sku}}';
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
            [['product_id'], 'required'],
            [['product_id', 'stock'], 'integer'],
            [['spec_json'], 'safe'],
            [['price'], 'number', 'min' => 0],
            [['price'], 'default', 'value' => 0],
            [['stock'], 'default', 'value' => 0],
            [['sku_code'], 'string', 'max' => 50],
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
            'spec' => (object) ($this->spec_json ?? []), // 空时输出 {} 而非 []，避免前端 Map 解析崩
            'price' => $this->price,
            'stock' => (int) $this->stock,
            'skuCode' => $this->sku_code,
        ];
    }
}
