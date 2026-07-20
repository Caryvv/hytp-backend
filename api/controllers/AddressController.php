<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\AddressService;
use common\services\JwtService;
use Yii;

/**
 * 收货地址（需登录）。
 */
class AddressController extends ApiController
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

    /** GET /addresses —— 列表 */
    public function actionIndex(): array
    {
        return (new AddressService())->list($this->currentUser()->getId());
    }

    /** POST /addresses —— 新建 */
    public function actionCreate(): array
    {
        return (new AddressService())->create($this->currentUser()->getId(), $this->body());
    }

    /** PUT /addresses/{id} —— 修改 */
    public function actionUpdate(int $id): array
    {
        return (new AddressService())->update($this->currentUser()->getId(), $id, $this->body());
    }

    /** DELETE /addresses/{id} —— 删除 */
    public function actionDelete(int $id): array
    {
        (new AddressService())->delete($this->currentUser()->getId(), $id);
        return ['deleted' => true];
    }

    /** POST /addresses/{id}/default —— 设为默认 */
    public function actionSetDefault(int $id): array
    {
        return (new AddressService())->setDefault($this->currentUser()->getId(), $id);
    }

    /**
     * @return array<string,mixed>
     */
    private function body(): array
    {
        $req = Yii::$app->request;
        return [
            'name' => $req->post('name'),
            'phone' => $req->post('phone'),
            'province' => $req->post('province'),
            'city' => $req->post('city'),
            'district' => $req->post('district'),
            'detail' => $req->post('detail'),
            'isDefault' => $req->post('isDefault'),
        ];
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
