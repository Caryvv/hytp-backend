<?php

declare(strict_types=1);

namespace merchant\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Shop;
use common\services\JwtService;
use common\services\ProductService;
use Yii;

/**
 * 商家端商品管理（需登录 aud=merchant）。
 */
class ProductController extends ApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => JwtAuthBehavior::class,
            'aud' => JwtService::AUD_MERCHANT,
            'identityClass' => Shop::class,
        ];
        return $behaviors;
    }

    /** GET /merchant/products —— 本店商品列表 ?status=&page=&pageSize= */
    public function actionIndex(): array
    {
        $req = Yii::$app->request;
        return (new ProductService())->listByShop($this->currentShop(), [
            'status' => $req->get('status'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** GET /merchant/products/{id} —— 本店商品详情 */
    public function actionView(int $id): array
    {
        return (new ProductService())->detailForShop($this->currentShop(), $id);
    }

    /** POST /merchant/products —— 新建商品（提审） */
    public function actionCreate(): array
    {
        return (new ProductService())->create($this->currentShop(), $this->bodyParams());
    }

    /** PUT /merchant/products/{id} —— 编辑商品（重新提审） */
    public function actionUpdate(int $id): array
    {
        return (new ProductService())->update($this->currentShop(), $id, $this->bodyParams());
    }

    /** POST /merchant/products/{id}/toggle —— 上/下架切换 */
    public function actionToggle(int $id): array
    {
        return (new ProductService())->toggle($this->currentShop(), $id);
    }

    /** PUT /merchant/products/{id}/stock —— { stock } */
    public function actionStock(int $id): array
    {
        $stock = (int) Yii::$app->request->post('stock', 0);
        return (new ProductService())->updateStock($this->currentShop(), $id, $stock);
    }

    /**
     * 商品可编辑字段（白名单从 body 读取）。
     *
     * @return array<string,mixed>
     */
    private function bodyParams(): array
    {
        $req = Yii::$app->request;
        $keys = ['title', 'categoryId', 'formeDynasty', 'formeType', 'style', 'tradeType',
            'price', 'cover', 'images', 'detail', 'tryonModelUrl', 'stock', 'isOriginal'];
        $out = [];
        foreach ($keys as $k) {
            $v = $req->post($k);
            if ($v !== null) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function currentShop(): Shop
    {
        /** @var Shop|null $shop */
        $shop = Yii::$app->user->identity;
        if ($shop === null) {
            throw new BizException(ErrorCode::UNAUTHORIZED);
        }
        return $shop;
    }
}
