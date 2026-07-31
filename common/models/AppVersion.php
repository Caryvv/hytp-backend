<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * App 版本（hytp 默认库）——应用内更新检查。
 *
 * @property int $id
 * @property string $platform
 * @property int $version_code
 * @property string $version_name
 * @property string|null $update_log
 * @property string $download_url
 * @property int $force_update
 * @property int $min_supported_code
 * @property int $enabled
 * @property int $created_at
 * @property int $updated_at
 */
class AppVersion extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%app_version}}';
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
            [['platform', 'version_code', 'version_name'], 'required'],
            [['platform'], 'string', 'max' => 20],
            [['version_name'], 'string', 'max' => 30],
            [['download_url'], 'string', 'max' => 500],
            [['update_log'], 'string'],
            [['version_code', 'min_supported_code', 'force_update', 'enabled'], 'integer'],
            [['force_update'], 'in', 'range' => [0, 1]],
            [['enabled'], 'in', 'range' => [0, 1]],
            [['force_update', 'min_supported_code'], 'default', 'value' => 0],
            [['enabled'], 'default', 'value' => 1],
        ];
    }

    /**
     * 供客户端消费的版本信息（驼峰）。
     *
     * @return array{versionCode:int, versionName:string, updateLog:string, downloadUrl:string, forceUpdate:bool}
     */
    public function toClientArray(bool $forceUpdate): array
    {
        return [
            'versionCode' => (int) $this->version_code,
            'versionName' => $this->version_name,
            'updateLog' => (string) ($this->update_log ?? ''),
            'downloadUrl' => $this->download_url,
            'forceUpdate' => $forceUpdate,
        ];
    }

    /** 管理端列表（全字段）。 */
    public function toAdminArray(): array
    {
        return [
            'id' => (int) $this->id,
            'platform' => $this->platform,
            'versionCode' => (int) $this->version_code,
            'versionName' => $this->version_name,
            'updateLog' => (string) ($this->update_log ?? ''),
            'downloadUrl' => $this->download_url,
            'forceUpdate' => (int) $this->force_update === 1,
            'minSupportedCode' => (int) $this->min_supported_code,
            'enabled' => (int) $this->enabled === 1,
            'createdAt' => (int) $this->created_at,
            'updatedAt' => (int) $this->updated_at,
        ];
    }
}
