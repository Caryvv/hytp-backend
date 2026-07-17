<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Product;
use common\models\ProductCategory;
use common\models\Shop;

/**
 * 商家端商品业务：CRUD、提审、上下架、库存。
 *
 * 越权约束：所有写操作校验 product.shop_id === 当前商家 id。
 * 商品新建/编辑后 status=审核中(2)，需管理端审核通过后 status=在售(1)。
 */
class ProductService
{
    /**
     * 本店商品列表（含各状态）。
     *
     * @param array{status?:mixed, page?:mixed, pageSize?:mixed} $in
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function listByShop(Shop $shop, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = Product::find()->where(['shop_id' => $shop->getId()]);
        if (isset($in['status']) && $in['status'] !== '') {
            $query->andWhere(['status' => (int) $in['status']]);
        }

        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        return [
            'list' => array_map(static fn (Product $p): array => $p->toListArray(), $rows),
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 本店商品详情（越权校验）。
     */
    public function detailForShop(Shop $shop, int $productId): array
    {
        return $this->ownedProduct($shop, $productId)->toDetailArray();
    }

    /**
     * 新建商品（提交即进入审核中）。
     *
     * @param array<string,mixed> $in
     */
    public function create(Shop $shop, array $in): array
    {
        if (!$shop->isActive()) {
            throw new BizException(ErrorCode::SHOP_NOT_AUDITED, '商家审核通过后才能上架商品');
        }

        $product = new Product();
        $product->shop_id = $shop->getId();
        $this->fill($product, $in);
        $product->status = Product::STATUS_AUDITING;

        if (!$product->save()) {
            $first = $this->firstError($product);
            throw new BizException(ErrorCode::PRODUCT_PARAM_INVALID, $first ?? '商品创建失败');
        }
        return $product->toDetailArray();
    }

    /**
     * 编辑商品（编辑后重新进入审核）。
     *
     * @param array<string,mixed> $in
     */
    public function update(Shop $shop, int $productId, array $in): array
    {
        $product = $this->ownedProduct($shop, $productId);
        $this->fill($product, $in);
        // 修改内容需重新审核
        $product->status = Product::STATUS_AUDITING;
        $product->audit_remark = '';

        if (!$product->save()) {
            $first = $this->firstError($product);
            throw new BizException(ErrorCode::PRODUCT_PARAM_INVALID, $first ?? '商品保存失败');
        }
        return $product->toDetailArray();
    }

    /**
     * 上/下架切换：仅在「在售」<->「下架」之间切换；审核中/违规下架不可操作。
     */
    public function toggle(Shop $shop, int $productId): array
    {
        $product = $this->ownedProduct($shop, $productId);
        $status = (int) $product->status;

        if ($status === Product::STATUS_ON) {
            $product->status = Product::STATUS_OFF;
        } elseif ($status === Product::STATUS_OFF) {
            $product->status = Product::STATUS_ON;
        } else {
            throw new BizException(ErrorCode::PRODUCT_STATUS_INVALID, '当前状态不可上下架');
        }

        if (!$product->save(false)) {
            throw new BizException(ErrorCode::INTERNAL_ERROR, '操作失败');
        }
        return $product->toListArray();
    }

    /**
     * 更新库存。
     */
    public function updateStock(Shop $shop, int $productId, int $stock): array
    {
        if ($stock < 0) {
            throw new BizException(ErrorCode::PARAM_INVALID, '库存不能为负');
        }
        $product = $this->ownedProduct($shop, $productId);
        $product->stock = $stock;
        if (!$product->save(false)) {
            throw new BizException(ErrorCode::INTERNAL_ERROR, '操作失败');
        }
        return $product->toListArray();
    }

    // ---------------- 内部 ----------------

    /**
     * 取本店商品，非本店抛「无权限」，不存在抛「商品不存在」。
     */
    private function ownedProduct(Shop $shop, int $productId): Product
    {
        $product = Product::findOne(['id' => $productId]);
        if ($product === null) {
            throw new BizException(ErrorCode::PRODUCT_NOT_FOUND);
        }
        if ((int) $product->shop_id !== $shop->getId()) {
            throw new BizException(ErrorCode::FORBIDDEN, '无权操作该商品');
        }
        return $product;
    }

    /**
     * 从入参填充可编辑字段（白名单）。
     *
     * @param array<string,mixed> $in
     */
    private function fill(Product $product, array $in): void
    {
        if (isset($in['title'])) {
            $product->title = (string) $in['title'];
        }
        if (isset($in['categoryId'])) {
            $categoryId = (int) $in['categoryId'];
            if ($categoryId > 0 && ProductCategory::findOne(['id' => $categoryId]) === null) {
                throw new BizException(ErrorCode::CATEGORY_NOT_FOUND);
            }
            $product->category_id = $categoryId;
        }
        if (isset($in['formeDynasty'])) {
            $product->forme_dynasty = (int) $in['formeDynasty'];
        }
        if (isset($in['formeType'])) {
            $product->forme_type = (string) $in['formeType'];
        }
        if (isset($in['style'])) {
            $product->style = (string) $in['style'];
        }
        if (isset($in['tradeType'])) {
            $product->trade_type = (int) $in['tradeType'];
        }
        if (isset($in['price'])) {
            $product->price = (string) $in['price'];
        }
        if (isset($in['cover'])) {
            $product->cover = (string) $in['cover'];
        }
        if (array_key_exists('images', $in)) {
            $product->images = is_array($in['images']) ? $in['images'] : null;
        }
        if (isset($in['detail'])) {
            $product->detail = (string) $in['detail'];
        }
        if (array_key_exists('tryonModelUrl', $in)) {
            $product->tryon_model_url = $in['tryonModelUrl'] !== null ? (string) $in['tryonModelUrl'] : null;
        }
        if (isset($in['stock'])) {
            $product->stock = (int) $in['stock'];
        }
        if (isset($in['isOriginal'])) {
            $product->is_original = (int) $in['isOriginal'];
        }
    }

    private function firstError(\yii\db\ActiveRecord $model): ?string
    {
        foreach ($model->getFirstErrors() as $msg) {
            return $msg;
        }
        return null;
    }
}
