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
        $this->find($id)->delete();
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
