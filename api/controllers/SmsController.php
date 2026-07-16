<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\services\SmsService;
use Yii;

/**
 * 短信验证码（白名单接口，无需登录）。
 */
class SmsController extends ApiController
{
    /** POST /sms/send —— 发送验证码 {phone, scene}。 */
    public function actionSend(): array
    {
        $phone = trim((string) Yii::$app->request->post('phone', ''));
        $scene = (string) Yii::$app->request->post('scene', SmsService::SCENE_LOGIN);
        $ip = Yii::$app->request->userIP;

        $result = (new SmsService())->send($phone, $scene, $ip);

        // Mock 模式回带 devCode，方便联调；真实通道返回空 data
        return $this->success($result ?: null, '验证码已发送');
    }
}
