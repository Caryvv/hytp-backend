<?php

declare(strict_types=1);

namespace common\services;

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
}
