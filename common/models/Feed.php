<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * Feed model —— 同袍动态（对齐 docs/dev/02-数据库设计 §4.1）。
 * 作者信息与 isLiked/isFavorited 由 Service 拼接，不放 model。
 *
 * @property int $id
 * @property int $user_id
 * @property string $content
 * @property int $media_type 1图文 2短视频 3直播回放
 * @property array|null $media 图/视频 URL 列表
 * @property array|null $tags
 * @property array|null $product_ids
 * @property string $city
 * @property int $like_count
 * @property int $comment_count
 * @property int $favorite_count
 * @property int $share_count
 * @property int $status 0待审 1正常 2下架
 * @property int $created_at
 * @property int $updated_at
 */
class Feed extends ActiveRecord
{
    public const STATUS_AUDITING = 0; // 待审
    public const STATUS_NORMAL = 1;   // 正常
    public const STATUS_OFF = 2;      // 下架

    public const MEDIA_IMAGE = 1;
    public const MEDIA_VIDEO = 2;
    public const MEDIA_LIVE = 3;

    public static function tableName(): string
    {
        return '{{%feed}}';
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
            [['user_id', 'content'], 'required'],
            [['user_id'], 'integer'],
            [['content'], 'string'],
            [['media', 'tags', 'product_ids'], 'safe'],
            [['city'], 'string', 'max' => 50],
            [['city'], 'default', 'value' => ''],
            [['media_type'], 'in', 'range' => [self::MEDIA_IMAGE, self::MEDIA_VIDEO, self::MEDIA_LIVE]],
            [['media_type'], 'default', 'value' => self::MEDIA_IMAGE],
            [['status'], 'in', 'range' => [self::STATUS_AUDITING, self::STATUS_NORMAL, self::STATUS_OFF]],
            [['status'], 'default', 'value' => self::STATUS_NORMAL],
        ];
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }

    public function toListArray(): array
    {
        return [
            'id' => $this->getId(),
            'userId' => (int) $this->user_id,
            'content' => $this->content,
            'mediaType' => (int) $this->media_type,
            'media' => $this->media ?? [],
            'tags' => $this->tags ?? [],
            'productIds' => $this->product_ids ?? [],
            'city' => $this->city,
            'likeCount' => (int) $this->like_count,
            'commentCount' => (int) $this->comment_count,
            'favoriteCount' => (int) $this->favorite_count,
            'shareCount' => (int) $this->share_count,
            'status' => (int) $this->status,
            'createdAt' => (int) $this->created_at,
        ];
    }

    public function toDetailArray(): array
    {
        return array_merge($this->toListArray(), [
            'updatedAt' => (int) $this->updated_at,
        ]);
    }
}
