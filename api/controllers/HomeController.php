<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\services\HomeService;

/**
 * 首页数据：轮播图 + 推荐流（免登录可访问）。
 */
class HomeController extends ApiController
{
    /** GET /home/banners —— 首页轮播图 */
    public function actionBanners(): array
    {
        return (new HomeService())->getBanners();
    }

    /** GET /home/feed —— 首页推荐流 ?page=&pageSize= */
    public function actionFeed(): array
    {
        $page = (int) \Yii::$app->request->get('page', 1);
        $pageSize = (int) \Yii::$app->request->get('pageSize', 10);
        return (new HomeService())->getFeed($page, $pageSize);
    }
}
