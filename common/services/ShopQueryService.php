<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Product;
use common\models\Shop;

/**
 * 店铺只读查询（用户端店铺主页，补 docs/dev/08 未列出的端点）。
 */
class ShopQueryService
{
    /**
     * 店铺主页（仅正常状态商家公开）。
     */
    public function detail(int $shopId): array
    {
        $shop = Shop::findOne(['id' => $shopId]);
        if ($shop === null) {
            throw new BizException(ErrorCode::SHOP_NOT_FOUND);
        }
        if (!$shop->isActive()) {
            throw new BizException(ErrorCode::SHOP_STATUS_INVALID, '店铺不可访问');
        }

        $onSaleCount = (int) Product::find()
            ->where(['shop_id' => $shopId, 'status' => Product::STATUS_ON])
            ->count();

        return array_merge($shop->toPublicArray(), [
            'onSaleCount' => $onSaleCount,
        ]);
    }

    /**
     * 店铺在售商品列表。
     *
     * @param array{page:?int, pageSize:?int} $in
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function products(int $shopId, array $in): array
    {
        $shop = Shop::findOne(['id' => $shopId]);
        if ($shop === null || !$shop->isActive()) {
            throw new BizException(ErrorCode::SHOP_NOT_FOUND);
        }

        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = Product::find()->where(['shop_id' => $shopId, 'status' => Product::STATUS_ON]);
        $total = (int) $query->count();
        $rows = $query->orderBy(['sales' => SORT_DESC, 'id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => array_map(static fn (Product $p): array => $p->toListArray(), $rows),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }
}
