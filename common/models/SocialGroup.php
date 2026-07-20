<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\SocialActiveRecord;

/**
 * SocialGroup model —— 社群（对齐 docs/dev/02-数据库设计 §4.5）。
 *
 * @property int $id
 * @property string $name
 * @property int $type 1地域 2形制 3兴趣 4男性同袍
 * @property int $owner_id
 * @property string $avatar
 * @property string $intro
 * @property string $city
 * @property int $member_count
 * @property int $status 0解散 1正常
 * @property int $created_at
 * @property int $updated_at
 */
class SocialGroup extends SocialActiveRecord
{
    public const STATUS_DISBANDED = 0;
    public const STATUS_ACTIVE = 1;

    public const TYPE_REGION = 1;
    public const TYPE_FORME = 2;
    public const TYPE_INTEREST = 3;
    public const TYPE_MALE = 4;

    public static function tableName(): string
    {
        return '{{%social_group}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['name', 'owner_id'], 'required'],
            [['owner_id', 'member_count'], 'integer'],
            [['name', 'city'], 'string', 'max' => 50],
            [['avatar', 'intro'], 'string', 'max' => 255],
            [['avatar', 'intro', 'city'], 'default', 'value' => ''],
            [['type'], 'in', 'range' => [self::TYPE_REGION, self::TYPE_FORME, self::TYPE_INTEREST, self::TYPE_MALE]],
            [['type'], 'default', 'value' => self::TYPE_REGION],
            [['status'], 'in', 'range' => [self::STATUS_DISBANDED, self::STATUS_ACTIVE]],
            [['status'], 'default', 'value' => self::STATUS_ACTIVE],
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
            'name' => $this->name,
            'type' => (int) $this->type,
            'ownerId' => (int) $this->owner_id,
            'avatar' => $this->avatar,
            'intro' => $this->intro,
            'city' => $this->city,
            'memberCount' => (int) $this->member_count,
            'status' => (int) $this->status,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
