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

    /** POST /admin/feeds/{id}/review —— 待审动态审核 { pass:bool, remark? }（pass=true 通过，false 驳回下架） */
    public function actionReview(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_FEED_AUDIT);
        $req = Yii::$app->request;
        $pass = (bool) $req->post('pass', false);
        $remark = (string) $req->post('remark', '');

        $result = (new AuditService())->auditFeed($id, $pass, $remark);

        (new AdminLogService())->record(
            $admin->getId(),
            $pass ? 'feed.review.pass' : 'feed.review.reject',
            'feed',
            ['feedId' => $id, 'remark' => $remark],
        );
        return $result;
    }

    /** GET /admin/feed-reports —— 用户举报队列 ?status=0&page=&pageSize=（默认待处理） */
    public function actionReports(): array
    {
        $this->requirePermission(AdminRolePermission::PERM_FEED_AUDIT);
        $req = Yii::$app->request;
        return (new AuditService())->reportList([
            'status' => $req->get('status'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** POST /admin/feed-reports/{id}/handle —— 处置举报 { accept:bool, remark? }（accept=true 成立并下架动态） */
    public function actionHandleReport(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_FEED_AUDIT);
        $req = Yii::$app->request;
        $accept = (bool) $req->post('accept', false);
        $remark = (string) $req->post('remark', '');

        $result = (new AuditService())->handleReport($id, $admin->getId(), $accept, $remark);

        (new AdminLogService())->record(
            $admin->getId(),
            $accept ? 'feed.report.accept' : 'feed.report.ignore',
            'feed_report',
            ['reportId' => $id, 'remark' => $remark],
        );
        return $result;
    }
}
