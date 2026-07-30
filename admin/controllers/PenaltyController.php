<?php

declare(strict_types=1);

namespace admin\controllers;

use admin\base\AdminBaseController;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\AdminRolePermission;
use common\services\AdminLogService;
use common\services\ShopPenaltyService;
use Yii;

/**
 * 管理端商家处罚（扣信用分 / 封禁 / 解封 + 信用流水），需权限点 shop:penalty。
 */
class PenaltyController extends AdminBaseController
{
    /** GET /shops/{id}/credit-logs —— 该店信用流水 ?page=&pageSize= */
    public function actionCreditLogs(int $id): array
    {
        $this->requirePermission(AdminRolePermission::PERM_SHOP_PENALTY);
        $req = Yii::$app->request;
        return (new ShopPenaltyService())->creditLogs(
            $id,
            $req->get('page') !== null ? (int) $req->get('page') : null,
            $req->get('pageSize') !== null ? (int) $req->get('pageSize') : null,
        );
    }

    /** POST /shops/{id}/penalty —— { action: deduct|ban|unban, points?, reason? } */
    public function actionPenalty(int $id): array
    {
        $admin = $this->requirePermission(AdminRolePermission::PERM_SHOP_PENALTY);
        $req = Yii::$app->request;
        $action = (string) $req->post('action', '');
        $reason = (string) $req->post('reason', '');
        $svc = new ShopPenaltyService();

        $result = match ($action) {
            'deduct' => $svc->deductCredit($id, (int) $req->post('points', 0), $reason),
            'ban' => $svc->ban($id, $reason),
            'unban' => $svc->unban($id),
            default => throw new BizException(ErrorCode::PARAM_INVALID, '未知处罚动作'),
        };

        (new AdminLogService())->record(
            $admin->getId(),
            'shop.penalty.' . $action,
            'shop',
            ['shopId' => $id, 'points' => $req->post('points'), 'reason' => $reason],
        );
        return $result;
    }
}
