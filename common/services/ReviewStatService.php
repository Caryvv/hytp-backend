<?php

declare(strict_types=1);

namespace common\services;

use common\models\Product;
use common\models\ProductReview;
use common\models\Shop;

/**
 * 商家评价洞察聚合（doc 13 §5 商家驾驶舱 review-keywords）。
 * 数据源为 ReviewService 写入的 product_review.sentiment/keywords（AI 或规则版产出）。
 */
class ReviewStatService
{
    /**
     * 本店评价情感分布 + 高频品控关键词。
     *
     * @return array{
     *   total:int,
     *   sentiment:array{positive:int,neutral:int,negative:int},
     *   keywords:array<int,array{word:string,count:int}>
     * }
     */
    public function shopReviewStats(Shop $shop, int $topN = 15): array
    {
        // 本店商品 id
        $productIds = Product::find()
            ->select('id')
            ->where(['shop_id' => $shop->getId()])
            ->column();
        $productIds = array_map('intval', $productIds);

        if ($productIds === []) {
            return [
                'total' => 0,
                'sentiment' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
                'keywords' => [],
            ];
        }

        // 情感分布：SQL 分组计数
        $rows = ProductReview::find()
            ->select(['sentiment', 'c' => 'COUNT(*)'])
            ->where(['product_id' => $productIds])
            ->andWhere(['not', ['sentiment' => null]])
            ->groupBy('sentiment')
            ->asArray()
            ->all();
        $sentiment = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $total = 0;
        foreach ($rows as $r) {
            $c = (int) $r['c'];
            $total += $c;
            match ((int) $r['sentiment']) {
                ProductReview::SENTIMENT_POSITIVE => $sentiment['positive'] = $c,
                ProductReview::SENTIMENT_NEGATIVE => $sentiment['negative'] = $c,
                default => $sentiment['neutral'] = $c,
            };
        }

        // 高频关键词：遍历 keywords JSON 累加词频
        // ponytail: 逐条累加，评价量级小够用；上千条改 data_stat_daily 预聚合表
        $freq = [];
        $keywordRows = ProductReview::find()
            ->select('keywords')
            ->where(['product_id' => $productIds])
            ->andWhere(['not', ['keywords' => null]])
            ->asArray()
            ->all();
        foreach ($keywordRows as $row) {
            $kw = $row['keywords'];
            if (is_string($kw)) {
                $kw = json_decode($kw, true);
            }
            if (!is_array($kw)) {
                continue;
            }
            foreach ($kw as $word) {
                $word = trim((string) $word);
                if ($word !== '') {
                    $freq[$word] = ($freq[$word] ?? 0) + 1;
                }
            }
        }
        arsort($freq);
        $keywords = [];
        foreach (array_slice($freq, 0, $topN, true) as $word => $count) {
            $keywords[] = ['word' => (string) $word, 'count' => (int) $count];
        }

        return [
            'total' => $total,
            'sentiment' => $sentiment,
            'keywords' => $keywords,
        ];
    }
}
