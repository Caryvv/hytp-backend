<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\JwtService;
use common\services\TaskService;
use Yii;

/**
 * 任务系统（赚同袍币，需登录 aud=app）。
 * 签到主动领取；发动态/关注/首单为行为触发（在对应 Service 埋点自动发奖）。
 */
class TaskController extends ApiController
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

    /** GET /tasks —— 任务列表（含各任务今日/历史完成状态 + 奖励） */
    public function actionIndex(): array
    {
        return (new TaskService())->list($this->currentUser()->getId());
    }

    /** POST /tasks/claim —— 主动领取（body{taskKey}，v1 仅 signin） */
    public function actionClaim(): array
    {
        $taskKey = (string) Yii::$app->request->post('taskKey', '');
        return (new TaskService())->claim($this->currentUser()->getId(), $taskKey);
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
