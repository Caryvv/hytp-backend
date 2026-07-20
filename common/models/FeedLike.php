<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * FeedLike model —— 动态点赞关系（对齐 docs/dev/02-数据库设计 §4.2）。
 * 唯一键 (feed_id,user_id) 防重复点赞。仅 created_at。
 *
 * @property int $id
 * @property int $feed_id
 * @property int $user_id
 * @property int $created_at
 */
class FeedLike extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%feed_like}}';
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
            [['feed_id', 'user_id'], 'required'],
            [['feed_id', 'user_id'], 'integer'],
        ];
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }
}
