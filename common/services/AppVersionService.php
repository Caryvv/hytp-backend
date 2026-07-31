<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\AppVersion;

/**
 * 应用内更新检查：返回该平台最新启用版本，判定是否有更新 / 是否强制。
 */
class AppVersionService
{
    /**
     * @return array{hasUpdate:bool, latest:array{versionCode:int,versionName:string,updateLog:string,downloadUrl:string,forceUpdate:bool}|null}
     */
    public function checkUpdate(string $platform, int $currentCode): array
    {
        $platform = $platform !== '' ? $platform : 'android';

        /** @var AppVersion|null $latest */
        $latest = AppVersion::find()
            ->where(['platform' => $platform, 'enabled' => 1])
            ->orderBy(['version_code' => SORT_DESC])
            ->limit(1)
            ->one();

        if ($latest === null) {
            return ['hasUpdate' => false, 'latest' => null];
        }

        $hasUpdate = (int) $latest->version_code > $currentCode;
        // 当前版本低于最低支持版本 → 强制升级；或版本本身标记了强制
        $forceUpdate = (bool) $latest->force_update
            || $currentCode < (int) $latest->min_supported_code;

        return [
            'hasUpdate' => $hasUpdate,
            'latest' => $latest->toClientArray($hasUpdate && $forceUpdate),
        ];
    }

    // ---------------- 管理端 CRUD ----------------

    /**
     * 全部版本（倒序）。
     *
     * @return array{list:array<int,array<string,mixed>>}
     */
    public function listAll(string $platform = 'android'): array
    {
        $rows = AppVersion::find()
            ->where(['platform' => $platform !== '' ? $platform : 'android'])
            ->orderBy(['version_code' => SORT_DESC])
            ->all();

        return ['list' => array_map(static fn (AppVersion $v): array => $v->toAdminArray(), $rows)];
    }

    /**
     * 新增版本。
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function create(array $in): array
    {
        $v = new AppVersion();
        $this->fill($v, $in);
        if (!$v->save()) {
            throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($v));
        }
        return $v->toAdminArray();
    }

    /**
     * 更新版本。
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function update(int $id, array $in): array
    {
        $v = $this->find($id);
        $this->fill($v, $in);
        if (!$v->save()) {
            throw new BizException(ErrorCode::PARAM_INVALID, $this->firstError($v));
        }
        return $v->toAdminArray();
    }

    /** 上/下线。 */
    public function toggle(int $id, bool $enabled): array
    {
        $v = $this->find($id);
        $v->enabled = $enabled ? 1 : 0;
        $v->save(false, ['enabled', 'updated_at']);
        return $v->toAdminArray();
    }

    public function delete(int $id): void
    {
        $v = $this->find($id);
        // 删库前先尝试删磁盘 APK 文件（孤儿文件清理）
        $this->deleteApkFile($v->download_url);
        $v->delete();
    }

    /**
     * 根据 download_url 删除 downloads 目录下的 APK 文件。
     * 安全：只删 @api/web/downloads/ 下的 .apk 文件，外部 URL / 路径穿越一律跳过。
     * 删除失败静默（文件可能已被手动删/权限问题），不影响数据库删除。
     */
    private function deleteApkFile(string $url): void
    {
        if ($url === '') {
            return;
        }
        // 解析出纯文件名（/downloads/xxx.apk → xxx.apk；http://domain/downloads/xxx.apk → xxx.apk）
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === false) {
            return;
        }
        $name = basename($path);
        // 仅处理 .apk 后缀（防误删）
        if (!str_ends_with($name, '.apk')) {
            return;
        }
        $downloadsDir = \Yii::getAlias('@api/web/downloads');
        $file = $downloadsDir . '/' . $name;
        // 二次确认在 downloads 目录下（防路径穿越）
        if (strpos(realpath($file) ?: $file, realpath($downloadsDir)) !== 0) {
            return;
        }
        if (is_file($file)) {
            @unlink($file);
            \Yii::info("Deleted APK file: {$file}", __METHOD__);
        }
    }

    private function find(int $id): AppVersion
    {
        $v = AppVersion::findOne(['id' => $id]);
        if ($v === null) {
            throw new BizException(ErrorCode::NOT_FOUND, '版本记录不存在');
        }
        return $v;
    }

    /** @param array<string,mixed> $in */
    private function fill(AppVersion $v, array $in): void
    {
        $v->platform = (string) ($in['platform'] ?? $v->platform ?? 'android');
        if (isset($in['versionCode'])) {
            $v->version_code = (int) $in['versionCode'];
        }
        if (isset($in['versionName'])) {
            $v->version_name = (string) $in['versionName'];
        }
        $v->update_log = isset($in['updateLog']) ? (string) $in['updateLog'] : $v->update_log;
        $v->download_url = isset($in['downloadUrl']) ? (string) $in['downloadUrl'] : (string) $v->download_url;
        $v->force_update = !empty($in['forceUpdate']) ? 1 : 0;
        if (isset($in['minSupportedCode'])) {
            $v->min_supported_code = (int) $in['minSupportedCode'];
        }
        if (isset($in['enabled'])) {
            $v->enabled = !empty($in['enabled']) ? 1 : 0;
        }
    }

    private function firstError(AppVersion $v): string
    {
        foreach ($v->getFirstErrors() as $msg) {
            return (string) $msg;
        }
        return '保存失败';
    }
}
