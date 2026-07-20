<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\SocialActiveRecord;

/**
 * ChatConversation model —— 私信会话（对齐 docs/dev/02-数据库设计 §4.4）。
 * user_a < user_b 有序对，唯一键防重复会话。对方信息由 Service 拼接。
 *
 * @property int $id
 * @property int $user_a 较小 userId
 * @property int $user_b 较大 userId
 * @property string $last_msg
 * @property int $last_at
 * @property int $created_at
 * @property int $updated_at
 */
class ChatConversation extends SocialActiveRecord
{
    public static function tableName(): string
    {
        return '{{%chat_conversation}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['user_a', 'user_b'], 'required'],
            [['user_a', 'user_b', 'last_at'], 'integer'],
            [['last_msg'], 'string', 'max' => 255],
            [['last_msg'], 'default', 'value' => ''],
        ];
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }

    /** 会话中的另一方 userId。 */
    public function otherUserId(int $userId): int
    {
        return (int) $this->user_a === $userId ? (int) $this->user_b : (int) $this->user_a;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => $this->getId(),
            'lastMsg' => $this->last_msg,
            'lastAt' => (int) $this->last_at,
        ];
    }
}
