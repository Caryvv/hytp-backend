<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Payment;
use common\models\ShopOrder;
use common\models\User;
use common\models\WalletTransaction;
use Yii;

/**
 * 支付服务。
 *
 * 通道：
 *   CHANNEL_COIN   = 1  代币支付（从 user.balance 扣款）
 *   CHANNEL_WECHAT = 2  微信支付（预留，待接入 SDK）
 *   CHANNEL_ALIPAY = 3  支付宝（预留，待接入 SDK）
 *
 * 流程：
 *   pay()   → 通道=1 扣代币余额→创建支付单(已付)→推进订单状态
 *           → 通道≠1 payReal() → 返回第三方支付参数（预留）
 *   confirm() → 幂等确认（通道=1 直接返回；通道≠1 模拟/sdk 回调）
 */
class PaymentService
{
    /**
     * 发起支付。
     * 通道 1（代币）：直接从用户余额扣款，创建已付支付单，推进订单。
     * 通道 2/3（预留）：调用第三方 SDK 创建预支付单，返回支付参数。
     *
     * @return array{payNo:string, orderNo:string, amount:string, channel:int, channelText:string, balanceBefore?:string, balanceAfter?:string, mock?:bool}
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

        // 通道默认值
        if (!in_array($channel, [Payment::CHANNEL_COIN, Payment::CHANNEL_WECHAT, Payment::CHANNEL_ALIPAY], true)) {
            $channel = Payment::CHANNEL_COIN;
        }

        if ($channel === Payment::CHANNEL_COIN) {
            return $this->payByCoin($userId, $order);
        }

        return $this->payReal($userId, $order, $channel);
    }

    /**
     * 代币支付：扣余额 → 建已付支付单 → 推进订单。
     */
    private function payByCoin(int $userId, ShopOrder $order): array
    {
        $amount = (string) $order->pay_amount;

        // 原子扣余额
        $affected = User::updateAllCounters(
            ['balance' => '-' . $amount],
            ['and', ['id' => $userId], ['>=', 'balance', $amount]],
        );
        if ($affected < 1) {
            $user = User::findOne(['id' => $userId]);
            $currentBalance = $user !== null ? $user->balance : '0.00';
            throw new BizException(
                ErrorCode::BALANCE_NOT_ENOUGH,
                '代币余额不足，当前余额 ' . $currentBalance . '，需支付 ' . $amount
            );
        }

        $tx = ShopOrder::getDb()->beginTransaction();
        try {
            $now = time();

            $payment = new Payment();
            $payment->pay_no = $this->genPayNo();
            $payment->order_id = $order->getId();
            $payment->amount = $amount;
            $payment->channel = Payment::CHANNEL_COIN;
            $payment->status = Payment::STATUS_PAID;
            $payment->trade_no = 'COIN' . $payment->pay_no;
            $payment->notify_at = $now;
            if (!$payment->save()) {
                // 扣了余额但支付单写失败 → 回退余额
                throw new BizException(ErrorCode::PAY_FAIL, '支付单创建失败');
            }

            // 推进订单状态
            if ((int) $order->status === ShopOrder::STATUS_UNPAID) {
                $order->status = ShopOrder::STATUS_UNSHIP;
                $order->paid_at = $now;
                $order->save(false);
            }

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            // 事务失败 → 回退余额
            if ($e instanceof BizException && $e->getCode() === ErrorCode::PAY_FAIL) {
                User::updateAllCounters(['balance' => $amount], ['id' => $userId]);
            }
            throw $e;
        }

        // 重新查询最新余额
        $user = User::findOne(['id' => $userId]);
        $balanceAfter = $user !== null ? $user->balance : '0.00';

        // 记同袍币消费流水（余额已在上方扣减，这里只记账）
        (new WalletService())->log(
            $userId,
            WalletTransaction::TYPE_CONSUME,
            -WalletService::yuanToCoin($amount),
            WalletService::yuanToCoin($balanceAfter),
            ['channel' => Payment::CHANNEL_COIN, 'refType' => 'order', 'refId' => (string) $order->getId(), 'remark' => '订单支付'],
        );
        (new TaskService())->award($userId, TaskService::TASK_FIRST_ORDER); // 首单奖励（一次性），吞异常不影响支付

        return [
            'payNo' => $payment->pay_no,
            'orderNo' => $order->order_no,
            'amount' => $amount,
            'channel' => Payment::CHANNEL_COIN,
            'channelText' => '代币',
            'balanceBefore' => bcadd($balanceAfter, $amount, 2),
            'balanceAfter' => $balanceAfter,
        ];
    }

    /**
     * 真实支付通道（预留：微信/支付宝 SDK 接入点）。
     * 当前返回 Mock 参数，后续替换 createOrder + 签名等逻辑。
     */
    private function payReal(int $userId, ShopOrder $order, int $channel): array
    {
        // TODO: 接入微信/支付宝 SDK
        // 1. 根据 $channel 选择 SDK 实例
        // 2. 调用 SDK::createOrder($order->pay_amount, $order->order_no, ...)
        // 3. 返回客户端调起支付所需的签名参数

        // 当前 Mock：建待支付单，返回 mock 参数
        $payment = Payment::findOne(['order_id' => $order->getId(), 'status' => Payment::STATUS_PENDING]);
        if ($payment === null) {
            $payment = new Payment();
            $payment->pay_no = $this->genPayNo();
            $payment->order_id = $order->getId();
            $payment->amount = (string) $order->pay_amount;
        }
        $payment->channel = $channel;
        if (!$payment->save()) {
            throw new BizException(ErrorCode::PAY_FAIL);
        }

        return [
            'payNo' => $payment->pay_no,
            'orderNo' => $order->order_no,
            'amount' => $payment->amount,
            'channel' => $channel,
            'channelText' => $channel === Payment::CHANNEL_WECHAT ? '微信支付' : '支付宝',
            'mock' => true,
        ];
    }

    /**
     * 支付确认（Mock 通道回调 / 代币支付幂等查询）。
     * 代币支付在 pay() 时已完成扣款与改单，此处仅做幂等返回。
     * 真实通道时此方法由服务端 notify 调用（验签 + 改单）。
     *
     * @return array{orderNo:string, status:int, paid:bool}
     */
    public function confirm(string $payNo): array
    {
        $payment = Payment::findOne(['pay_no' => $payNo]);
        if ($payment === null) {
            throw new BizException(ErrorCode::PAY_ORDER_NOT_FOUND);
        }
        $order = ShopOrder::findOne(['id' => $payment->order_id]);
        if ($order === null) {
            throw new BizException(ErrorCode::ORDER_NOT_FOUND);
        }

        // 已支付：幂等返回
        if ((int) $payment->status === Payment::STATUS_PAID) {
            return ['orderNo' => $order->order_no, 'status' => (int) $order->status, 'paid' => true];
        }

        // 未支付（真实通道）：标记为已付 + 推进订单
        // TODO: 真实通道需在此处验签（微信/支付宝回调签名）
        $tx = ShopOrder::getDb()->beginTransaction();
        try {
            $now = time();
            $payment->status = Payment::STATUS_PAID;
            $payment->notify_at = $now;
            if (empty($payment->trade_no)) {
                $payment->trade_no = 'MOCK' . $payNo;
            }
            $payment->save(false);

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
        (new TaskService())->award((int) $order->user_id, TaskService::TASK_FIRST_ORDER); // 首单奖励（一次性），吞异常不影响回调

        return ['orderNo' => $order->order_no, 'status' => (int) $order->status, 'paid' => true];
    }

    /**
     * 退款（回退代币余额 + 支付单置已退款）。
     * 仅代币支付(CHANNEL_COIN)支持自动退款，真实通道退款走人工/定时对账。
     */
    public function refund(Payment $payment, ?string $amount = null): void
    {
        $refundAmount = $amount ?? $payment->amount;
        $order = ShopOrder::findOne(['id' => $payment->order_id]);
        $userId = $order !== null ? (int) $order->user_id : null;

        if ((int) $payment->channel === Payment::CHANNEL_COIN && $userId !== null) {
            User::updateAllCounters(['balance' => $refundAmount], ['id' => $userId]);
            // 记同袍币退款流水
            $wallet = new WalletService();
            $wallet->log(
                $userId,
                WalletTransaction::TYPE_REFUND,
                WalletService::yuanToCoin($refundAmount),
                $wallet->currentCoin($userId),
                ['channel' => Payment::CHANNEL_COIN, 'refType' => 'order', 'refId' => (string) $payment->order_id, 'remark' => '订单退款'],
            );
        }
        // 真实通道退款：预留，走 SDK 退款接口
        // TODO: 微信/支付宝退款

        $payment->status = Payment::STATUS_REFUNDED;
        $payment->save(false);
    }

    private function genPayNo(): string
    {
        return 'P' . date('YmdHis') . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
