<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\JwtService;
use common\services\MembershipService;
use Yii;

/**
 * 会员开通/续费（需登录）。用同袍币购买，每月 30 元 = 3000 币。
 */
class MembershipController extends ApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => JwtAuthBehavior::class,
            'aud' => JwtService::AUD_APP,
        ];
        return $behaviors;
    }

    /** GET /membership/plan —— 套餐价格 + 当前会员状态 */
    public function actionPlan(): array
    {
        return (new MembershipService())->plan($this->currentUser()->getId());
    }

    /** POST /membership/purchase —— 用同袍币开通/续费 { plan: month|year }（默认 month） */
    public function actionPurchase(): array
    {
        $plan = (string) Yii::$app->request->post('plan', 'month');
        return (new MembershipService())->purchase($this->currentUser()->getId(), $plan);
    }

    private function currentUser(): User
    {
        /** @var User|null $user */
        $user = Yii::$app->user->identity;
        if ($user === null) {
            throw new BizException(ErrorCode::UNAUTHORIZED);
        }
        return $user;
    }
}
