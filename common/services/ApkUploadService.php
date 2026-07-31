<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use Yii;
use yii\web\UploadedFile;

/**
 * APK 分片上传：前端把 APK 切成 ≤1MB 的块逐个上传（绕开 nginx/PHP 上传体积限制），
 * 后端存临时块，全部到齐后按序流式拼接进 api/web/downloads/，供手机直接下载。
 *
 * 安全：uploadId/fileName 严格过滤，只落 downloads/，仅认 .apk + ZIP 魔数。
 */
class ApkUploadService
{
    /** 单次最多 500 块（≤1MB/块 → ≤500MB，足够 APK）。 */
    private const MAX_CHUNKS = 500;

    /** 临时块目录（runtime 下，不对外）。 */
    private function tmpDir(string $uploadId): string
    {
        return Yii::getAlias('@api/runtime/apk_chunks/' . $this->safeId($uploadId));
    }

    private function downloadsDir(): string
    {
        return Yii::getAlias('@api/web/downloads');
    }

    /** uploadId 只允许十六进制/字母数字，防目录穿越。 */
    private function safeId(string $uploadId): string
    {
        $id = preg_replace('/[^a-zA-Z0-9]/', '', $uploadId) ?? '';
        if ($id === '' || strlen($id) > 64) {
            throw new BizException(ErrorCode::PARAM_INVALID, '上传标识非法');
        }
        return $id;
    }

    /** 文件名归一：取 basename，仅留安全字符，强制 .apk 后缀。 */
    private function safeApkName(string $name): string
    {
        $base = basename($name);
        $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', $base) ?? '';
        if ($base === '' || $base === '.apk') {
            $base = 'app.apk';
        }
        if (!str_ends_with(strtolower($base), '.apk')) {
            $base .= '.apk';
        }
        return $base;
    }

    /**
     * 保存一个分片。index 从 0 开始。
     *
     * @return array{uploadId:string, index:int}
     */
    public function saveChunk(string $uploadId, int $index, UploadedFile $chunk): array
    {
        if ($index < 0 || $index >= self::MAX_CHUNKS) {
            throw new BizException(ErrorCode::PARAM_INVALID, '分片序号超出范围');
        }
        $dir = $this->tmpDir($uploadId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new BizException(ErrorCode::UPLOAD_FAILED, '临时目录创建失败');
        }
        if (!$chunk->saveAs($dir . '/' . $index . '.part')) {
            throw new BizException(ErrorCode::UPLOAD_FAILED, '分片保存失败');
        }
        return ['uploadId' => $this->safeId($uploadId), 'index' => $index];
    }

    /**
     * 合并所有分片为最终 APK，返回可访问 URL 与文件名。
     *
     * @return array{fileName:string, url:string, size:int}
     */
    public function merge(string $uploadId, int $total, string $fileName): array
    {
        if ($total < 1 || $total > self::MAX_CHUNKS) {
            throw new BizException(ErrorCode::PARAM_INVALID, '分片总数非法');
        }
        $dir = $this->tmpDir($uploadId);
        // 校验每块都在
        for ($i = 0; $i < $total; $i++) {
            if (!is_file($dir . '/' . $i . '.part')) {
                throw new BizException(ErrorCode::UPLOAD_FAILED, "缺少分片 {$i}，请重试");
            }
        }

        $downloads = $this->downloadsDir();
        if (!is_dir($downloads) && !@mkdir($downloads, 0755, true) && !is_dir($downloads)) {
            throw new BizException(ErrorCode::UPLOAD_FAILED, '下载目录创建失败');
        }
        $name = $this->safeApkName($fileName);
        $target = $downloads . '/' . $name;

        // 流式拼接，不整包进内存
        $out = @fopen($target, 'wb');
        if ($out === false) {
            throw new BizException(ErrorCode::UPLOAD_FAILED, '目标文件创建失败');
        }
        try {
            for ($i = 0; $i < $total; $i++) {
                $part = $dir . '/' . $i . '.part';
                $in = @fopen($part, 'rb');
                if ($in === false) {
                    throw new BizException(ErrorCode::UPLOAD_FAILED, "分片 {$i} 读取失败");
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        // 校验 APK（ZIP）魔数 PK\x03\x04
        $fh = @fopen($target, 'rb');
        $magic = $fh !== false ? (string) fread($fh, 4) : '';
        if ($fh !== false) {
            fclose($fh);
        }
        if (substr($magic, 0, 2) !== 'PK') {
            @unlink($target);
            $this->cleanup($uploadId);
            throw new BizException(ErrorCode::UPLOAD_TYPE_INVALID, '文件不是有效的 APK');
        }

        $size = (int) filesize($target);
        $this->cleanup($uploadId);

        $baseUrl = rtrim((string) (Yii::$app->params['app.apkBaseUrl'] ?? ''), '/');
        return [
            'fileName' => $name,
            'url' => ($baseUrl !== '' ? $baseUrl . '/' : '/downloads/') . $name,
            'size' => $size,
        ];
    }

    /** 清理临时块目录。 */
    private function cleanup(string $uploadId): void
    {
        $dir = $this->tmpDir($uploadId);
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.part') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}

