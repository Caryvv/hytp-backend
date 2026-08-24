<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\enums\ErrorCode;
use common\services\AuthService;
use Yii;

/**
 * 认证：登录/注册合一、刷新、退出、第三方登录（对齐 05 §5）。
 * 全部为白名单接口（无需 accessToken）。
 */
class AuthController extends ApiController
{
    /**
     * POST /auth/login —— 验证码/密码登录，注册合一。
     * 请求：{ phone, code?, password?, loginType(code|password) }
     * 响应：{ accessToken, refreshToken, expiresIn, user, isNewUser }
     */
    public function actionLogin(): array
    {
        $req = Yii::$app->request;
        return (new AuthService())->login([
            'phone' => $req->post('phone'),
            'code' => $req->post('code'),
            'password' => $req->post('password'),
            'loginType' => $req->post('loginType', AuthService::LOGIN_TYPE_CODE),
            'ip' => $req->userIP,
        ]);
    }

    /**
     * POST /auth/refresh —— 用 refreshToken 换新 token 对。
     * 请求：{ refreshToken }
     */
    public function actionRefresh(): array
    {
        $refreshToken = (string) Yii::$app->request->post('refreshToken', '');
        return (new AuthService())->refresh($refreshToken);
    }

    /**
     * POST /auth/logout —— 退出登录，拉黑 refreshToken。
     * 请求：{ refreshToken }
     */
    public function actionLogout(): array
    {
        $refreshToken = (string) Yii::$app->request->post('refreshToken', '');
        (new AuthService())->logout($refreshToken);
        return $this->success(null, '已退出登录');
    }

    /**
     * POST /auth/oauth —— 第三方登录（微信/QQ）。
     * 阶段1 预留占位，接入开放平台凭证后实现。
     */
    public function actionOauth(): array
    {
        return $this->fail(ErrorCode::OAUTH_FAIL, '第三方登录暂未开放');
    }
}
