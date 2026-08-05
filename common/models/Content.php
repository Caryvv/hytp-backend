<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\SocialActiveRecord;

/**
 * Content model —— 文旅 + 文化传承 内容（合并一张表，type 区分）。
 * 运营在管理端录入;用户端只读浏览 + 点赞/收藏/报名。
 * isLiked/isFavorited/isSignedUp 由 Service 拼接，不放 model。
 *
 * @property int $id
 * @property int $type 1文旅 2文化传承
 * @property string $title
 * @property string $cover
 * @property array|null $images 图集 URL 列表
 * @property string|null $detail 图文正文
 * @property string $city
 * @property string $category
 * @property int $like_count
 * @property int $favorite_count
 * @property int $signup_count
 * @property int $status 0下架 1上线
 * @property int $created_at
 * @property int $updated_at
 */
class Content extends SocialActiveRecord
{
    public const TYPE_TRAVEL = 1;   // 文旅
    public const TYPE_CULTURE = 2;  // 文化传承

    public const STATUS_OFF = 0; // 下架
    public const STATUS_ON = 1;  // 上线

    public static function tableName(): string
    {
        return '{{%content}}';
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
            [['type', 'title'], 'required'],
            [['type'], 'in', 'range' => [self::TYPE_TRAVEL, self::TYPE_CULTURE]],
            [['title'], 'string', 'max' => 120],
            [['cover'], 'string', 'max' => 255],
            [['cover', 'city', 'category'], 'default', 'value' => ''],
            [['city', 'category'], 'string', 'max' => 50],
            [['images'], 'safe'],
            [['detail'], 'string'],
            [['status'], 'in', 'range' => [self::STATUS_OFF, self::STATUS_ON]],
            [['status'], 'default', 'value' => self::STATUS_ON],
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
            'type' => (int) $this->type,
            'title' => $this->title,
            'cover' => $this->cover,
            'city' => $this->city,
            'category' => $this->category,
            'likeCount' => (int) $this->like_count,
            'favoriteCount' => (int) $this->favorite_count,
            'signupCount' => (int) $this->signup_count,
            'status' => (int) $this->status,
            'createdAt' => (int) $this->created_at,
        ];
    }

    public function toDetailArray(): array
    {
        return array_merge($this->toListArray(), [
            'images' => $this->images ?? [],
            'detail' => (string) $this->detail,
            'updatedAt' => (int) $this->updated_at,
        ]);
    }

    /** 管理端视图（全字段）。 */
    public function toAdminArray(): array
    {
        return $this->toDetailArray();
    }
}
