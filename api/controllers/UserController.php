<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\JwtService;
use Yii;

/**
 * 当前用户资料（需登录）。
 */
class UserController extends ApiController
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

    /** GET /user/profile —— 当前用户信息。 */
    public function actionProfile(): array
    {
        return $this->currentUser()->toProfileArray();
    }

    /**
     * PUT /user/profile —— 改昵称/头像/性别/城市/生日。
     * 请求：{ nickname?, avatar?, gender?, city?, birthday? }
     */
    public function actionUpdateProfile(): array
    {
        $user = $this->currentUser();
        $req = Yii::$app->request;

        // 仅允许更新白名单字段
        foreach (['nickname', 'avatar', 'city'] as $f) {
            $v = $req->post($f);
            if ($v !== null) {
                $user->$f = (string) $v;
            }
        }
        if ($req->post('gender') !== null) {
            $user->gender = (int) $req->post('gender');
        }
        if ($req->post('birthday') !== null) {
            $user->birthday = (string) $req->post('birthday') ?: null;
        }

        if (!$user->save()) {
            $first = $this->firstError($user);
            throw new BizException(ErrorCode::PARAM_INVALID, $first ?? '资料更新失败');
        }

        return $user->toProfileArray();
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

    private function firstError(User $user): ?string
    {
        foreach ($user->getFirstErrors() as $msg) {
            return $msg;
        }
        return null;
    }
}
