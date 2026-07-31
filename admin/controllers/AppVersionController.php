<?php

declare(strict_types=1);

namespace admin\controllers;

use admin\base\AdminBaseController;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\AdminRolePermission;
use common\services\AdminLogService;
use common\services\ApkUploadService;
use common\services\AppVersionService;
use Yii;
use yii\web\UploadedFile;

/**
 * 管理端 App 版本管理（需权限点 config:edit）：APK 分片上传 + 版本记录 CRUD。
 */
class AppVersionController extends AdminBaseController
{
    /** GET /app-versions —— 版本列表 ?platform= */
    public function actionIndex(): array
    {
        $this->requirePermission(AdminRolePermission::PERM_CONFIG_EDIT);
        return (new AppVersionService())->listAll((string) Yii::$app->request->get('platform', 'android'));
    }

    /** POST /app-versions/chunk —— 上传一个分片 { uploadId, index, chunk(file) } */
    public function actionChunk(): array
    {
        $this->requirePermission(AdminRolePermission::PERM_CONFIG_EDIT);
        $req = Yii::$app->request;
        $chunk = UploadedFile::getInstanceByName('chunk');
        if ($chunk === null) {
            throw new BizException(ErrorCode::PARAM_INVALID, '缺少分片数据');
        }
        return (new ApkUploadService())->saveChunk(
            (string) $req->post('uploadId', ''),
            (int) $req->post('index', -1),
            $chunk,
        );
    }

    /** POST /app-versions/merge —— 合并分片 { uploadId, total, fileName } */
    public function actionMerge(): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_CONFIG_EDIT);
        $req = Yii::$app->request;
        $result = (new ApkUploadService())->merge(
            (string) $req->post('uploadId', ''),
            (int) $req->post('total', 0),
            (string) $req->post('fileName', 'app.apk'),
        );
        (new AdminLogService())->record($admin->getId(), 'appversion.upload', 'app_version', $result);
        return $result;
    }

    /** POST /app-versions —— 新增版本记录 */
    public function actionCreate(): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_CONFIG_EDIT);
        $result = (new AppVersionService())->create(Yii::$app->request->post());
        (new AdminLogService())->record($admin->getId(), 'appversion.create', 'app_version', $result);
        return $result;
    }

    /** PUT /app-versions/{id} —— 更新版本记录 */
    public function actionUpdate(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_CONFIG_EDIT);
        $result = (new AppVersionService())->update($id, Yii::$app->request->post());
        (new AdminLogService())->record($admin->getId(), 'appversion.update', 'app_version', ['id' => $id]);
        return $result;
    }

    /** POST /app-versions/{id}/toggle —— 上/下线 { enabled } */
    public function actionToggle(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_CONFIG_EDIT);
        $enabled = (bool) Yii::$app->request->post('enabled', false);
        $result = (new AppVersionService())->toggle($id, $enabled);
        (new AdminLogService())->record($admin->getId(), 'appversion.toggle', 'app_version', ['id' => $id, 'enabled' => $enabled]);
        return $result;
    }

    /** DELETE /app-versions/{id} */
    public function actionDelete(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_CONFIG_EDIT);
        (new AppVersionService())->delete($id);
        (new AdminLogService())->record($admin->getId(), 'appversion.delete', 'app_version', ['id' => $id]);
        return ['id' => $id];
    }
}
