<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\services\ShopQueryService;
use Yii;

/**
 * 店铺主页（用户端只读，白名单免登录）。
 */
class ShopController extends ApiController
{
    /** GET /shops/{id} —— 店铺主页 */
    public function actionView(int $id): array
    {
        return (new ShopQueryService())->detail($id);
    }

    /** GET /shops/{id}/products —— 店铺在售商品 */
    public function actionProducts(int $id): array
    {
        $req = Yii::$app->request;
        return (new ShopQueryService())->products($id, [
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }
}
