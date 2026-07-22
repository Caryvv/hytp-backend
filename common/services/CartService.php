<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Cart;
use common\models\Product;
use common\models\ProductSku;
use common\models\Shop;

/**
 * 购物车（用户端，需登录）。
 *
 * 校验商品在售、商家正常、SKU 归属；同商品同规格合并数量。
 */
class CartService
{
    /**
     * 购物车列表（含商品快照信息，失效项标记 invalid）。
     *
     * @return array{list:array<int,array>}
     */
    public function list(int $userId): array
    {
        $rows = Cart::find()
            ->where(['user_id' => $userId])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        $list = [];
        foreach ($rows as $row) {
            /** @var Cart $row */
            $list[] = $this->decorate($row);
        }

        return ['list' => $list];
    }

    /**
     * 加入购物车（同商品同规格合并数量）。
     *
     * @param array<string,mixed> $in productId, skuId?, qty?
     */
    public function add(int $userId, array $in): array
    {
        $productId = (int) ($in['productId'] ?? 0);
        $skuId = isset($in['skuId']) && $in['skuId'] !== '' ? (int) $in['skuId'] : null;
        $qty = max(1, (int) ($in['qty'] ?? 1));

        $product = $this->requireOnSaleProduct($productId);
        $this->requireSku($product, $skuId);

        $existing = Cart::findOne([
            'user_id' => $userId,
            'product_id' => $productId,
            'sku_id' => $skuId,
        ]);

        if ($existing !== null) {
            $existing->qty = (int) $existing->qty + $qty;
            $existing->save(false);
            return $this->decorate($existing);
        }

        $cart = new Cart();
        $cart->user_id = $userId;
        $cart->product_id = $productId;
        $cart->sku_id = $skuId;
        $cart->qty = $qty;
        $cart->trade_type = (int) $product->trade_type;
        if (!$cart->save()) {
            throw new BizException(ErrorCode::CART_ITEM_INVALID, $this->firstError($cart));
        }

        return $this->decorate($cart);
    }

    /**
     * 修改数量。
     */
    public function updateQty(int $userId, int $cartId, int $qty): array
    {
        $qty = max(1, $qty);
        $cart = $this->requireOwnItem($userId, $cartId);
        $cart->qty = $qty;
        $cart->save(false);
        return $this->decorate($cart);
    }

    /**
     * 删除单项。
     */
    public function remove(int $userId, int $cartId): void
    {
        $cart = $this->requireOwnItem($userId, $cartId);
        $cart->delete();
    }

    /**
     * 清空购物车。
     */
    public function clear(int $userId): void
    {
        Cart::deleteAll(['user_id' => $userId]);
    }

    // ---------------- 内部 ----------------

    private function requireOwnItem(int $userId, int $cartId): Cart
    {
        $cart = Cart::findOne(['id' => $cartId, 'user_id' => $userId]);
        if ($cart === null) {
            throw new BizException(ErrorCode::CART_ITEM_INVALID);
        }
        return $cart;
    }

    /**
     * 商品必须在售且商家正常。
     */
    private function requireOnSaleProduct(int $productId): Product
    {
        $product = Product::findOne(['id' => $productId]);
        if ($product === null) {
            throw new BizException(ErrorCode::PRODUCT_NOT_FOUND);
        }
        if ((int) $product->status !== Product::STATUS_ON) {
            throw new BizException(ErrorCode::PRODUCT_OFF_SHELF);
        }
        $shop = Shop::findOne(['id' => $product->shop_id]);
        if ($shop === null || (int) $shop->status !== Shop::STATUS_ACTIVE) {
            throw new BizException(ErrorCode::PRODUCT_OFF_SHELF);
        }
        return $product;
    }

    /**
     * 若传了 skuId 必须归属该商品；商品有 SKU 时应指定。
     */
    private function requireSku(Product $product, ?int $skuId): void
    {
        if ($skuId !== null) {
            $sku = ProductSku::findOne(['id' => $skuId, 'product_id' => $product->getId()]);
            if ($sku === null) {
                throw new BizException(ErrorCode::SKU_NOT_FOUND);
            }
        }
    }

    /**
     * 补充商品展示信息 + 有效性/库存判断。
     */
    private function decorate(Cart $cart): array
    {
        $product = Product::findOne(['id' => $cart->product_id]);
        $sku = $cart->sku_id !== null ? ProductSku::findOne(['id' => $cart->sku_id]) : null;

        $valid = $product !== null && (int) $product->status === Product::STATUS_ON;
        $price = $sku !== null ? $sku->price : ($product !== null ? $product->price : '0.00');
        $stock = $sku !== null ? (int) $sku->stock : ($product !== null ? (int) $product->stock : 0);

        return [
            'id' => $cart->getId(),
            'productId' => (int) $cart->product_id,
            'skuId' => $cart->sku_id !== null ? (int) $cart->sku_id : null,
            'qty' => (int) $cart->qty,
            'tradeType' => (int) $cart->trade_type,
            'title' => $product !== null ? $product->title : '',
            'cover' => $product !== null ? $product->cover : '',
            // spec 是 key-value 规格对象；空时强转 (object) 使 JSON 输出 {} 而非 []，
            // 否则 Android kotlinx 反序列化 Map 遇到 [] 会崩。
            'spec' => (object) ($sku !== null ? ($sku->spec_json ?? []) : []),
            'price' => $price,
            'stock' => $stock,
            'shopId' => $product !== null ? (int) $product->shop_id : 0,
            'valid' => $valid,
        ];
    }

    private function firstError(Cart $cart): ?string
    {
        foreach ($cart->getFirstErrors() as $msg) {
            return $msg;
        }
        return null;
    }
}
