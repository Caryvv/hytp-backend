<?php

declare(strict_types=1);

namespace admin\controllers;

use common\base\ApiController;
use Yii;
use yii\web\ErrorAction;

/**
 * 管理端站点/健康检查控制器（阶段0）。
 */
class SiteController extends ApiController
{
    public function actions(): array
    {
        return [
            'error' => ['class' => ErrorAction::class],
        ];
    }

    /** GET /site/ping */
    public function actionPing(): array
    {
        return [
            'app' => 'app-admin',
            'time' => time(),
            'yii' => Yii::getVersion(),
        ];
    }
}
