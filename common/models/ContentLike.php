<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\SocialActiveRecord;

/**
 * ContentLike model —— 内容点赞关系。
 * 唯一键 (content_id,user_id) 防重复点赞。仅 created_at。
 *
 * @property int $id
 * @property int $content_id
 * @property int $user_id
 * @property int $created_at
 */
class ContentLike extends SocialActiveRecord
{
    public static function tableName(): string
    {
        return '{{%content_like}}';
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
            [['content_id', 'user_id'], 'required'],
            [['content_id', 'user_id'], 'integer'],
        ];
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }
}
