<?php

declare(strict_types=1);

namespace common\services;

use common\components\Redis;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Address;
use common\models\Cart;
use common\models\OrderItem;
use common\models\Product;
use common\models\ProductSku;
use common\models\Shop;
use common\models\ShopOrder;
use Yii;

/**
 * 订单（用户端，需登录）。
 *
 * 购物车按 shop_id 拆单；下单事务内扣库存；取消回补；确认收货结算佣金。
 * 佣金比例读 sys_config `trade.commission_rate`（默认 0.06）。
 */
class OrderService
{
    private const DEFAULT_COMMISSION_RATE = '0.06';

    /**
     * 结算预览：按店铺分组算金额（不落库）。
     *
     * @param array<string,mixed> $in items:[{productId,skuId?,qty}] 或 fromCart:true
     * @return array{shops:array<int,array>, totalAmount:string}
     */
    public function preview(int $userId, array $in): array
    {
        $lines = $this->resolveLines($userId, $in);
        if ($lines === []) {
            throw new BizException(ErrorCode::CART_EMPTY);
        }

        $groups = $this->groupByShop($lines);
        $shops = [];
        $grandTotal = '0.00';
        foreach ($groups as $shopId => $items) {
            $shop = Shop::findOne(['id' => $shopId]);
            $subtotal = '0.00';
            $itemViews = [];
            foreach ($items as $ln) {
                $lineTotal = bcmul($ln['price'], (string) $ln['qty'], 2);
                $subtotal = bcadd($subtotal, $lineTotal, 2);
                $itemViews[] = [
                    'productId' => $ln['productId'],
                    'skuId' => $ln['skuId'],
                    'title' => $ln['title'],
                    'cover' => $ln['cover'],
                    'spec' => $ln['spec'],
                    'price' => $ln['price'],
                    'qty' => $ln['qty'],
                ];
            }
            $grandTotal = bcadd($grandTotal, $subtotal, 2);
            $shops[] = [
                'shopId' => (int) $shopId,
                'shopName' => $shop !== null ? $shop->name : '',
                'items' => $itemViews,
                'subtotal' => $subtotal,
                'shipFee' => '0.00',
            ];
        }

        return ['shops' => $shops, 'totalAmount' => $grandTotal];
    }

    /**
     * 创建订单（事务：校验+扣库存+建单+明细+清购物车对应项）。幂等键防重复提交。
     *
     * @param array<string,mixed> $in items 或 fromCart:true；addressId 必填；remark?
     * @return array{orderNos:array<int,string>, totalAmount:string}
     */
    public function create(int $userId, array $in, ?string $idempotencyKey = null): array
    {
        // 幂等：命中则返回上次结果
        $redis = Redis::conn();
        $idemKey = null;
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $idemKey = "hytp:idem:order:{$userId}:{$idempotencyKey}";
            $cached = $redis->get($idemKey);
            if (is_string($cached) && $cached !== '') {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $addressId = (int) ($in['addressId'] ?? 0);
        $address = Address::findOne(['id' => $addressId, 'user_id' => $userId]);
        if ($address === null) {
            throw new BizException(ErrorCode::ADDRESS_REQUIRED);
        }

        $lines = $this->resolveLines($userId, $in);
        if ($lines === []) {
            throw new BizException(ErrorCode::CART_EMPTY);
        }
        $groups = $this->groupByShop($lines);
        $remark = (string) ($in['remark'] ?? '');
        $rate = $this->commissionRate();

        $tx = Yii::$app->db->beginTransaction();
        try {
            $orderNos = [];
            $grandTotal = '0.00';
            $cartIdsToClear = [];

            foreach ($groups as $shopId => $items) {
                $subtotal = '0.00';
                // 扣库存（行级锁：ProductSku/Product 更新计数）
                foreach ($items as $ln) {
                    $this->deductStock($ln['productId'], $ln['skuId'], $ln['qty']);
                    $subtotal = bcadd($subtotal, bcmul($ln['price'], (string) $ln['qty'], 2), 2);
                    if ($ln['cartId'] !== null) {
                        $cartIdsToClear[] = $ln['cartId'];
                    }
                }

                $order = new ShopOrder();
                $order->order_no = $this->genOrderNo();
                $order->user_id = $userId;
                $order->shop_id = (int) $shopId;
                $order->type = ShopOrder::TYPE_BUY;
                $order->total_amount = $subtotal;
                $order->pay_amount = $subtotal;
                $order->commission = bcmul($subtotal, $rate, 2);
                $order->status = ShopOrder::STATUS_UNPAID;
                $order->address_id = $address->getId();
                $order->address_snapshot = $address->toArray();
                $order->remark = $remark;
                if (!$order->save()) {
                    throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($order) ?? '下单失败');
                }

                foreach ($items as $ln) {
                    $item = new OrderItem();
                    $item->order_id = $order->getId();
                    $item->product_id = $ln['productId'];
                    $item->sku_id = $ln['skuId'];
                    $item->title_snapshot = $ln['title'];
                    $item->spec_snapshot = $ln['spec'];
                    $item->price = $ln['price'];
                    $item->qty = $ln['qty'];
                    $item->image_snapshot = $ln['cover'];
                    if (!$item->save()) {
                        throw new BizException(ErrorCode::PARAM_INVALID, '订单明细保存失败');
                    }
                }

                $orderNos[] = $order->order_no;
                $grandTotal = bcadd($grandTotal, $subtotal, 2);
            }

            // 清购物车对应项
            if ($cartIdsToClear !== []) {
                Cart::deleteAll(['id' => $cartIdsToClear, 'user_id' => $userId]);
            }

            $tx->commit();

            $result = ['orderNos' => $orderNos, 'totalAmount' => $grandTotal];
            if ($idemKey !== null) {
                $redis->set($idemKey, json_encode($result), 'EX', 300);
            }
            return $result;
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    /**
     * 用户订单列表（?type=&status= 分页）。
     *
     * @param array<string,mixed> $in
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function listByUser(int $userId, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = ShopOrder::find()->where(['user_id' => $userId]);
        if (!empty($in['type'])) {
            $query->andWhere(['type' => (int) $in['type']]);
        }
        if (isset($in['status']) && $in['status'] !== '') {
            $query->andWhere(['status' => (int) $in['status']]);
        }

        $total = (int) $query->count();
        $rows = $query->orderBy(['id' => SORT_DESC])
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->all();

        $list = [];
        foreach ($rows as $row) {
            /** @var ShopOrder $row */
            $view = $row->toListArray();
            $view['shopName'] = $this->shopName((int) $row->shop_id);
            $view['items'] = $this->itemViews($row->getId());
            $list[] = $view;
        }

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 订单详情（含明细）。
     */
    public function detail(int $userId, string $orderNo): array
    {
        $order = $this->requireOwnOrder($userId, $orderNo);
        $view = $order->toDetailArray();
        $view['shopName'] = $this->shopName((int) $order->shop_id);
        $view['items'] = $this->itemViews($order->getId());
        return $view;
    }

    /**
     * 取消订单（未发货前，回补库存）。
     */
    public function cancel(int $userId, string $orderNo): array
    {
        $order = $this->requireOwnOrder($userId, $orderNo);
        if (!$order->isCancellable()) {
            throw new BizException(ErrorCode::ORDER_STATUS_INVALID);
        }

        $tx = Yii::$app->db->beginTransaction();
        try {
            // 回补库存
            foreach (OrderItem::find()->where(['order_id' => $order->getId()])->all() as $item) {
                /** @var OrderItem $item */
                $this->restoreStock((int) $item->product_id, $item->sku_id !== null ? (int) $item->sku_id : null, (int) $item->qty);
            }
            $order->status = ShopOrder::STATUS_CANCELLED;
            $order->save(false);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        return $order->toDetailArray();
    }

    /**
     * 确认收货（置已完成 + 累加销量 + 结算佣金已在下单时计好）。
     */
    public function confirm(int $userId, string $orderNo): array
    {
        $order = $this->requireOwnOrder($userId, $orderNo);
        if (!$order->isConfirmable()) {
            throw new BizException(ErrorCode::ORDER_STATUS_INVALID);
        }

        $tx = Yii::$app->db->beginTransaction();
        try {
            foreach (OrderItem::find()->where(['order_id' => $order->getId()])->all() as $item) {
                /** @var OrderItem $item */
                Product::updateAllCounters(['sales' => (int) $item->qty], ['id' => $item->product_id]);
            }
            $order->status = ShopOrder::STATUS_FINISHED;
            $order->finished_at = time();
            $order->save(false);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        return $order->toDetailArray();
    }

    /**
     * 申请售后/退款（已支付且未完成售后的订单）。
     *
     * @param array<string,mixed> $in reason, amount?, evidence?
     */
    public function applyRefund(int $userId, string $orderNo, array $in): array
    {
        $order = $this->requireOwnOrder($userId, $orderNo);
        // 已支付（待发货/待收货/已完成）才可申请；待付款直接取消即可
        if (!in_array((int) $order->status, [
            ShopOrder::STATUS_UNSHIP, ShopOrder::STATUS_SHIPPED, ShopOrder::STATUS_FINISHED,
        ], true)) {
            throw new BizException(ErrorCode::ORDER_STATUS_INVALID);
        }

        $existing = \common\models\OrderRefund::findOne([
            'order_id' => $order->getId(),
            'status' => \common\models\OrderRefund::STATUS_APPLYING,
        ]);
        if ($existing !== null) {
            throw new BizException(ErrorCode::REFUND_STATUS_INVALID, '已有进行中的售后申请');
        }

        $refund = new \common\models\OrderRefund();
        $refund->order_id = $order->getId();
        $refund->user_id = $userId;
        $refund->reason = (string) ($in['reason'] ?? '');
        $refund->amount = isset($in['amount']) && is_numeric($in['amount'])
            ? (string) $in['amount']
            : $order->pay_amount;
        $refund->evidence = isset($in['evidence']) && is_array($in['evidence']) ? $in['evidence'] : [];
        $refund->status = \common\models\OrderRefund::STATUS_APPLYING;

        $tx = Yii::$app->db->beginTransaction();
        try {
            if (!$refund->save()) {
                throw new BizException(ErrorCode::PARAM_INVALID, '售后申请失败');
            }
            $order->status = ShopOrder::STATUS_REFUND;
            $order->save(false);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        return $refund->toArray();
    }

    /**
     * 租赁下单：租金(日租金×天数) + 押金合并支付。单商品单店铺。
     * 复用 buildLine 校验商品在售 + SKU 归属；事务扣库存。
     *
     * @param array<string,mixed> $in productId, skuId?, addressId, rentStart, rentEnd, depositAmount, remark?
     * @return array{orderNo:string, totalAmount:string, depositAmount:string, payAmount:string}
     */
    public function createRent(int $userId, array $in): array
    {
        $addressId = (int) ($in['addressId'] ?? 0);
        $address = Address::findOne(['id' => $addressId, 'user_id' => $userId]);
        if ($address === null) {
            throw new BizException(ErrorCode::ADDRESS_REQUIRED);
        }

        $rentStart = (int) ($in['rentStart'] ?? 0);
        $rentEnd = (int) ($in['rentEnd'] ?? 0);
        if ($rentStart <= 0 || $rentEnd <= 0 || $rentEnd <= $rentStart) {
            throw new BizException(ErrorCode::RENT_PARAM_INVALID, '租期不合法');
        }
        $days = (int) ceil(($rentEnd - $rentStart) / 86400);
        if ($days < 1) {
            throw new BizException(ErrorCode::RENT_PARAM_INVALID, '租期至少 1 天');
        }

        $productId = (int) ($in['productId'] ?? 0);
        $skuId = isset($in['skuId']) && $in['skuId'] !== '' ? (int) $in['skuId'] : null;
        $line = $this->buildLine($productId, $skuId, 1, null);
        if ($line === null) {
            throw new BizException(ErrorCode::CART_ITEM_INVALID, '商品不可租赁');
        }

        $product = Product::findOne(['id' => $productId]);
        if ($product === null || (int) $product->trade_type !== Product::TRADE_RENT) {
            throw new BizException(ErrorCode::RENT_PARAM_INVALID, '该商品非租赁商品');
        }

        // 日租金 = 商品 price；租金 = 日租金 × 天数
        $rentTotal = bcmul($line['price'], (string) $days, 2);
        $deposit = isset($in['depositAmount']) && is_numeric($in['depositAmount'])
            ? bcadd((string) $in['depositAmount'], '0', 2)
            : '0.00';
        $payAmount = bcadd($rentTotal, $deposit, 2);
        $rate = $this->commissionRate();

        $tx = Yii::$app->db->beginTransaction();
        try {
            $this->deductStock($productId, $skuId, 1);

            $order = new ShopOrder();
            $order->order_no = $this->genOrderNo();
            $order->user_id = $userId;
            $order->shop_id = $line['shopId'];
            $order->type = ShopOrder::TYPE_RENT;
            $order->total_amount = $rentTotal;
            $order->deposit_amount = $deposit;
            $order->pay_amount = $payAmount;
            $order->commission = bcmul($rentTotal, $rate, 2);
            $order->status = ShopOrder::STATUS_UNPAID;
            $order->rent_start = $rentStart;
            $order->rent_end = $rentEnd;
            $order->address_id = $address->getId();
            $order->address_snapshot = $address->toArray();
            $order->remark = (string) ($in['remark'] ?? '');
            if (!$order->save()) {
                throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($order) ?? '租赁下单失败');
            }

            $item = new OrderItem();
            $item->order_id = $order->getId();
            $item->product_id = $productId;
            $item->sku_id = $skuId;
            $item->title_snapshot = $line['title'];
            $item->spec_snapshot = $line['spec'];
            $item->price = $line['price'];
            $item->qty = 1;
            $item->image_snapshot = $line['cover'];
            if (!$item->save()) {
                throw new BizException(ErrorCode::PARAM_INVALID, '订单明细保存失败');
            }

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        return [
            'orderNo' => $order->order_no,
            'totalAmount' => $rentTotal,
            'depositAmount' => $deposit,
            'payAmount' => $payAmount,
        ];
    }

    /**
     * 租赁：用户寄回（使用中 → 待归还）。
     */
    public function markReturn(int $userId, string $orderNo): array
    {
        $order = $this->requireOwnOrder($userId, $orderNo);
        if (!$order->isReturnable()) {
            throw new BizException(ErrorCode::ORDER_STATUS_INVALID);
        }
        $order->status = ShopOrder::STATUS_TO_RETURN;
        $order->save(false);
        return $order->toDetailArray();
    }

    /**
     * 取当前用户的订单，不存在或越权抛错。
     */
    public function requireOwnOrder(int $userId, string $orderNo): ShopOrder
    {
        $order = ShopOrder::findOne(['order_no' => $orderNo]);
        if ($order === null) {
            throw new BizException(ErrorCode::ORDER_NOT_FOUND);
        }
        if ((int) $order->user_id !== $userId) {
            throw new BizException(ErrorCode::FORBIDDEN);
        }
        return $order;
    }

    // ---------------- 内部 ----------------

    /**
     * 把入参解析成标准下单行：来自购物车或直接传 items。
     *
     * @param array<string,mixed> $in
     * @return array<int,array{cartId:?int,productId:int,skuId:?int,qty:int,title:string,cover:string,spec:array,price:string,shopId:int}>
     */
    private function resolveLines(int $userId, array $in): array
    {
        $lines = [];

        if (!empty($in['fromCart'])) {
            // 从购物车结算，可选 cartIds 子集
            $query = Cart::find()->where(['user_id' => $userId]);
            if (!empty($in['cartIds']) && is_array($in['cartIds'])) {
                $ids = array_map('intval', $in['cartIds']);
                $query->andWhere(['id' => $ids]);
            }
            foreach ($query->all() as $c) {
                /** @var Cart $c */
                $ln = $this->buildLine(
                    (int) $c->product_id,
                    $c->sku_id !== null ? (int) $c->sku_id : null,
                    (int) $c->qty,
                    $c->getId()
                );
                if ($ln !== null) {
                    $lines[] = $ln;
                }
            }
            return $lines;
        }

        // 直接下单（立即购买）
        $items = $in['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $ln = $this->buildLine(
                (int) ($it['productId'] ?? 0),
                isset($it['skuId']) && $it['skuId'] !== '' ? (int) $it['skuId'] : null,
                max(1, (int) ($it['qty'] ?? 1)),
                null
            );
            if ($ln === null) {
                throw new BizException(ErrorCode::CART_ITEM_INVALID);
            }
            $lines[] = $ln;
        }
        return $lines;
    }

    /**
     * 构造并校验单行（商品在售、商家正常、SKU 归属）。失效返回 null（购物车忽略、直购抛错）。
     *
     * @return array{cartId:?int,productId:int,skuId:?int,qty:int,title:string,cover:string,spec:array,price:string,shopId:int}|null
     */
    private function buildLine(int $productId, ?int $skuId, int $qty, ?int $cartId): ?array
    {
        $product = Product::findOne(['id' => $productId]);
        if ($product === null || (int) $product->status !== Product::STATUS_ON) {
            return null;
        }
        $shop = Shop::findOne(['id' => $product->shop_id]);
        if ($shop === null || (int) $shop->status !== Shop::STATUS_ACTIVE) {
            return null;
        }

        $price = $product->price;
        $spec = [];
        if ($skuId !== null) {
            $sku = ProductSku::findOne(['id' => $skuId, 'product_id' => $productId]);
            if ($sku === null) {
                return null;
            }
            $price = $sku->price;
            $spec = $sku->spec_json ?? [];
        }

        return [
            'cartId' => $cartId,
            'productId' => $productId,
            'skuId' => $skuId,
            'qty' => $qty,
            'title' => $product->title,
            'cover' => $product->cover,
            'spec' => $spec,
            'price' => $price,
            'shopId' => (int) $product->shop_id,
        ];
    }

    /**
     * 按店铺分组。
     *
     * @param array<int,array> $lines
     * @return array<int,array<int,array>>
     */
    private function groupByShop(array $lines): array
    {
        $groups = [];
        foreach ($lines as $ln) {
            $groups[$ln['shopId']][] = $ln;
        }
        return $groups;
    }

    /**
     * 扣库存（原子条件更新，防超卖）。SKU 优先，否则扣商品主库存。
     */
    private function deductStock(int $productId, ?int $skuId, int $qty): void
    {
        if ($skuId !== null) {
            $affected = ProductSku::updateAllCounters(
                ['stock' => -$qty],
                ['and', ['id' => $skuId, 'product_id' => $productId], ['>=', 'stock', $qty]]
            );
        } else {
            $affected = Product::updateAllCounters(
                ['stock' => -$qty],
                ['and', ['id' => $productId], ['>=', 'stock', $qty]]
            );
        }
        if ($affected < 1) {
            throw new BizException(ErrorCode::STOCK_NOT_ENOUGH);
        }
    }

    /**
     * 回补库存。
     */
    private function restoreStock(int $productId, ?int $skuId, int $qty): void
    {
        if ($skuId !== null) {
            ProductSku::updateAllCounters(['stock' => $qty], ['id' => $skuId]);
        } else {
            Product::updateAllCounters(['stock' => $qty], ['id' => $productId]);
        }
    }

    private function commissionRate(): string
    {
        $rate = \common\models\SysConfig::get('trade.commission_rate', self::DEFAULT_COMMISSION_RATE);
        return $rate !== null && is_numeric($rate) ? $rate : self::DEFAULT_COMMISSION_RATE;
    }

    private function genOrderNo(): string
    {
        // 时间 + 随机，32 位内
        return date('YmdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<int,array>
     */
    private function itemViews(int $orderId): array
    {
        $items = OrderItem::find()->where(['order_id' => $orderId])->all();
        return array_map(static fn (OrderItem $i): array => $i->toArray(), $items);
    }

    private function shopName(int $shopId): string
    {
        $shop = Shop::findOne(['id' => $shopId]);
        return $shop !== null ? $shop->name : '';
    }

    private function firstError(ShopOrder $order): ?string
    {
        foreach ($order->getFirstErrors() as $msg) {
            return $msg;
        }
        return null;
    }
}
