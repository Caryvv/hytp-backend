<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\GroupService;
use common\services\JwtService;
use Yii;

/**
 * 社群（阶段4 P1，需登录）。列表/创建/详情/加入/退出/成员/群聊。
 */
class GroupController extends ApiController
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

    /** GET /groups?type=&city= —— 社群列表 */
    public function actionIndex(): array
    {
        $req = Yii::$app->request;
        return (new GroupService())->list($this->currentUser()->getId(), [
            'type' => $req->get('type'),
            'city' => $req->get('city'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** POST /groups —— 创建社群 { name, type?, avatar?, intro?, city? } */
    public function actionCreate(): array
    {
        $req = Yii::$app->request;
        return (new GroupService())->create($this->currentUser()->getId(), [
            'name' => $req->post('name'),
            'type' => $req->post('type'),
            'avatar' => $req->post('avatar'),
            'intro' => $req->post('intro'),
            'city' => $req->post('city'),
        ]);
    }

    /** GET /groups/{id} —— 社群详情 */
    public function actionView(int $id): array
    {
        return (new GroupService())->detail($this->currentUser()->getId(), $id);
    }

    /** POST /groups/{id}/join */
    public function actionJoin(int $id): array
    {
        return (new GroupService())->join($this->currentUser()->getId(), $id);
    }

    /** POST /groups/{id}/quit */
    public function actionQuit(int $id): array
    {
        return (new GroupService())->quit($this->currentUser()->getId(), $id);
    }

    /** GET /groups/{id}/members */
    public function actionMembers(int $id): array
    {
        $req = Yii::$app->request;
        return (new GroupService())->members($this->currentUser()->getId(), $id, [
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** GET /groups/{id}/messages?afterId= —— 群消息（增量） */
    public function actionMessages(int $id): array
    {
        $req = Yii::$app->request;
        return (new GroupService())->groupMessages($this->currentUser()->getId(), $id, [
            'afterId' => $req->get('afterId'),
            'limit' => $req->get('limit'),
        ]);
    }

    /** POST /groups/{id}/messages —— 发群消息 { content } */
    public function actionSend(int $id): array
    {
        return (new GroupService())->sendGroupMessage($this->currentUser()->getId(), $id, [
            'content' => Yii::$app->request->post('content'),
        ]);
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
