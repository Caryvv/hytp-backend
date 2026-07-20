<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use common\base\TradeActiveRecord;

/**
 * ProductCategory model —— 商品分类（树形，对齐 docs/dev/02-数据库设计 §3.2）。
 *
 * @property int $id
 * @property int $parent_id 父分类，0为顶级
 * @property string $name
 * @property int $level 层级
 * @property int $sort 排序，越小越前
 * @property string $icon
 * @property int $created_at
 * @property int $updated_at
 */
class ProductCategory extends TradeActiveRecord
{
    public static function tableName(): string
    {
        return '{{%product_category}}';
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
            [['icon'], 'string', 'max' => 255],
            [['parent_id', 'level', 'sort'], 'integer'],
            [['parent_id'], 'default', 'value' => 0],
            [['level'], 'default', 'value' => 1],
            [['sort'], 'default', 'value' => 0],
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
            'parentId' => (int) $this->parent_id,
            'name' => $this->name,
            'level' => (int) $this->level,
            'sort' => (int) $this->sort,
            'icon' => $this->icon,
        ];
    }
}
