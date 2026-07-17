<?php

declare(strict_types=1);

namespace merchant\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Shop;
use common\services\JwtService;
use common\services\ShopService;
use Yii;

/**
 * 商家端店铺信息与资质（需登录 aud=merchant）。
 */
class ShopController extends ApiController
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

    /** GET /merchant/shop —— 本店信息 */
    public function actionInfo(): array
    {
        return $this->currentShop()->toMerchantArray();
    }

    /** PUT /merchant/shop —— 更新店铺信息 */
    public function actionUpdate(): array
    {
        $req = Yii::$app->request;
        return (new ShopService())->updateShop($this->currentShop(), [
            'name' => $req->post('name'),
            'logo' => $req->post('logo'),
            'region' => $req->post('region'),
            'contactName' => $req->post('contactName'),
            'contactPhone' => $req->post('contactPhone'),
            'type' => $req->post('type'),
        ]);
    }

    /** GET /merchant/qualifications —— 本店资质列表 */
    public function actionQualifications(): array
    {
        return (new ShopService())->qualifications($this->currentShop());
    }

    /** POST /merchant/qualifications —— { qualType, fileUrl } */
    public function actionAddQualification(): array
    {
        $req = Yii::$app->request;
        return (new ShopService())->addQualification($this->currentShop(), [
            'qualType' => $req->post('qualType'),
            'fileUrl' => $req->post('fileUrl'),
        ]);
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
