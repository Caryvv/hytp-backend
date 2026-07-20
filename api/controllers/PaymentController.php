<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\JwtService;
use common\services\PaymentService;
use Yii;

/**
 * 支付（Mock 通道，需登录）。
 * 真实通道时 mock-confirm 换成 /pay/notify/{channel} 服务端验签回调。
 */
class PaymentController extends ApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => JwtAuthBehavior::class,
            'aud' => JwtService::AUD_APP,
        ];
        return $behaviors;
    }

    /** POST /pay —— 发起支付 { orderNo, channel } → mock 支付参数 */
    public function actionPay(): array
    {
        $req = Yii::$app->request;
        $orderNo = (string) $req->post('orderNo', '');
        $channel = (int) $req->post('channel', 1);
        return (new PaymentService())->pay($this->currentUser()->getId(), $orderNo, $channel);
    }

    /** POST /pay/mock/confirm —— 模拟支付回调改单 { payNo } */
    public function actionMockConfirm(): array
    {
        // 需登录以防他人乱触发；订单归属在 payment→order 已隐含
        $this->currentUser();
        $payNo = (string) Yii::$app->request->post('payNo', '');
        return (new PaymentService())->mockConfirm($payNo);
    }

    private function currentUser(): User
    {
        /** @var User|null $user */
        $user = Yii::$app->user->identity;
        if ($user === null) {
            throw new BizException(ErrorCode::UNAUTHORIZED);
        }
        return $user;
    }
}
