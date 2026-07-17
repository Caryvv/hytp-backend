<?php

declare(strict_types=1);

namespace common\models;

use yii\db\ActiveRecord;

/**
 * ProductReview model —— 商品评价（用户端只读展示，情感分析源；提交属阶段3）。
 * 对齐 docs/dev/02-数据库设计 §3.9。仅 created_at（无 updated_at）。
 *
 * @property int $id
 * @property int|null $order_id
 * @property int $product_id
 * @property int $user_id
 * @property int $rating 1-5 星
 * @property string|null $content
 * @property array|null $images
 * @property int|null $sentiment 0负 1中 2正
 * @property array|null $keywords
 * @property int $created_at
 */
class ProductReview extends ActiveRecord
{
    public const SENTIMENT_NEGATIVE = 0;
    public const SENTIMENT_NEUTRAL = 1;
    public const SENTIMENT_POSITIVE = 2;

    public static function tableName(): string
    {
        return '{{%product_review}}';
    }

    public function rules(): array
    {
        return [
            [['product_id', 'user_id'], 'required'],
            [['order_id', 'product_id', 'user_id'], 'integer'],
            [['rating'], 'integer', 'min' => 1, 'max' => 5],
            [['rating'], 'default', 'value' => 5],
            [['content'], 'string'],
            [['images', 'keywords'], 'safe'],
            [['sentiment'], 'in', 'range' => [
                self::SENTIMENT_NEGATIVE, self::SENTIMENT_NEUTRAL, self::SENTIMENT_POSITIVE,
            ]],
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
            'productId' => (int) $this->product_id,
            'userId' => (int) $this->user_id,
            'rating' => (int) $this->rating,
            'content' => $this->content ?? '',
            'images' => $this->images ?? [],
            'sentiment' => $this->sentiment !== null ? (int) $this->sentiment : null,
            'keywords' => $this->keywords ?? [],
            'createdAt' => (int) $this->created_at,
        ];
    }
}
