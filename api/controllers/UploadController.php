<?php

declare(strict_types=1);

namespace api\controllers;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\services\UploadService;
use Yii;
use yii\web\UploadedFile;

/**
 * 文件上传（需登录）。
 * POST /upload：multipart 上传单个文件，field 名 "file"。
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
}
