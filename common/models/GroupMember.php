<?php

declare(strict_types=1);

namespace common\models;

use common\base\SocialActiveRecord;

/**
 * GroupMember model —— 群成员（对齐 docs/dev/02-数据库设计 §4.5）。
 * 唯一键 (group_id,user_id)。用 joined_at（非标准 created_at），手动赋值。
 *
 * @property int $id
 * @property int $group_id
 * @property int $user_id
 * @property int $role 0成员 1管理 2群主
 * @property int $joined_at
 */
class GroupMember extends SocialActiveRecord
{
    public const ROLE_MEMBER = 0;
    public const ROLE_ADMIN = 1;
    public const ROLE_OWNER = 2;

    public static function tableName(): string
    {
        return '{{%group_member}}';
    }

    public function rules(): array
    {
        return [
            [['group_id', 'user_id'], 'required'],
            [['group_id', 'user_id', 'joined_at'], 'integer'],
            [['role'], 'in', 'range' => [self::ROLE_MEMBER, self::ROLE_ADMIN, self::ROLE_OWNER]],
            [['role'], 'default', 'value' => self::ROLE_MEMBER],
        ];
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }
}
