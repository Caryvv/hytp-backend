<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use Yii;
use yii\web\ErrorAction;

/**
 * 站点/健康检查控制器（阶段0：验证 API 骨架端到端跑通）。
 */
class SiteController extends ApiController
{
    public function actions(): array
    {
        return [
            'error' => ['class' => ErrorAction::class],
        ];
    }

    /** GET /site/ping —— 健康检查 + DB 连通性。 */
    public function actionPing(): array
    {
        $dbOk = false;
        try {
            Yii::$app->db->createCommand('SELECT 1')->queryScalar();
            $dbOk = true;
        } catch (\Throwable $e) {
            Yii::error($e->getMessage(), __METHOD__);
        }

        return [
            'app' => 'app-api',
            'time' => time(),
            'db' => $dbOk,
            'yii' => \Yii::getVersion(),
        ];
    }

    /** GET /site/error-demo —— 验证业务异常统一响应。 */
    public function actionErrorDemo(): void
    {
        throw new BizException(ErrorCode::PARAM_INVALID, '这是一个演示错误');
    }
}
