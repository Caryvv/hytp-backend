<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\OrderItem;
use common\models\OrderRefund;
use common\models\Payment;
use common\models\Shop;
use common\models\ShopOrder;
use Yii;

/**
 * 管理端订单监控 + 售后仲裁（需登录，aud=admin + RBAC）。
 *
 * 全平台视角，无归属限制；权限点校验在 Controller 层 requirePermission。
 */
class AdminOrderService
{
    /**
     * 全平台订单列表（?shopId=&status=&keyword=(订单号)&page=）。
     *
     * @param array<string,mixed> $in
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function list(array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = ShopOrder::find();
        if (!empty($in['shopId'])) {
            $query->andWhere(['shop_id' => (int) $in['shopId']]);
        }
        if (isset($in['status']) && $in['status'] !== '') {
            $query->andWhere(['status' => (int) $in['status']]);
        }
        if (!empty($in['keyword'])) {
            $query->andWhere(['like', 'order_no', (string) $in['keyword']]);
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
            $list[] = $view;
        }

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 订单详情（含明细 + 售后 + 店铺名）。
     */
    public function detail(string $orderNo): array
    {
        $order = ShopOrder::findOne(['order_no' => $orderNo]);
        if ($order === null) {
            throw new BizException(ErrorCode::ORDER_NOT_FOUND);
        }
        $view = $order->toDetailArray();
        $view['shopName'] = $this->shopName((int) $order->shop_id);
        $items = OrderItem::find()->where(['order_id' => $order->getId()])->all();
        $view['items'] = array_map(static fn (OrderItem $i): array => $i->toArray(), $items);
        $refund = OrderRefund::find()->where(['order_id' => $order->getId()])->orderBy(['id' => SORT_DESC])->one();
        $view['refund'] = $refund !== null ? $refund->toArray() : null;
        return $view;
    }

    /**
     * 售后仲裁队列（?status=&page=）。默认看申请中的。
     *
     * @param array<string,mixed> $in
     * @return array{list:array<int,array>, pagination:array{page:int,pageSize:int,total:int}}
     */
    public function listRefunds(array $in): array
    {
        $page = max(1, (int) ($in['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($in['pageSize'] ?? 20)));

        $query = OrderRefund::find();
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
            $view['shopId'] = $order !== null ? (int) $order->shop_id : 0;
            $view['shopName'] = $order !== null ? $this->shopName((int) $order->shop_id) : '';
            $list[] = $view;
        }

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total],
        ];
    }

    /**
     * 售后仲裁：平台强制介入。
     * 支持处理"申请中"或商家"已拒绝"的售后单（用户申诉后平台裁决）。
     * agree=true：退款成立 → refund 已完成 + payment 已退款 + 订单 STATUS_REFUND。
     * agree=false：驳回 → refund 已拒绝 + 订单回已完成。
     *
     * @param array<string,mixed> $in agree(bool), remark
     */
    public function arbitrate(int $refundId, array $in): array
    {
        $refund = OrderRefund::findOne(['id' => $refundId]);
        if ($refund === null) {
            throw new BizException(ErrorCode::REFUND_NOT_FOUND);
        }
        $order = ShopOrder::findOne(['id' => $refund->order_id]);
        if ($order === null) {
            throw new BizException(ErrorCode::ORDER_NOT_FOUND);
        }
        // 已完成/已拒绝可再被平台仲裁改判；仅"已完成的退款"不再动
        if ((int) $refund->status === OrderRefund::STATUS_DONE) {
            throw new BizException(ErrorCode::REFUND_STATUS_INVALID, '该售后已完成退款');
        }

        $agree = (bool) ($in['agree'] ?? false);
        $remark = '[平台仲裁] ' . (string) ($in['remark'] ?? '');

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
                $order->status = ShopOrder::STATUS_REFUND;
                $order->save(false);
            } else {
                $refund->status = OrderRefund::STATUS_REJECTED;
                $refund->handle_remark = $remark;
                $refund->save(false);
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

    private function shopName(int $shopId): string
    {
        $shop = Shop::findOne(['id' => $shopId]);
        return $shop !== null ? $shop->name : '';
    }
}
