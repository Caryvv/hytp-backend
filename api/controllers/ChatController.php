<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\ChatService;
use common\services\JwtService;
use Yii;

/**
 * 私信（阶段4 P1，需登录）。轮询拉取。
 */
class ChatController extends ApiController
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

    /** GET /chat/conversations —— 会话列表 */
    public function actionConversations(): array
    {
        $req = Yii::$app->request;
        return (new ChatService())->conversations($this->currentUser()->getId(), [
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** POST /chat/open —— 打开/创建与某用户的会话 { targetId } */
    public function actionOpen(): array
    {
        $targetId = (int) Yii::$app->request->post('targetId', 0);
        return (new ChatService())->openConversation($this->currentUser()->getId(), $targetId);
    }

    /** GET /chat/messages?conversationId=&afterId= —— 会话消息（增量） */
    public function actionMessages(): array
    {
        $req = Yii::$app->request;
        return (new ChatService())->messages($this->currentUser()->getId(), (int) $req->get('conversationId', 0), [
            'afterId' => $req->get('afterId'),
            'limit' => $req->get('limit'),
        ]);
    }

    /** POST /chat/messages —— 发消息 { conversationId, content } */
    public function actionSend(): array
    {
        $req = Yii::$app->request;
        return (new ChatService())->sendMessage($this->currentUser()->getId(), (int) $req->post('conversationId', 0), [
            'content' => $req->post('content'),
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
