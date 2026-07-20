<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Product;
use common\models\ProductReview;
use common\models\ProductSku;
use common\models\Shop;

/**
 * 商品只读查询（用户端，白名单免登录）。
 *
 * 只暴露 status=在售 且 归属商家 status=正常 的商品。
 */
class ProductQueryService
{
    /** 允许的排序字段（白名单） */
    private const SORT_MAP = [
        'sales' => ['sales' => SORT_DESC],
        '-sales' => ['sales' => SORT_DESC],
        'sales-asc' => ['sales' => SORT_ASC],
        'rating' => ['rating' => SORT_DESC],
        '-rating' => ['rating' => SORT_DESC],
        'price' => ['price' => SORT_ASC],
        'price-desc' => ['price' => SORT_DESC],
        'new' => ['id' => SORT_DESC],
    ];

    /**
     * 商品列表（筛选 + 分页）。
     *
     * @param array<string,mixed> $in 支持 categoryId/formeDynasty/formeType/style/tradeType/keyword/sort/page/pageSize
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function list(array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = $this->onSaleQuery();

        if (!empty($in['categoryId'])) {
            $query->andWhere(['category_id' => (int) $in['categoryId']]);
        }
        if (isset($in['formeDynasty']) && $in['formeDynasty'] !== '') {
            $query->andWhere(['forme_dynasty' => (int) $in['formeDynasty']]);
        }
        if (!empty($in['formeType'])) {
            $query->andWhere(['forme_type' => (string) $in['formeType']]);
        }
        if (!empty($in['style'])) {
            $query->andWhere(['style' => (string) $in['style']]);
        }
        if (!empty($in['tradeType'])) {
            $query->andWhere(['trade_type' => (int) $in['tradeType']]);
        }
        if (!empty($in['keyword'])) {
            $query->andWhere(['like', 'title', (string) $in['keyword']]);
        }

        $sortKey = (string) ($in['sort'] ?? 'new');
        $order = self::SORT_MAP[$sortKey] ?? ['id' => SORT_DESC];

        $total = (int) $query->count();
        $rows = $query->orderBy($order)
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => array_map(static fn (Product $p): array => $p->toListArray(), $rows),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 商品详情（含 SKU + 店铺公开信息）。
     */
    public function detail(int $productId): array
    {
        $product = $this->onSaleQuery()->andWhere(['id' => $productId])->one();
        if ($product === null) {
            // 不存在或未在售，统一按下架处理
            $exists = Product::findOne(['id' => $productId]);
            throw new BizException(
                $exists !== null ? ErrorCode::PRODUCT_OFF_SHELF : ErrorCode::PRODUCT_NOT_FOUND
            );
        }
        /** @var Product $product */

        $skus = ProductSku::find()->where(['product_id' => $productId])->all();
        $shop = Shop::findOne(['id' => $product->shop_id]);

        return array_merge($product->toDetailArray(), [
            'skus' => array_map(static fn (ProductSku $s): array => $s->toArray(), $skus),
            'shop' => $shop !== null ? $shop->toPublicArray() : null,
        ]);
    }

    /**
     * 商品评价列表（只读展示，含情感关键词）。
     *
     * @param array{page:?int, pageSize:?int} $in
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function reviews(int $productId, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = ProductReview::find()->where(['product_id' => $productId]);
        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => array_map(static fn (ProductReview $r): array => $r->toArray(), $rows),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 在售商品基础查询：product.status=在售 且 shop.status=正常。
     *
     * @return \yii\db\ActiveQuery<Product>
     */
    private function onSaleQuery(): \yii\db\ActiveQuery
    {
        // 跨库（shop@shop × product@trade）：先在商家库取正常店铺 id 数组，
        // 再传给商品库查询（不能用 ActiveQuery 子查询对象，跨连接会执行失败）。
        $activeShopIds = Shop::find()
            ->select('id')
            ->where(['status' => Shop::STATUS_ACTIVE])
            ->column();

        return Product::find()
            ->where(['status' => Product::STATUS_ON])
            ->andWhere(['shop_id' => $activeShopIds]);
    }
}
