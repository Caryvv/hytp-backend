<?php

declare(strict_types=1);

namespace admin\controllers;

use admin\base\AdminBaseController;
use common\models\AdminRolePermission;
use common\services\AliyunStsService;
use Yii;

/**
 * 管理端文件上传 —— OSS 直传临时凭证（供浏览器直传 OSS，字节不经服务器）。
 * 复用 AliyunStsService（与 App 端同一套 STS 基础设施），scope=content 目录隔离。
 * 未配置 STS 返 {enabled:false}，前端据此提示。
 */
class UploadController extends AdminBaseController
{
    /** GET /admin/upload/sts —— 换取 OSS 直传临时凭证（限 content/ 前缀写入）。 */
    public function actionStsToken(): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_CONTENT_MANAGE);
        $sts = new AliyunStsService();
        if (!$sts->enabled()) {
            return ['enabled' => false];
        }
        try {
            return $sts->assumeRole($admin->getId(), 'content');
        } catch (\Throwable $e) {
            Yii::warning('管理端 STS 取凭证失败: ' . $e->getMessage(), 'upload');
            return ['enabled' => false];
        }
    }
}
