<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * AdminRole model —— 后台角色（对齐 docs/dev/02-数据库设计 §9.6）。
 *
 * @property int $id
 * @property string $name 超级管理员/普通管理员
 * @property string $remark
 * @property int $created_at
 * @property int $updated_at
 */
class AdminRole extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%admin_role}}';
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
            [['name'], 'required'],
            [['name'], 'string', 'max' => 50],
            [['remark'], 'string', 'max' => 255],
        ];
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }
}
