<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\JwtService;
use common\services\WalletService;
use Yii;

/**
 * 同袍币钱包（充值，需登录）。
 * Mock 通道：recharge 直接到账。真实通道时 recharge 返回支付参数，
 * 由 /wallet/recharge/notify 服务端验签回调改为 rechargeConfirm 到账。
 */
class WalletController extends ApiController
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

    /** POST /wallet/recharge —— 充值 { coin } 同袍币 → Mock 直接到账 */
    public function actionRecharge(): array
    {
        $coin = (int) Yii::$app->request->post('coin', 0);
        return (new WalletService())->recharge($this->currentUser()->getId(), $coin);
    }

    /** POST /wallet/recharge/mock/confirm —— 充值确认（Mock 幂等；真实通道回调） */
    public function actionMockConfirm(): array
    {
        $rechargeNo = (string) Yii::$app->request->post('rechargeNo', '');
        return (new WalletService())->rechargeConfirm($this->currentUser()->getId(), $rechargeNo);
    }

    /** POST /wallet/withdraw —— 提现 { coin } 同袍币 → Mock 即时扣减，余额不足抛 BALANCE_NOT_ENOUGH */
    public function actionWithdraw(): array
    {
        $coin = (int) Yii::$app->request->post('coin', 0);
        return (new WalletService())->withdraw($this->currentUser()->getId(), $coin);
    }

    /** GET /wallet/transactions —— 钱包流水（充值/消费/退款/提现等，倒序分页） */
    public function actionTransactions(): array
    {
        $req = Yii::$app->request;
        return (new WalletService())->transactions($this->currentUser()->getId(), [
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
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
