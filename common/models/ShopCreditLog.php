<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * ShopCreditLog model —— 商家信用分变更流水（对齐 docs/dev/02-数据库设计 §2.3）。
 * 仅 created_at（无 updated_at）。差评/违规扣分、好评加分、保障金赔付扣分。
 *
 * @property int $id
 * @property int $shop_id
 * @property int $change ±分
 * @property string $reason
 * @property string $ref_type 关联类型（订单/评价/处罚/保障金）
 * @property int|null $ref_id
 * @property int $created_at
 */
class ShopCreditLog extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%shop_credit_log}}';
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
            [['shop_id'], 'required'],
            [['shop_id', 'change', 'ref_id'], 'integer'],
            [['change'], 'default', 'value' => 0],
            [['reason'], 'string', 'max' => 255],
            [['ref_type'], 'string', 'max' => 30],
            [['reason', 'ref_type'], 'default', 'value' => ''],
        ];
    }

    /**
     * 记一条信用分变更并同步更新 shop.credit_score（事务外调用方保证）。
     */
    public static function record(int $shopId, int $change, string $reason, string $refType = '', ?int $refId = null): void
    {
        $log = new self();
        $log->shop_id = $shopId;
        $log->change = $change;
        $log->reason = $reason;
        $log->ref_type = $refType;
        $log->ref_id = $refId;
        $log->save(false);
        Shop::updateAllCounters(['credit_score' => $change], ['id' => $shopId]);
    }
}
