<?php

declare(strict_types=1);

namespace admin\controllers;

use admin\base\AdminBaseController;
use common\models\AdminRolePermission;
use common\services\AdminLogService;
use common\services\DepositClaimService;
use Yii;

/**
 * 管理端品质保障金理赔（需登录 aud=admin + RBAC deposit:arbitrate）。
 */
class DepositClaimController extends AdminBaseController
{
    /** GET /deposit-claims —— 理赔队列 ?status=&page= */
    public function actionIndex(): array
    {
        $this->requirePermission(AdminRolePermission::PERM_DEPOSIT_ARBITRATE);
        $req = Yii::$app->request;
        return (new DepositClaimService())->listForAdmin([
            'status' => $req->get('status'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** POST /deposit-claims/{id}/arbitrate —— 判定 { approve, remark } */
    public function actionArbitrate(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_DEPOSIT_ARBITRATE);
        $req = Yii::$app->request;
        $approve = (bool) $req->post('approve');
        $result = (new DepositClaimService())->arbitrate($admin->getId(), $id, [
            'approve' => $approve,
            'remark' => $req->post('remark'),
        ]);
        (new AdminLogService())->record(
            $admin->getId(),
            $approve ? 'deposit.arbitrate.approve' : 'deposit.arbitrate.reject',
            'deposit_claim',
            ['claimId' => $id, 'remark' => $req->post('remark')],
        );
        return $result;
    }
}
