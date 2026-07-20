<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * Address model —— 收货地址（对齐 docs/dev/02-数据库设计 §9.1）。
 *
 * @property int $id
 * @property int $user_id
 * @property string $name 收货人
 * @property string $phone
 * @property string $province
 * @property string $city
 * @property string $district
 * @property string $detail
 * @property int $is_default
 * @property int $created_at
 * @property int $updated_at
 */
class Address extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%address}}';
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
            [['user_id', 'name', 'phone', 'province', 'city', 'detail'], 'required'],
            [['user_id'], 'integer'],
            [['name'], 'string', 'max' => 50],
            [['phone'], 'match', 'pattern' => '/^1[3-9]\d{9}$/', 'message' => '手机号格式不正确'],
            [['province', 'city', 'district'], 'string', 'max' => 50],
            [['district'], 'default', 'value' => ''],
            [['detail'], 'string', 'max' => 255],
            [['is_default'], 'in', 'range' => [0, 1]],
            [['is_default'], 'default', 'value' => 0],
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
            'userId' => (int) $this->user_id,
            'name' => $this->name,
            'phone' => $this->phone,
            'province' => $this->province,
            'city' => $this->city,
            'district' => $this->district,
            'detail' => $this->detail,
            'isDefault' => (int) $this->is_default,
        ];
    }
}
