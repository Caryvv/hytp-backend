<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\services\AppVersionService;
use Yii;

/**
 * 应用内更新检查（免登录）。
 */
class AppVersionController extends ApiController
{
    /** GET /app/version/check?platform=android&versionCode=1 */
    public function actionCheck(): array
    {
        $req = Yii::$app->request;
        return (new AppVersionService())->checkUpdate(
            (string) $req->get('platform', 'android'),
            (int) $req->get('versionCode', 0),
        );
    }
}
