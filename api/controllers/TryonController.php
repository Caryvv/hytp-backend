<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\JwtService;
use common\services\TryonService;
use Yii;

/**
 * AI 试衣（需登录）。
 * 提交任务 → 前端定时轮询查结果；可复用形象管理。
 */
class TryonController extends ApiController
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

    /** POST /tryon/submit —— 提交试衣任务 { productId, personUrl } */
    public function actionSubmit(): array
    {
        $req = Yii::$app->request;
        return (new TryonService())->createTask(
            $this->currentUser()->getId(),
            (int) $req->post('productId'),
            (string) $req->post('personUrl'),
        );
    }

    /** GET /tryon/tasks/{id} —— 轮询任务结果 */
    public function actionPoll(int $id): array
    {
        return (new TryonService())->pollTask($this->currentUser()->getId(), $id);
    }

    /** GET /tryon/quota —— 今日试衣配额（剩余免费次数 / 超额单价，供页面提示） */
    public function actionQuota(): array
    {
        return (new TryonService())->quota($this->currentUser()->getId());
    }

    /** GET /tryon/tasks —— 我的试衣历史 */
    public function actionMyTasks(): array
    {
        $req = Yii::$app->request;
        return (new TryonService())->myTasks($this->currentUser()->getId(), [
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** DELETE /tryon/tasks/{id} —— 软删除我的试衣记录 */
    public function actionDeleteTask(int $id): array
    {
        return (new TryonService())->deleteTask($this->currentUser()->getId(), $id);
    }

    /** GET /tryon/avatars —— 我的可复用形象列表 */
    public function actionAvatars(): array
    {
        return ['list' => (new TryonService())->avatars($this->currentUser()->getId())];
    }

    /** POST /tryon/avatars —— 新增形象 { imageUrl } */
    public function actionAddAvatar(): array
    {
        $req = Yii::$app->request;
        return (new TryonService())->addAvatar($this->currentUser()->getId(), (string) $req->post('imageUrl'));
    }

    /** DELETE /tryon/avatars/{id} —— 删除形象 */
    public function actionDeleteAvatar(int $id): array
    {
        return (new TryonService())->deleteAvatar($this->currentUser()->getId(), $id);
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
