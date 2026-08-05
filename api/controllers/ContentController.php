<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\ContentService;
use common\services\JwtService;
use Yii;

/**
 * 文旅 + 文化传承 内容（用户端）。
 * 列表/详情免登录可浏览（登录后带 isLiked/isFavorited/isSignedUp）；互动需登录。
 */
class ContentController extends ApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => JwtAuthBehavior::class,
            'aud' => JwtService::AUD_APP,
            // 列表/详情可匿名浏览：有 token 则解析出用户，无 token 也放行
            'optional' => ['index', 'view'],
        ];
        return $behaviors;
    }

    /** GET /contents?type=&city=&category=&sort=&page= —— 内容列表 */
    public function actionIndex(): array
    {
        $req = Yii::$app->request;
        return (new ContentService())->list($this->currentUserId(), [
            'type' => $req->get('type'),
            'city' => $req->get('city'),
            'category' => $req->get('category'),
            'sort' => $req->get('sort'),
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** GET /contents/{id} —— 内容详情 */
    public function actionView(int $id): array
    {
        return (new ContentService())->detail($this->currentUserId(), $id);
    }

    /** POST /contents/{id}/like */
    public function actionLike(int $id): array
    {
        return (new ContentService())->like($this->requireUser()->getId(), $id);
    }

    /** POST /contents/{id}/unlike */
    public function actionUnlike(int $id): array
    {
        return (new ContentService())->unlike($this->requireUser()->getId(), $id);
    }

    /** POST /contents/{id}/favorite */
    public function actionFavorite(int $id): array
    {
        return (new ContentService())->favorite($this->requireUser()->getId(), $id);
    }

    /** POST /contents/{id}/unfavorite */
    public function actionUnfavorite(int $id): array
    {
        return (new ContentService())->unfavorite($this->requireUser()->getId(), $id);
    }

    /** POST /contents/{id}/signup —— 报名预约 { name, phone, quantity? } */
    public function actionSignup(int $id): array
    {
        $req = Yii::$app->request;
        return (new ContentService())->signup($this->requireUser()->getId(), $id, [
            'name' => $req->post('name'),
            'phone' => $req->post('phone'),
            'quantity' => $req->post('quantity'),
        ]);
    }

    /** POST /contents/{id}/cancel-signup —— 取消报名 */
    public function actionCancelSignup(int $id): array
    {
        return (new ContentService())->cancelSignup($this->requireUser()->getId(), $id);
    }

    /** 匿名浏览：未登录返回 0（无互动态）。 */
    private function currentUserId(): int
    {
        /** @var User|null $user */
        $user = Yii::$app->user->identity;
        return $user?->getId() ?? 0;
    }

    /** 互动操作：必须登录。 */
    private function requireUser(): User
    {
        /** @var User|null $user */
        $user = Yii::$app->user->identity;
        if ($user === null) {
            throw new BizException(ErrorCode::UNAUTHORIZED);
        }
        return $user;
    }
}
