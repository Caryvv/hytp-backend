<?php

declare(strict_types=1);

namespace common\models;

use common\base\TradeActiveRecord;
use yii\behaviors\TimestampBehavior;

/**
 * 任务领奖记录（hytp_trade 库）。
 *
 * @property int $id
 * @property int $user_id
 * @property string $task_key
 * @property string $period_key
 * @property int $reward_coin
 * @property string $txn_no
 * @property int $created_at
 */
class UserTask extends TradeActiveRecord
{
    public static function tableName(): string
    {
        return '{{%user_task}}';
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
            [['user_id', 'task_key'], 'required'],
            [['user_id', 'reward_coin'], 'integer'],
            [['task_key', 'period_key', 'txn_no'], 'string', 'max' => 32],
            [['period_key', 'txn_no'], 'default', 'value' => ''],
        ];
    }
}
