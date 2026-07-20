<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * GroupMessage model —— 群聊消息（独立于私信 chat_message）。仅 created_at。
 * 发送者信息由 Service 拼接。
 *
 * @property int $id
 * @property int $group_id
 * @property int $from_user
 * @property string $content
 * @property int $msg_type 1文本 2图片
 * @property int $created_at
 */
class GroupMessage extends ActiveRecord
{
    public const TYPE_TEXT = 1;
    public const TYPE_IMAGE = 2;

    public static function tableName(): string
    {
        return '{{%group_message}}';
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
            [['group_id', 'from_user', 'content'], 'required'],
            [['group_id', 'from_user'], 'integer'],
            [['content'], 'string', 'max' => 1000],
            [['msg_type'], 'in', 'range' => [self::TYPE_TEXT, self::TYPE_IMAGE]],
            [['msg_type'], 'default', 'value' => self::TYPE_TEXT],
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
            'groupId' => (int) $this->group_id,
            'fromUser' => (int) $this->from_user,
            'content' => $this->content,
            'msgType' => (int) $this->msg_type,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
