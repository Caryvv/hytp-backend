<?php

declare(strict_types=1);

namespace admin\controllers;

use admin\base\AdminBaseController;
use common\models\Product;
use common\models\Shop;

/**
 * 管理端概览首页（登录即可看，核心提醒含待审核数）。
 */
class DashboardController extends AdminBaseController
{
    /** GET /admin/dashboard —— 概览指标 */
    public function actionIndex(): array
    {
        $this->currentAdmin();

        return [
            'shop' => [
                'total' => (int) Shop::find()->count(),
                'pending' => (int) Shop::find()->where(['status' => Shop::STATUS_PENDING])->count(),
                'active' => (int) Shop::find()->where(['status' => Shop::STATUS_ACTIVE])->count(),
            ],
            'product' => [
                'total' => (int) Product::find()->count(),
                'auditing' => (int) Product::find()->where(['status' => Product::STATUS_AUDITING])->count(),
                'onSale' => (int) Product::find()->where(['status' => Product::STATUS_ON])->count(),
            ],
        ];
    }
}
