<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * ChatMessage model —— 私信消息（对齐 docs/dev/02-数据库设计 §4.4）。仅 created_at。
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $from_user
 * @property int $to_user
 * @property string $content
 * @property int $msg_type 1文本 2图片
 * @property int $is_read
 * @property int $created_at
 */
class ChatMessage extends ActiveRecord
{
    public const TYPE_TEXT = 1;
    public const TYPE_IMAGE = 2;

    public static function tableName(): string
    {
        return '{{%chat_message}}';
    }

    public function behaviors(): array
    {
        return [
            ['class' => TimestampBehavior::class, 'updatedAtAttribute' => false],
        ];
    }

    public function rules(): array
    {
        return [
            [['conversation_id', 'from_user', 'to_user', 'content'], 'required'],
            [['conversation_id', 'from_user', 'to_user'], 'integer'],
            [['content'], 'string', 'max' => 1000],
            [['msg_type'], 'in', 'range' => [self::TYPE_TEXT, self::TYPE_IMAGE]],
            [['msg_type'], 'default', 'value' => self::TYPE_TEXT],
            [['is_read'], 'in', 'range' => [0, 1]],
            [['is_read'], 'default', 'value' => 0],
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
            'conversationId' => (int) $this->conversation_id,
            'fromUser' => (int) $this->from_user,
            'toUser' => (int) $this->to_user,
            'content' => $this->content,
            'msgType' => (int) $this->msg_type,
            'isRead' => (int) $this->is_read,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
