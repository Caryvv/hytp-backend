<?php

declare(strict_types=1);

namespace common\models;

use yii\db\ActiveRecord;

/**
 * AdminRolePermission model —— 角色-权限点（RBAC，对齐 docs/dev/02-数据库设计 §9.6）。
 * 无时间戳字段。
 *
 * @property int $id
 * @property int $role_id
 * @property string $permission_key 权限点如 shop:audit / product:audit / config:edit
 */
class AdminRolePermission extends ActiveRecord
{
    // 常用权限点
    public const PERM_SHOP_AUDIT = 'shop:audit';
    public const PERM_PRODUCT_AUDIT = 'product:audit';
    public const PERM_CONFIG_EDIT = 'config:edit';

    public static function tableName(): string
    {
        return '{{%admin_role_permission}}';
    }

    public function rules(): array
    {
        return [
            [['role_id', 'permission_key'], 'required'],
            [['role_id'], 'integer'],
            [['permission_key'], 'string', 'max' => 50],
            [['permission_key'], 'unique', 'targetAttribute' => ['role_id', 'permission_key']],
        ];
    }
}
