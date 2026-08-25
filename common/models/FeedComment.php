<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\SocialActiveRecord;

/**
 * FeedComment model —— 动态评论（对齐 docs/dev/02-数据库设计 §4.2）。
 * 仅 created_at（无 updated_at）。作者信息由 Service 拼接。
 *
 * @property int $id
 * @property int $feed_id
 * @property int $user_id
 * @property int|null $parent_id 盖楼父评论
 * @property string $content
 * @property int $status 1正常 0隐藏(命中敏感词软隐藏，仅作者可见)
 * @property int $created_at
 */
class FeedComment extends SocialActiveRecord
{
    public const STATUS_NORMAL = 1; // 正常，对所有人可见
    public const STATUS_HIDDEN = 0; // 隐藏（命中敏感词），仅作者本人可见、不计入评论数

    public static function tableName(): string
    {
        return '{{%feed_comment}}';
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
            [['feed_id', 'user_id', 'content'], 'required'],
            [['feed_id', 'user_id', 'parent_id'], 'integer'],
            [['content'], 'string', 'max' => 500],
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
            'feedId' => (int) $this->feed_id,
            'userId' => (int) $this->user_id,
            'parentId' => $this->parent_id !== null ? (int) $this->parent_id : null,
            'content' => $this->content,
            'status' => (int) $this->status,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
