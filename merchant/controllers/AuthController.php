<?php

declare(strict_types=1);

namespace merchant\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\models\Shop;
use common\services\JwtService;
use common\services\MerchantAuthService;
use common\services\ShopService;
use Yii;

/**
 * 商家端认证与入驻（login/register/refresh/logout 免登录）。
 */
class AuthController extends ApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => JwtAuthBehavior::class,
            'aud' => JwtService::AUD_MERCHANT,
            'identityClass' => Shop::class,
            'except' => ['login', 'register', 'refresh', 'logout'],
        ];
        return $behaviors;
    }

    /** POST /merchant/auth/login —— { account, password } */
    public function actionLogin(): array
    {
        $req = Yii::$app->request;
        return (new MerchantAuthService())->login([
            'account' => $req->post('account'),
            'password' => $req->post('password'),
        ]);
    }

    /** POST /merchant/register —— 商家入驻 */
    public function actionRegister(): array
    {
        $req = Yii::$app->request;
        return (new ShopService())->register([
            'account' => $req->post('account'),
            'password' => $req->post('password'),
            'name' => $req->post('name'),
            'type' => $req->post('type'),
            'region' => $req->post('region'),
            'contactName' => $req->post('contactName'),
            'contactPhone' => $req->post('contactPhone'),
        ]);
    }

    /** POST /merchant/auth/refresh —— { refreshToken } */
    public function actionRefresh(): array
    {
        return (new MerchantAuthService())->refresh((string) Yii::$app->request->post('refreshToken', ''));
    }

    /** POST /merchant/auth/logout —— { refreshToken } */
    public function actionLogout(): array
    {
        (new MerchantAuthService())->logout((string) Yii::$app->request->post('refreshToken', ''));
        return ['success' => true];
    }
}
