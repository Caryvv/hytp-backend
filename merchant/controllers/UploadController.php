<?php

declare(strict_types=1);

namespace merchant\controllers;

use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Shop;
use common\services\AliyunStsService;
use common\services\JwtService;
use Yii;

/**
 * 商家端文件上传（需登录 aud=merchant）。
 * GET /merchant/upload/sts：换取 OSS 直传临时凭证（shop 命名空间）；未配置返 {enabled:false}。
 */
class UploadController extends \common\base\ApiController
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

    /**
     * GET /merchant/upload/sts —— OSS 直传临时凭证（限本店 shop/{shopId}/ 目录）。
     * 未启用/未配置/STS 失败均返 {enabled:false}。
     */
    public function actionStsToken(): array
    {
        $sts = new AliyunStsService();
        if (!$sts->enabled()) {
            return ['enabled' => false];
        }
        try {
            return $sts->assumeRole($this->currentShop()->getId(), 'shop');
        } catch (\Throwable $e) {
            Yii::warning('商家端 STS 取凭证失败: ' . $e->getMessage(), 'upload');
            return ['enabled' => false];
        }
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
