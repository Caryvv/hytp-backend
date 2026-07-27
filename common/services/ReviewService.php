<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\OrderItem;
use common\models\Product;
use common\models\ProductReview;
use common\models\ShopOrder;
use Yii;

/**
 * 商品评价提交（用户端，需登录）。
 *
 * 校验订单已完成 + 归属；写 product_review 并触发情感分析（本轮占位打桩）。
 * 情感分析后续接 AI 阶段（13 文档）异步任务；当前按 rating 粗判 + 简易关键词。
 */
class ReviewService
{
    /**
     * 提交评价。一个订单内的商品只允许评价一次。
     *
     * @param array<string,mixed> $in productId, rating, content?, images?
     */
    public function submit(int $userId, string $orderNo, array $in): array
    {
        $order = ShopOrder::findOne(['order_no' => $orderNo]);
        if ($order === null) {
            throw new BizException(ErrorCode::ORDER_NOT_FOUND);
        }
        if ((int) $order->user_id !== $userId) {
            throw new BizException(ErrorCode::FORBIDDEN);
        }
        if ((int) $order->status !== ShopOrder::STATUS_FINISHED) {
            throw new BizException(ErrorCode::REVIEW_NOT_ALLOWED);
        }

        $productId = (int) ($in['productId'] ?? 0);
        // 商品须在该订单内
        $item = OrderItem::findOne(['order_id' => $order->getId(), 'product_id' => $productId]);
        if ($item === null) {
            throw new BizException(ErrorCode::CART_ITEM_INVALID, '该商品不在此订单内');
        }

        // 一个订单内同商品只能评一次
        $exists = ProductReview::findOne(['order_id' => $order->getId(), 'product_id' => $productId, 'user_id' => $userId]);
        if ($exists !== null) {
            throw new BizException(ErrorCode::REVIEW_ALREADY_EXISTS);
        }

        $rating = (int) ($in['rating'] ?? 5);
        $rating = max(1, min(5, $rating));
        $content = (string) ($in['content'] ?? '');

        $review = new ProductReview();
        $review->order_id = $order->getId();
        $review->product_id = $productId;
        $review->user_id = $userId;
        $review->rating = $rating;
        $review->content = $content;
        $review->images = isset($in['images']) && is_array($in['images']) ? $in['images'] : [];

        // 情感分析：先试 AI 微服务，失败/未启用回退规则版（doc 13 §7 降级，不阻断评价提交）
        [$sentiment, $keywords] = $this->analyze($rating, $content);
        $review->sentiment = $sentiment;
        $review->keywords = $keywords;

        if (!$review->save()) {
            throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($review) ?? '评价提交失败');
        }

        // 更新商品评分（简单平均）
        $this->refreshProductRating($productId);

        return $review->toArray();
    }

    /**
     * 情感分析：内容非空时先试 AI 微服务，失败/未启用回退规则版（doc 13 §5/§7）。
     *
     * @return array{0:int,1:array<int,string>}
     */
    private function analyze(int $rating, string $content): array
    {
        if (trim($content) !== '') {
            $ai = (new AiSentimentService())->analyze([$content]);
            if ($ai !== null && isset($ai[0])) {
                return [$ai[0]['sentiment'], $ai[0]['keywords']];
            }
        }
        return $this->analyzeByRule($rating, $content);
    }

    /**
     * 规则版情感分析（AI 不可用时的降级）：按评分粗判情感，从内容提取常见工艺关键词。
     *
     * @return array{0:int,1:array<int,string>}
     */
    private function analyzeByRule(int $rating, string $content): array
    {
        $sentiment = $rating >= 4
            ? ProductReview::SENTIMENT_POSITIVE
            : ($rating <= 2 ? ProductReview::SENTIMENT_NEGATIVE : ProductReview::SENTIMENT_NEUTRAL);

        $dict = ['线头', '色差', '炸褶', '做工', '版型', '面料', '发货', '客服', '性价比', '还原'];
        $keywords = [];
        foreach ($dict as $word) {
            if (mb_strpos($content, $word) !== false) {
                $keywords[] = $word;
            }
        }

        return [$sentiment, $keywords];
    }

    private function refreshProductRating(int $productId): void
    {
        $avg = ProductReview::find()->where(['product_id' => $productId])->average('rating');
        if ($avg !== null) {
            Product::updateAll(['rating' => round((float) $avg, 2)], ['id' => $productId]);
        }
    }

    private function firstError(ProductReview $review): ?string
    {
        foreach ($review->getFirstErrors() as $msg) {
            return $msg;
        }
        return null;
    }
}
