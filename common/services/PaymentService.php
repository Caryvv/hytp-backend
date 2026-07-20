<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Payment;
use common\models\ShopOrder;
use Yii;

/**
 * 支付（Mock 通道）。
 *
 * 流程镜像真实"服务端回调改单"：
 *   pay()        建 payment(待支付)，返回 mock 支付参数
 *   mockConfirm() 模拟第三方异步回调：置 payment 成功 + 改订单 待付款→待发货（幂等）
 * 真实通道时 mockConfirm 换成 notify(channel) 验签逻辑，其余不变。
 */
class PaymentService
{
    /**
     * 发起支付：为订单创建支付单，返回 mock 支付参数。
     *
     * @return array{payNo:string, orderNo:string, amount:string, channel:int, mock:bool}
     */
    public function pay(int $userId, string $orderNo, int $channel): array
    {
        $order = ShopOrder::findOne(['order_no' => $orderNo]);
        if ($order === null) {
            throw new BizException(ErrorCode::ORDER_NOT_FOUND);
        }
        if ((int) $order->user_id !== $userId) {
            throw new BizException(ErrorCode::FORBIDDEN);
        }
        if (!$order->isPayable()) {
            throw new BizException(ErrorCode::PAY_ALREADY_PAID);
        }

        if (!in_array($channel, [Payment::CHANNEL_WECHAT, Payment::CHANNEL_ALIPAY], true)) {
            $channel = Payment::CHANNEL_WECHAT;
        }

        // 复用该订单未完成的支付单，否则新建
        $payment = Payment::findOne(['order_id' => $order->getId(), 'status' => Payment::STATUS_PENDING]);
        if ($payment === null) {
            $payment = new Payment();
            $payment->pay_no = $this->genPayNo();
            $payment->order_id = $order->getId();
            $payment->amount = $order->pay_amount;
        }
        $payment->channel = $channel;
        if (!$payment->save()) {
            throw new BizException(ErrorCode::PAY_FAIL);
        }

        return [
            'payNo' => $payment->pay_no,
            'orderNo' => $order->order_no,
            'amount' => $payment->amount,
            'channel' => (int) $payment->channel,
            'mock' => true,
        ];
    }

    /**
     * Mock 支付回调：置支付成功 + 改订单为待发货。幂等（重复回调不重复改单）。
     *
     * @return array{orderNo:string, status:int, paid:bool}
     */
    public function mockConfirm(string $payNo): array
    {
        $payment = Payment::findOne(['pay_no' => $payNo]);
        if ($payment === null) {
            throw new BizException(ErrorCode::PAY_ORDER_NOT_FOUND);
        }
        $order = ShopOrder::findOne(['id' => $payment->order_id]);
        if ($order === null) {
            throw new BizException(ErrorCode::ORDER_NOT_FOUND);
        }

        // 幂等：已支付直接返回
        if ((int) $payment->status === Payment::STATUS_PAID) {
            return ['orderNo' => $order->order_no, 'status' => (int) $order->status, 'paid' => true];
        }

        $tx = ShopOrder::getDb()->beginTransaction();
        try {
            $now = time();
            $payment->status = Payment::STATUS_PAID;
            $payment->trade_no = 'MOCK' . $payNo;
            $payment->notify_at = $now;
            $payment->save(false);

            // 仅当订单仍待付款时推进状态（幂等保护）
            if ((int) $order->status === ShopOrder::STATUS_UNPAID) {
                $order->status = ShopOrder::STATUS_UNSHIP;
                $order->paid_at = $now;
                $order->save(false);
            }
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        return ['orderNo' => $order->order_no, 'status' => (int) $order->status, 'paid' => true];
    }

    private function genPayNo(): string
    {
        return 'P' . date('YmdHis') . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
