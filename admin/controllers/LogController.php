<?php

declare(strict_types=1);

namespace admin\controllers;

use admin\base\AdminBaseController;
use common\services\AdminLogService;
use Yii;

/**
 * 管理端操作日志查询。
 */
class LogController extends AdminBaseController
{
    /** GET /admin/logs —— ?adminId=&module=&page=&pageSize= */
    public function actionIndex(): array
    {
        $this->currentAdmin();
        $req = Yii::$app->request;
        return (new AdminLogService())->list([
            'adminId' => $req->get('adminId'),
            'module' => $req->get('module'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }
}
