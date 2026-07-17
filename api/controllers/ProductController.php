<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\services\ProductQueryService;
use Yii;

/**
 * 商品浏览（用户端只读，白名单免登录）。
 */
class ProductController extends ApiController
{
    /** GET /products —— 商品列表（筛选+分页） */
    public function actionIndex(): array
    {
        $req = Yii::$app->request;
        return (new ProductQueryService())->list([
            'categoryId' => $req->get('categoryId'),
            'formeDynasty' => $req->get('formeDynasty'),
            'formeType' => $req->get('formeType'),
            'style' => $req->get('style'),
            'tradeType' => $req->get('tradeType'),
            'keyword' => $req->get('keyword'),
            'sort' => $req->get('sort'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** POST /products/search —— 复杂筛选（body） */
    public function actionSearch(): array
    {
        $req = Yii::$app->request;
        return (new ProductQueryService())->list([
            'categoryId' => $req->post('categoryId'),
            'formeDynasty' => $req->post('formeDynasty'),
            'formeType' => $req->post('formeType'),
            'style' => $req->post('style'),
            'tradeType' => $req->post('tradeType'),
            'keyword' => $req->post('keyword'),
            'sort' => $req->post('sort'),
            'page' => $req->post('page'),
            'pageSize' => $req->post('pageSize'),
        ]);
    }

    /** GET /products/{id} —— 商品详情 */
    public function actionView(int $id): array
    {
        return (new ProductQueryService())->detail($id);
    }

    /** GET /products/{id}/reviews —— 评价列表 */
    public function actionReviews(int $id): array
    {
        $req = Yii::$app->request;
        return (new ProductQueryService())->reviews($id, [
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }
}
