<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * 首页轮播图 Banner（hytp 默认库）。
 *
 * @property int $id
 * @property string $title
 * @property string $image_url
 * @property int $link_type  0=无跳转 1=商品 2=外部链接
 * @property string $link_value
 * @property int $sort_order
 * @property int $status  1=启用 0=禁用
 * @property int $created_at
 * @property int $updated_at
 */
class HomeBanner extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%home_banner}}';
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
            [['title'], 'required'],
            [['title'], 'string', 'max' => 100],
            [['image_url', 'link_value'], 'string', 'max' => 500],
            [['link_type', 'sort_order', 'status'], 'integer'],
            [['link_type'], 'in', 'range' => [0, 1, 2]],
            [['status'], 'in', 'range' => [0, 1]],
            [['link_type'], 'default', 'value' => 0],
            [['sort_order'], 'default', 'value' => 0],
            [['status'], 'default', 'value' => 1],
        ];
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'imageUrl' => $this->image_url,
            'linkType' => (int) $this->link_type,
            'linkValue' => $this->link_value,
            'sortOrder' => (int) $this->sort_order,
            'status' => (int) $this->status,
        ];
    }
}
