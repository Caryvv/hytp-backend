<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\SocialActiveRecord;

/**
 * UserAvatar model —— 用户可复用的试衣形象照（一人可存多张）。仅 created_at。
 *
 * @property int $id
 * @property int $user_id
 * @property string $image_url
 * @property int $created_at
 */
class UserAvatar extends SocialActiveRecord
{
    public static function tableName(): string
    {
        return '{{%user_avatar}}';
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
            [['user_id', 'image_url'], 'required'],
            [['user_id'], 'integer'],
            [['image_url'], 'string', 'max' => 500],
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
            'imageUrl' => $this->image_url,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
