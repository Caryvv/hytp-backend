<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\TradeActiveRecord;

/**
 * Cart model —— 购物车（对齐 docs/dev/02-数据库设计 §3.3）。
 *
 * @property int $id
 * @property int $user_id
 * @property int $product_id
 * @property int|null $sku_id
 * @property int $qty
 * @property int $trade_type
 * @property int|null $rent_start
 * @property int|null $rent_end
 * @property int $created_at
 * @property int $updated_at
 */
class Cart extends TradeActiveRecord
{
    public static function tableName(): string
    {
        return '{{%cart}}';
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
            [['user_id', 'product_id'], 'required'],
            [['user_id', 'product_id', 'sku_id', 'qty', 'rent_start', 'rent_end'], 'integer'],
            [['qty'], 'integer', 'min' => 1],
            [['qty'], 'default', 'value' => 1],
            [['trade_type'], 'default', 'value' => Product::TRADE_SELL],
        ];
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }
}
