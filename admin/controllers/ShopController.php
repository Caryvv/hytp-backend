<?php

declare(strict_types=1);

namespace admin\controllers;

use admin\base\AdminBaseController;
use common\models\AdminRolePermission;
use common\services\AdminLogService;
use common\services\AuditService;
use Yii;

/**
 * 管理端商家审核（需权限点 shop:audit）。
 */
class ShopController extends AdminBaseController
{
    /** GET /admin/shops —— 商家列表 ?status=&type=&keyword=&page=&pageSize= */
    public function actionIndex(): array
    {
        $this->requirePermission(AdminRolePermission::PERM_SHOP_AUDIT);
        $req = Yii::$app->request;
        return (new AuditService())->shopList([
            'status' => $req->get('status'),
            'type' => $req->get('type'),
            'keyword' => $req->get('keyword'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** POST /admin/shops/{id}/audit —— { pass:bool, remark? } */
    public function actionAudit(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_SHOP_AUDIT);
        $req = Yii::$app->request;
        $pass = (bool) $req->post('pass', false);
        $remark = (string) $req->post('remark', '');

        $result = (new AuditService())->auditShop($id, $pass, $remark);

        (new AdminLogService())->record(
            $admin->getId(),
            $pass ? 'shop.audit.pass' : 'shop.audit.reject',
            'shop',
            ['shopId' => $id, 'remark' => $remark],
        );
        return $result;
    }
}
