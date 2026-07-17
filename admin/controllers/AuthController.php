<?php

declare(strict_types=1);

namespace admin\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\models\AdminUser;
use common\services\AdminAuthService;
use common\services\JwtService;
use Yii;

/**
 * 管理端认证（login/refresh/logout 免登录）。
 */
class AuthController extends ApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => JwtAuthBehavior::class,
            'aud' => JwtService::AUD_ADMIN,
            'identityClass' => AdminUser::class,
            'except' => ['login', 'refresh', 'logout'],
        ];
        return $behaviors;
    }

    /** POST /admin/auth/login —— { username, password } */
    public function actionLogin(): array
    {
        $req = Yii::$app->request;
        return (new AdminAuthService())->login([
            'username' => $req->post('username'),
            'password' => $req->post('password'),
        ]);
    }

    /** POST /admin/auth/refresh —— { refreshToken } */
    public function actionRefresh(): array
    {
        return (new AdminAuthService())->refresh((string) Yii::$app->request->post('refreshToken', ''));
    }

    /** POST /admin/auth/logout —— { refreshToken } */
    public function actionLogout(): array
    {
        (new AdminAuthService())->logout((string) Yii::$app->request->post('refreshToken', ''));
        return ['success' => true];
    }
}
