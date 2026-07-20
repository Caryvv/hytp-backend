<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\JwtService;
use common\services\OrderService;
use common\services\ReviewService;
use Yii;

/**
 * 订单（需登录）：结算预览、下单、列表、详情、取消、确认收货、售后、评价。
 */
class OrderController extends ApiController
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

    /** POST /orders/preview —— 结算预览 { fromCart?, cartIds?, items? } */
    public function actionPreview(): array
    {
        $req = Yii::$app->request;
        return (new OrderService())->preview($this->currentUser()->getId(), [
            'fromCart' => $req->post('fromCart'),
            'cartIds' => $req->post('cartIds'),
            'items' => $req->post('items'),
        ]);
    }

    /** POST /orders —— 创建订单（幂等：Idempotency-Key 头）{ addressId, fromCart?, cartIds?, items?, remark? } */
    public function actionCreate(): array
    {
        $req = Yii::$app->request;
        $idempotencyKey = $req->headers->get('Idempotency-Key');
        return (new OrderService())->create($this->currentUser()->getId(), [
            'addressId' => $req->post('addressId'),
            'fromCart' => $req->post('fromCart'),
            'cartIds' => $req->post('cartIds'),
            'items' => $req->post('items'),
            'remark' => $req->post('remark'),
        ], is_string($idempotencyKey) ? $idempotencyKey : null);
    }

    /** GET /orders —— 订单列表 ?type=&status=&page=&pageSize= */
    public function actionIndex(): array
    {
        $req = Yii::$app->request;
        return (new OrderService())->listByUser($this->currentUser()->getId(), [
            'type' => $req->get('type'),
            'status' => $req->get('status'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** GET /orders/{orderNo} —— 订单详情 */
    public function actionView(string $orderNo): array
    {
        return (new OrderService())->detail($this->currentUser()->getId(), $orderNo);
    }

    /** POST /orders/{orderNo}/cancel —— 取消 */
    public function actionCancel(string $orderNo): array
    {
        return (new OrderService())->cancel($this->currentUser()->getId(), $orderNo);
    }

    /** POST /orders/{orderNo}/confirm —— 确认收货 */
    public function actionConfirm(string $orderNo): array
    {
        return (new OrderService())->confirm($this->currentUser()->getId(), $orderNo);
    }

    /** POST /orders/{orderNo}/refund —— 申请售后 { reason, amount?, evidence? } */
    public function actionRefund(string $orderNo): array
    {
        $req = Yii::$app->request;
        return (new OrderService())->applyRefund($this->currentUser()->getId(), $orderNo, [
            'reason' => $req->post('reason'),
            'amount' => $req->post('amount'),
            'evidence' => $req->post('evidence'),
        ]);
    }

    /** POST /orders/{orderNo}/review —— 提交评价 { productId, rating, content?, images? } */
    public function actionReview(string $orderNo): array
    {
        $req = Yii::$app->request;
        return (new ReviewService())->submit($this->currentUser()->getId(), $orderNo, [
            'productId' => $req->post('productId'),
            'rating' => $req->post('rating'),
            'content' => $req->post('content'),
            'images' => $req->post('images'),
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
