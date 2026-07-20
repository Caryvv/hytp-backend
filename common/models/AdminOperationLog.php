<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\AdminActiveRecord;

/**
 * AdminOperationLog model —— 后台操作日志（对齐 docs/dev/02-数据库设计 §9.6）。
 * 仅 created_at。
 *
 * @property int $id
 * @property int $admin_id
 * @property string $action 操作动作，如 shop.audit
 * @property string $module 模块，如 shop/product
 * @property string|null $detail
 * @property string $ip
 * @property int $created_at
 */
class AdminOperationLog extends AdminActiveRecord
{
    public static function tableName(): string
    {
        return '{{%admin_operation_log}}';
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
            [['admin_id', 'action'], 'required'],
            [['admin_id'], 'integer'],
            [['action'], 'string', 'max' => 50],
            [['module'], 'string', 'max' => 30],
            [['detail'], 'string'],
            [['ip'], 'string', 'max' => 45],
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
            'adminId' => (int) $this->admin_id,
            'action' => $this->action,
            'module' => $this->module,
            'detail' => $this->detail ?? '',
            'ip' => $this->ip,
            'createdAt' => (int) $this->created_at,
        ];
    }
}
