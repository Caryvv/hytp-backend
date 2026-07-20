<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\CartService;
use common\services\JwtService;
use Yii;

/**
 * 购物车（需登录）。
 */
class CartController extends ApiController
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

    /** GET /cart —— 购物车列表 */
    public function actionIndex(): array
    {
        return (new CartService())->list($this->currentUser()->getId());
    }

    /** POST /cart —— 加入购物车 { productId, skuId?, qty? } */
    public function actionAdd(): array
    {
        $req = Yii::$app->request;
        return (new CartService())->add($this->currentUser()->getId(), [
            'productId' => $req->post('productId'),
            'skuId' => $req->post('skuId'),
            'qty' => $req->post('qty'),
        ]);
    }

    /** PUT /cart/{id} —— 修改数量 { qty } */
    public function actionUpdate(int $id): array
    {
        $qty = (int) Yii::$app->request->post('qty', 1);
        return (new CartService())->updateQty($this->currentUser()->getId(), $id, $qty);
    }

    /** DELETE /cart/{id} —— 删除单项 */
    public function actionDelete(int $id): array
    {
        (new CartService())->remove($this->currentUser()->getId(), $id);
        return ['deleted' => true];
    }

    /** DELETE /cart —— 清空 */
    public function actionClear(): array
    {
        (new CartService())->clear($this->currentUser()->getId());
        return ['cleared' => true];
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
