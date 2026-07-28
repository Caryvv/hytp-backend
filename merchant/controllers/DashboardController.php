<?php

declare(strict_types=1);

namespace merchant\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Shop;
use common\services\JwtService;
use common\services\ReviewStatService;
use Yii;

/**
 * 商家端数据驾驶舱（需登录 aud=merchant）。
 */
class DashboardController extends ApiController
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

    /** GET /merchant/dashboard/review-keywords —— 本店评价情感分布 + 高频品控关键词 */
    public function actionReviewKeywords(): array
    {
        return (new ReviewStatService())->shopReviewStats($this->currentShop());
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
