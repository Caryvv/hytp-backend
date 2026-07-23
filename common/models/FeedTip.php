<?php

declare(strict_types=1);

namespace common\models;

use common\base\TradeActiveRecord;
use yii\behaviors\TimestampBehavior;

/**
 * 动态打赏记录（hytp_trade 库）。
 *
 * @property int $id
 * @property string $tip_no
 * @property int $feed_id
 * @property int $from_user_id
 * @property int $to_user_id
 * @property int $coin
 * @property string $txn_no
 * @property int $created_at
 */
class FeedTip extends TradeActiveRecord
{
    public static function tableName(): string
    {
        return '{{%feed_tip}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'updatedAtAttribute' => false, // 只记 created_at
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['tip_no', 'feed_id', 'from_user_id', 'to_user_id', 'coin'], 'required'],
            [['feed_id', 'from_user_id', 'to_user_id', 'coin'], 'integer'],
            [['tip_no'], 'string', 'max' => 64],
            [['txn_no'], 'string', 'max' => 32],
            [['txn_no'], 'default', 'value' => ''],
        ];
    }
}
