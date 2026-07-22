<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use Yii;
use yii\web\UploadedFile;

/**
 * 文件上传服务。
 * 当前驱动为本地存储（upload.driver=local），文件写入 api/web/uploads/。
 * 预留 uploadToOss() 方法，切换 upload.driver=oss 时启用阿里云 OSS 上传。
 */
class UploadService
{
    /** 允许的 MIME 类型 */
    private const ALLOWED_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** 单文件最大 10MB */
    private const MAX_SIZE = 10 * 1024 * 1024;

    /**
     * 上传单个文件，返回 ['url' => 可访问URL, 'path' => 相对路径]。
     * @throws BizException
     */
    public function upload(UploadedFile $file): array
    {
        $this->validate($file);

        $driver = Yii::$app->params['upload.driver'] ?? 'local';

        if ($driver === 'oss') {
            return $this->uploadToOss($file);
        }

        return $this->uploadToLocal($file);
    }

    /**
     * 本地存储：写入 api/web/uploads/YYYYMM/ 目录。
     */
    private function uploadToLocal(UploadedFile $file): array
    {
        $dir = Yii::getAlias('@api/web/uploads/' . date('Ym'));
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $ext = $file->getExtension() ?: 'jpg';
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $relativePath = 'uploads/' . date('Ym') . '/' . $name;

        if (!$file->saveAs(Yii::getAlias('@api/web/' . $relativePath))) {
            throw new BizException(ErrorCode::UPLOAD_FAILED);
        }

        $baseUrl = rtrim(Yii::$app->params['upload.baseUrl'] ?? '', '/');

        return [
            'url' => ($baseUrl ?: '') . '/' . $relativePath,
            'path' => $relativePath,
        ];
    }

    /**
     * 预留 OSS 上传（阿里云 OSS SDK 接入点）。
     * 切换 upload.driver=oss 时实现此方法。
     * @throws BizException
     */
    private function uploadToOss(UploadedFile $file): array
    {
        // TODO: 集成阿里云 OSS SDK
        // 1. 使用 Yii::$app->params['upload.oss.accessKeyId/accessKeySecret/endpoint/bucket']
        // 2. 实例化 OssClient，执行 putObject
        // 3. 返回 ['url' => $ossUrl, 'path' => $ossKey]
        throw new BizException(ErrorCode::UPLOAD_FAILED, 'OSS 上传尚未接入');
    }

    /**
     * 校验文件类型与大小。
     * @throws BizException
     */
    private function validate(UploadedFile $file): void
    {
        // MIME 具体（image/png 等）则按白名单校验；泛化(image/*)或缺失时回退到扩展名判断，
        // 避免个别客户端传 image/* 或空 MIME 就误拒。
        $mime = $file->type;
        if ($mime && $mime !== 'application/octet-stream' && !str_contains($mime, '*')) {
            if (!in_array($mime, self::ALLOWED_TYPES, true)) {
                throw new BizException(ErrorCode::UPLOAD_TYPE_INVALID);
            }
        } else {
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                throw new BizException(ErrorCode::UPLOAD_TYPE_INVALID);
            }
        }

        if ($file->size > self::MAX_SIZE) {
            throw new BizException(ErrorCode::UPLOAD_SIZE_EXCEEDED);
        }
    }
}
