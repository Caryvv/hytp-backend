<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * Product model —— 商品（对齐 docs/dev/02-数据库设计 §3.1）。
 *
 * status：0下架 1在售 2审核中 3违规下架。新建默认 2（提审）。
 * 用户端只展示 status=1。
 *
 * @property int $id
 * @property int $shop_id
 * @property string $title
 * @property int $category_id
 * @property int $forme_dynasty 1秦汉 2魏晋 3唐 4宋 5明 0其他
 * @property string $forme_type 形制
 * @property string $style 风格
 * @property int $trade_type 1售卖 2租赁 3定制 4服务
 * @property string $price
 * @property string $cover
 * @property array|null $images
 * @property string|null $detail
 * @property string|null $tryon_model_url
 * @property int $stock
 * @property int $is_original
 * @property int $sales
 * @property string $rating
 * @property int $status
 * @property string $audit_remark
 * @property int $created_at
 * @property int $updated_at
 */
class Product extends ActiveRecord
{
    // 商品状态
    public const STATUS_OFF = 0;       // 下架
    public const STATUS_ON = 1;        // 在售
    public const STATUS_AUDITING = 2;  // 审核中
    public const STATUS_VIOLATION = 3; // 违规下架

    // 交易类型
    public const TRADE_SELL = 1;    // 售卖
    public const TRADE_RENT = 2;    // 租赁
    public const TRADE_CUSTOM = 3;  // 定制
    public const TRADE_SERVICE = 4; // 服务（妆造/摄影）

    public static function tableName(): string
    {
        return '{{%product}}';
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
            [['shop_id', 'title'], 'required'],
            [['shop_id', 'category_id', 'stock', 'sales'], 'integer'],
            [['title'], 'string', 'max' => 120],
            [['forme_type', 'style'], 'string', 'max' => 30],
            [['cover', 'tryon_model_url', 'audit_remark'], 'string', 'max' => 255],
            [['detail'], 'string'],
            [['images'], 'safe'],
            [['price', 'rating'], 'number', 'min' => 0],
            [['price'], 'default', 'value' => 0],
            [['rating'], 'default', 'value' => 0],
            [['stock', 'sales'], 'default', 'value' => 0],
            [['forme_dynasty'], 'in', 'range' => [0, 1, 2, 3, 4, 5]],
            [['forme_dynasty'], 'default', 'value' => 0],
            [['trade_type'], 'in', 'range' => [
                self::TRADE_SELL, self::TRADE_RENT, self::TRADE_CUSTOM, self::TRADE_SERVICE,
            ]],
            [['trade_type'], 'default', 'value' => self::TRADE_SELL],
            [['is_original'], 'in', 'range' => [0, 1]],
            [['is_original'], 'default', 'value' => 0],
            [['status'], 'in', 'range' => [
                self::STATUS_OFF, self::STATUS_ON, self::STATUS_AUDITING, self::STATUS_VIOLATION,
            ]],
            [['status'], 'default', 'value' => self::STATUS_AUDITING],
        ];
    }

    public function getId(): int
    {
        return (int) $this->getPrimaryKey();
    }

    // ---------------- 输出 ----------------

    /**
     * 列表卡片（用户端商城/店铺页、商家端列表通用轻量字段）。
     */
    public function toListArray(): array
    {
        return [
            'id' => $this->getId(),
            'shopId' => (int) $this->shop_id,
            'title' => $this->title,
            'categoryId' => (int) $this->category_id,
            'formeDynasty' => (int) $this->forme_dynasty,
            'formeType' => $this->forme_type,
            'style' => $this->style,
            'tradeType' => (int) $this->trade_type,
            'price' => $this->price,
            'cover' => $this->cover,
            'stock' => (int) $this->stock,
            'isOriginal' => (int) $this->is_original,
            'sales' => (int) $this->sales,
            'rating' => $this->rating,
            'status' => (int) $this->status,
        ];
    }

    /**
     * 详情（含图集/富文本/试穿素材）。
     */
    public function toDetailArray(): array
    {
        return array_merge($this->toListArray(), [
            'images' => $this->images ?? [],
            'detail' => $this->detail ?? '',
            'tryonModelUrl' => $this->tryon_model_url,
            'auditRemark' => $this->audit_remark,
            'createdAt' => (int) $this->created_at,
            'updatedAt' => (int) $this->updated_at,
        ]);
    }
}
