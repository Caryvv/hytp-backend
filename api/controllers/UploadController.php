<?php

declare(strict_types=1);

namespace api\controllers;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\AliyunStsService;
use common\services\UploadService;
use Yii;
use yii\web\UploadedFile;

/**
 * 文件上传（需登录）。
 * POST /upload：multipart 服务器中转上传（field 名 "file"）。
 * GET /upload/sts：换取 OSS 直传临时凭证；未配置返 {enabled:false}，客户端回退中转。
 */
class UploadController extends \common\base\ApiController
{
    /**
     * {@inheritDoc}
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['jwt'] = [
            'class' => \common\behaviors\JwtAuthBehavior::class,
            'aud' => \common\services\JwtService::AUD_APP,
            // optional 默认 []（空=全部 action 强制登录），不要传布尔
        ];
        return $behaviors;
    }

    /**
     * POST /upload
     * 接收 multipart/form-data 的 file 字段，返回 {url, path}。
     */
    public function actionUpload(): array
    {
        $file = UploadedFile::getInstanceByName('file');
        if ($file === null) {
            throw new BizException(ErrorCode::PARAM_INVALID, '请选择文件');
        }

        $service = new UploadService();
        return $service->upload($file);
    }

    /**
     * GET /upload/sts —— OSS 直传临时凭证。
     * 未启用/未配置/STS 调用失败均返 {enabled:false}，客户端据此回退服务器中转上传。
     */
    public function actionStsToken(): array
    {
        $sts = new AliyunStsService();
        if (!$sts->enabled()) {
            return ['enabled' => false];
        }
        try {
            return $sts->assumeRole($this->currentUser()->getId());
        } catch (\Throwable $e) {
            Yii::warning('STS 取凭证失败，客户端回退中转: ' . $e->getMessage(), 'upload');
            return ['enabled' => false];
        }
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
