<?php

declare(strict_types=1);

namespace admin\controllers;

use admin\base\AdminBaseController;
use common\models\AdminRolePermission;
use common\services\AdminLogService;
use common\services\AuditService;
use Yii;

/**
 * 管理端商品审核队列（需权限点 product:audit）。
 * 补 docs/dev/12 未列出的商品审核端点。
 */
class ProductController extends AdminBaseController
{
    /** GET /admin/products —— 审核队列 ?status=2&shopId=&page=&pageSize=（默认审核中） */
    public function actionIndex(): array
    {
        $this->requirePermission(AdminRolePermission::PERM_PRODUCT_AUDIT);
        $req = Yii::$app->request;
        return (new AuditService())->productList([
            'status' => $req->get('status'),
            'shopId' => $req->get('shopId'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** POST /admin/products/{id}/audit —— { pass:bool, remark? } */
    public function actionAudit(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_PRODUCT_AUDIT);
        $req = Yii::$app->request;
        $pass = (bool) $req->post('pass', false);
        $remark = (string) $req->post('remark', '');

        $result = (new AuditService())->auditProduct($id, $pass, $remark);

        (new AdminLogService())->record(
            $admin->getId(),
            $pass ? 'product.audit.pass' : 'product.audit.reject',
            'product',
            ['productId' => $id, 'remark' => $remark],
        );
        return $result;
    }
}
