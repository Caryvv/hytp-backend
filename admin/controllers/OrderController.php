<?php

declare(strict_types=1);

namespace admin\controllers;

use admin\base\AdminBaseController;
use common\models\AdminRolePermission;
use common\services\AdminLogService;
use common\services\AdminOrderService;
use Yii;

/**
 * 管理端订单监控 + 售后仲裁（需登录 aud=admin + RBAC）。
 */
class OrderController extends AdminBaseController
{
    /** GET /orders —— 全平台订单监控 ?shopId=&status=&keyword=&page= */
    public function actionIndex(): array
    {
        $this->requirePermission(AdminRolePermission::PERM_ORDER_MANAGE);
        $req = Yii::$app->request;
        return (new AdminOrderService())->list([
            'shopId' => $req->get('shopId'),
            'status' => $req->get('status'),
            'keyword' => $req->get('keyword'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** GET /orders/{orderNo} —— 订单详情 */
    public function actionView(string $orderNo): array
    {
        $this->requirePermission(AdminRolePermission::PERM_ORDER_MANAGE);
        return (new AdminOrderService())->detail($orderNo);
    }

    /** GET /refunds —— 售后仲裁队列 ?status=&page= */
    public function actionRefunds(): array
    {
        $this->requirePermission(AdminRolePermission::PERM_REFUND_ARBITRATE);
        $req = Yii::$app->request;
        return (new AdminOrderService())->listRefunds([
            'status' => $req->get('status'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** POST /refunds/{id}/arbitrate —— 售后仲裁 { agree, remark } */
    public function actionArbitrate(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_REFUND_ARBITRATE);
        $req = Yii::$app->request;
        $agree = (bool) $req->post('agree');
        $result = (new AdminOrderService())->arbitrate($id, [
            'agree' => $agree,
            'remark' => $req->post('remark'),
        ]);
        (new AdminLogService())->record(
            $admin->getId(),
            $agree ? 'refund.arbitrate.agree' : 'refund.arbitrate.reject',
            'order',
            ['refundId' => $id, 'remark' => $req->post('remark')],
        );
        return $result;
    }
}
