<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\services\CatalogService;

/**
 * 商品分类（用户端只读，免登录）。
 */
class CategoryController extends ApiController
{
    /** GET /categories —— 分类树 */
    public function actionIndex(): array
    {
        return (new CatalogService())->tree();
    }
}
