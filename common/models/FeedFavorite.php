<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\SocialActiveRecord;

/**
 * FeedFavorite model —— 动态收藏关系（对齐 docs/dev/02-数据库设计 §4.2）。
 * 唯一键 (feed_id,user_id) 防重复收藏。仅 created_at。
 *
 * @property int $id
 * @property int $feed_id
 * @property int $user_id
 * @property int $created_at
 */
class FeedFavorite extends SocialActiveRecord
{
    public static function tableName(): string
    {
        return '{{%feed_favorite}}';
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
