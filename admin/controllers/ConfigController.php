<?php

declare(strict_types=1);

namespace admin\controllers;

use admin\base\AdminBaseController;
use common\models\AdminRolePermission;
use common\services\AdminLogService;
use common\services\ConfigService;
use Yii;

/**
 * 管理端平台配置（sys_config）读写，需权限点 config:edit。
 */
class ConfigController extends AdminBaseController
{
    /** GET /configs —— 全部配置项（含已知 key 的含义映射）。 */
    public function actionIndex(): array
    {
        $this->requirePermission(AdminRolePermission::PERM_CONFIG_EDIT);
        return (new ConfigService())->list();
    }

    /** PUT /configs/{key} —— 新增或更新 { value }。 */
    public function actionSave(string $key): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_CONFIG_EDIT);
        $value = Yii::$app->request->post('value');
        $result = (new ConfigService())->save($key, $value !== null ? (string) $value : null);

        (new AdminLogService())->record(
            $admin->getId(),
            'config.save',
            'config',
            ['key' => $key, 'value' => $value],
        );
        return $result;
    }

    /** DELETE /configs/{key} —— 删除该项（已知 key 回落默认值）。 */
    public function actionRemove(string $key): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_CONFIG_EDIT);
        (new ConfigService())->remove($key);

        (new AdminLogService())->record($admin->getId(), 'config.remove', 'config', ['key' => $key]);
        return ['key' => $key];
    }
}
