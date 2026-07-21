<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\OrderItem;
use common\models\OrderRefund;
use common\models\Payment;
use common\models\Product;
use common\models\ProductSku;
use common\models\ShopOrder;
use Yii;

/**
 * 商家端订单管理（需登录，aud=merchant）。
 *
 * 所有操作限定本店 shop_id 归属（照 ProductService::ownedProduct 模式）。
 * 发货：待发货(1)→待收货(2)+shipped_at+物流。售后处理：同意/拒绝申请中的售后单。
 */
class MerchantOrderService
{
    /**
     * 本店订单列表（?status=&page=）。
     *
     * @param array<string,mixed> $in
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function listByShop(int $shopId, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = ShopOrder::find()->where(['shop_id' => $shopId]);
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
            $view['items'] = $this->itemViews($row->getId());
            $list[] = $view;
        }

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 本店订单详情（含明细 + 售后信息）。
     */
    public function detail(int $shopId, string $orderNo): array
    {
        $order = $this->ownedOrder($shopId, $orderNo);
        $view = $order->toDetailArray();
        $view['items'] = $this->itemViews($order->getId());
        $refund = OrderRefund::find()->where(['order_id' => $order->getId()])->orderBy(['id' => SORT_DESC])->one();
        $view['refund'] = $refund !== null ? $refund->toArray() : null;
        return $view;
    }

    /**
     * 发货：待发货 → 待收货，记物流。
     *
     * @param array<string,mixed> $in expressCompany, expressNo
     */
    public function ship(int $shopId, string $orderNo, array $in): array
    {
        $order = $this->ownedOrder($shopId, $orderNo);
        if (!$order->isShippable()) {
            throw new BizException(ErrorCode::ORDER_STATUS_INVALID);
        }
        // 租赁单发货后进"使用中"，购买单进"待收货"
        $order->status = $order->isRent() ? ShopOrder::STATUS_IN_USE : ShopOrder::STATUS_SHIPPED;
        $order->shipped_at = time();
        $order->express_company = (string) ($in['expressCompany'] ?? '');
        $order->express_no = (string) ($in['expressNo'] ?? '');
        $order->save(false);
        return $order->toDetailArray();
    }

    /**
     * 租赁：商家确认收到归还（待归还 → 已归还 → 退押金 Mock → 已完成）。
     */
    public function confirmReturn(int $shopId, string $orderNo): array
    {
        $order = $this->ownedOrder($shopId, $orderNo);
        if (!$order->isReturnConfirmable()) {
            throw new BizException(ErrorCode::ORDER_STATUS_INVALID);
        }

        $tx = ShopOrder::getDb()->beginTransaction();
        try {
            $now = time();
            $order->status = ShopOrder::STATUS_FINISHED;
            $order->returned_at = $now;
            $order->finished_at = $now;
            // 退押金（Mock）：标记已退 + 累加商品销量
            if (bccomp($order->deposit_amount, '0', 2) > 0) {
                $order->deposit_refunded = 1;
            }
            $order->save(false);
            foreach (OrderItem::find()->where(['order_id' => $order->getId()])->all() as $item) {
                /** @var OrderItem $item */
                Product::updateAllCounters(['sales' => (int) $item->qty], ['id' => $item->product_id]);
                // 归还后库存回补（租赁品可再租）
                $this->restoreStock((int) $item->product_id, $item->sku_id !== null ? (int) $item->sku_id : null, (int) $item->qty);
            }
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        return $order->toDetailArray();
    }

    /**
     * 本店售后列表（?status=&page=）。经 order 关联本店。
     *
     * @param array<string,mixed> $in
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function listRefunds(int $shopId, array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        // 本店订单 id 子查询
        $orderIds = ShopOrder::find()->select('id')->where(['shop_id' => $shopId]);
        $query = OrderRefund::find()->where(['order_id' => $orderIds]);
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
            /** @var OrderRefund $row */
            $view = $row->toArray();
            $order = ShopOrder::findOne(['id' => $row->order_id]);
            $view['orderNo'] = $order !== null ? $order->order_no : '';
            $list[] = $view;
        }

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 商家处理售后：同意（退款）或拒绝。
     * 同意：refund→已完成、payment→已退款、订单保持 STATUS_REFUND（终态）。
     * 拒绝：refund→已拒绝，订单回退到售后前的合理状态（简化为已完成）。
     *
     * @param array<string,mixed> $in agree(bool), remark
     */
    public function handleRefund(int $shopId, int $refundId, array $in): array
    {
        $refund = OrderRefund::findOne(['id' => $refundId]);
        if ($refund === null) {
            throw new BizException(ErrorCode::REFUND_NOT_FOUND);
        }
        $order = ShopOrder::findOne(['id' => $refund->order_id]);
        if ($order === null) {
            throw new BizException(ErrorCode::ORDER_NOT_FOUND);
        }
        // 归属校验：售后单对应订单必须属于本店
        if ((int) $order->shop_id !== $shopId) {
            throw new BizException(ErrorCode::FORBIDDEN, '无权处理该售后');
        }
        if ((int) $refund->status !== OrderRefund::STATUS_APPLYING) {
            throw new BizException(ErrorCode::REFUND_STATUS_INVALID);
        }

        $agree = (bool) ($in['agree'] ?? false);
        $remark = (string) ($in['remark'] ?? '');

        $tx = ShopOrder::getDb()->beginTransaction();
        try {
            if ($agree) {
                $refund->status = OrderRefund::STATUS_DONE;
                $refund->handle_remark = $remark;
                $refund->save(false);
                // 关联支付单置已退款 + 代币退款回补余额
                $payments = Payment::findAll([
                    'order_id' => $order->getId(),
                    'status' => Payment::STATUS_PAID,
                ]);
                $paymentService = new \common\services\PaymentService();
                foreach ($payments as $p) {
                    $paymentService->refund($p);
                }
                // 订单保持 STATUS_REFUND 作为售后完成终态
            } else {
                $refund->status = OrderRefund::STATUS_REJECTED;
                $refund->handle_remark = $remark;
                $refund->save(false);
                // 驳回后订单回到已完成（用户可再申诉/走管理端仲裁）
                $order->status = ShopOrder::STATUS_FINISHED;
                $order->save(false);
            }
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        return $refund->toArray();
    }

    // ---------------- 内部 ----------------

    /**
     * 取本店订单，不存在抛 ORDER_NOT_FOUND，越权抛 FORBIDDEN。
     */
    private function ownedOrder(int $shopId, string $orderNo): ShopOrder
    {
        $order = ShopOrder::findOne(['order_no' => $orderNo]);
        if ($order === null) {
            throw new BizException(ErrorCode::ORDER_NOT_FOUND);
        }
        if ((int) $order->shop_id !== $shopId) {
            throw new BizException(ErrorCode::FORBIDDEN, '无权操作该订单');
        }
        return $order;
    }

    /**
     * @return array<int,array>
     */
    private function itemViews(int $orderId): array
    {
        $items = OrderItem::find()->where(['order_id' => $orderId])->all();
        return array_map(static fn (OrderItem $i): array => $i->toArray(), $items);
    }

    /**
     * 回补库存（租赁归还后商品可再租）。
     */
    private function restoreStock(int $productId, ?int $skuId, int $qty): void
    {
        if ($skuId !== null) {
            ProductSku::updateAllCounters(['stock' => $qty], ['id' => $skuId]);
        } else {
            Product::updateAllCounters(['stock' => $qty], ['id' => $productId]);
        }
    }
}

