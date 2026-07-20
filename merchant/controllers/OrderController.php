<?php

declare(strict_types=1);

namespace merchant\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Shop;
use common\services\JwtService;
use common\services\MerchantOrderService;
use Yii;

/**
 * 商家端订单管理（需登录 aud=merchant）：本店订单列表/详情/发货 + 售后处理。
 */
class OrderController extends ApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => JwtAuthBehavior::class,
            'aud' => JwtService::AUD_MERCHANT,
            'identityClass' => Shop::class,
        ];
        return $behaviors;
    }

    /** GET /orders —— 本店订单列表 ?status=&page=&pageSize= */
    public function actionIndex(): array
    {
        $req = Yii::$app->request;
        return (new MerchantOrderService())->listByShop($this->currentShop()->getId(), [
            'status' => $req->get('status'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** GET /orders/{orderNo} —— 本店订单详情 */
    public function actionView(string $orderNo): array
    {
        return (new MerchantOrderService())->detail($this->currentShop()->getId(), $orderNo);
    }

    /** POST /orders/{orderNo}/ship —— 发货 { expressCompany, expressNo } */
    public function actionShip(string $orderNo): array
    {
        $req = Yii::$app->request;
        return (new MerchantOrderService())->ship($this->currentShop()->getId(), $orderNo, [
            'expressCompany' => $req->post('expressCompany'),
            'expressNo' => $req->post('expressNo'),
        ]);
    }

    /** GET /refunds —— 本店售后列表 ?status=&page= */
    public function actionRefunds(): array
    {
        $req = Yii::$app->request;
        return (new MerchantOrderService())->listRefunds($this->currentShop()->getId(), [
            'status' => $req->get('status'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** POST /refunds/{id}/handle —— 处理售后 { agree, remark } */
    public function actionHandleRefund(int $id): array
    {
        $req = Yii::$app->request;
        return (new MerchantOrderService())->handleRefund($this->currentShop()->getId(), $id, [
            'agree' => $req->post('agree'),
            'remark' => $req->post('remark'),
        ]);
    }

    private function currentShop(): Shop
    {
        /** @var Shop|null $shop */
        $shop = Yii::$app->user->identity;
        if ($shop === null) {
            throw new BizException(ErrorCode::UNAUTHORIZED);
        }
        return $shop;
    }
}
