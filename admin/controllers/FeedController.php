<?php

declare(strict_types=1);

namespace admin\controllers;

use admin\base\AdminBaseController;
use common\models\AdminRolePermission;
use common\services\AdminLogService;
use common\services\AuditService;
use Yii;

/**
 * 管理端动态巡查（先发后审：动态默认正常发布，管理端巡查违规下架 / 恢复）。
 * 需权限点 feed:audit。
 */
class FeedController extends AdminBaseController
{
    /** GET /admin/feeds —— 巡查列表 ?status=1&userId=&page=&pageSize=（默认正常） */
    public function actionIndex(): array
    {
        $this->requirePermission(AdminRolePermission::PERM_FEED_AUDIT);
        $req = Yii::$app->request;
        return (new AuditService())->feedList([
            'status' => $req->get('status'),
            'userId' => $req->get('userId'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** POST /admin/feeds/{id}/audit —— { off:bool, remark? }（off=true 下架，false 恢复） */
    public function actionAudit(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_FEED_AUDIT);
        $req = Yii::$app->request;
        $off = (bool) $req->post('off', false);
        $remark = (string) $req->post('remark', '');

        $result = (new AuditService())->setFeedStatus($id, $off, $remark);

        (new AdminLogService())->record(
            $admin->getId(),
            $off ? 'feed.off' : 'feed.restore',
            'feed',
            ['feedId' => $id, 'remark' => $remark],
        );
        return $result;
    }
}
