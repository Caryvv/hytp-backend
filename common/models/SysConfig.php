<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\AdminActiveRecord;

/**
 * SysConfig model —— 平台参数（对齐 docs/dev/02-数据库设计 §9.7）。
 * 注意：字段用 config_key/config_value（key/value 为 MySQL 保留字）。
 *
 * @property int $id
 * @property string $config_key
 * @property string|null $config_value
 * @property string $remark
 * @property int $created_at
 * @property int $updated_at
 */
class SysConfig extends AdminActiveRecord
{
    public static function tableName(): string
    {
        return '{{%sys_config}}';
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
            [['config_key'], 'required'],
            [['config_key'], 'string', 'max' => 64],
            [['config_key'], 'unique'],
            [['config_value'], 'string'],
            [['remark'], 'string', 'max' => 255],
        ];
    }

    /**
     * 读取配置值（不存在返回默认）。
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $row = static::findOne(['config_key' => $key]);
        return $row !== null ? $row->config_value : $default;
    }
}
