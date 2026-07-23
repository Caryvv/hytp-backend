<?php

declare(strict_types=1);

namespace api\controllers;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\FeedService;
use common\services\JwtService;
use common\services\TipService;
use Yii;

/**
 * 同袍动态 + 互动（阶段4 P0，需登录）。
 */
class FeedController extends ApiController
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

    /** GET /feeds?tab=recommend|following&page= —— 动态流 */
    public function actionIndex(): array
    {
        $req = Yii::$app->request;
        $uid = $this->currentUser()->getId();
        $in = ['page' => $req->get('page'), 'pageSize' => $req->get('pageSize')];
        $svc = new FeedService();
        return $req->get('tab') === 'following'
            ? $svc->followingFeed($uid, $in)
            : $svc->recommendFeed($uid, $in);
    }

    /** POST /feeds —— 发布动态 { content, media?, tags?, productIds?, city?, mediaType? } */
    public function actionCreate(): array
    {
        $req = Yii::$app->request;
        return (new FeedService())->publish($this->currentUser()->getId(), [
            'content' => $req->post('content'),
            'media' => $req->post('media'),
            'tags' => $req->post('tags'),
            'productIds' => $req->post('productIds'),
            'city' => $req->post('city'),
            'mediaType' => $req->post('mediaType'),
        ]);
    }

    /** GET /feeds/{id} —— 动态详情 */
    public function actionView(int $id): array
    {
        return (new FeedService())->detail($this->currentUser()->getId(), $id);
    }

    /** DELETE /feeds/{id} —— 删除自己的动态 */
    public function actionDelete(int $id): array
    {
        return (new FeedService())->deleteOwn($this->currentUser()->getId(), $id);
    }

    /** POST /feeds/{id}/like */
    public function actionLike(int $id): array
    {
        return (new FeedService())->like($this->currentUser()->getId(), $id);
    }

    /** POST /feeds/{id}/unlike */
    public function actionUnlike(int $id): array
    {
        return (new FeedService())->unlike($this->currentUser()->getId(), $id);
    }

    /** POST /feeds/{id}/favorite */
    public function actionFavorite(int $id): array
    {
        return (new FeedService())->favorite($this->currentUser()->getId(), $id);
    }

    /** POST /feeds/{id}/unfavorite */
    public function actionUnfavorite(int $id): array
    {
        return (new FeedService())->unfavorite($this->currentUser()->getId(), $id);
    }

    /** POST /feeds/{id}/share */
    public function actionShare(int $id): array
    {
        return (new FeedService())->share($this->currentUser()->getId(), $id);
    }

    /** POST /feeds/{id}/tip —— 打赏动态 { coin }，header Idempotency-Key 幂等 */
    public function actionTip(int $id): array
    {
        $req = Yii::$app->request;
        $tipNo = (string) $req->headers->get('Idempotency-Key', '');
        if ($tipNo === '') {
            $tipNo = 'T' . date('YmdHis') . bin2hex(random_bytes(8)); // 兜底：客户端未传
        }
        return (new TipService())->tip($this->currentUser()->getId(), $id, (int) $req->post('coin'), $tipNo);
    }

    /** GET /feeds/{id}/comments?page= —— 评论列表 */
    public function actionComments(int $id): array
    {
        $req = Yii::$app->request;
        return (new FeedService())->comments($this->currentUser()->getId(), $id, [
            'page' => $req->get('page'),
            'pageSize' => $req->get('pageSize'),
        ]);
    }

    /** POST /feeds/{id}/comments —— 发表评论 { content, parentId? } */
    public function actionComment(int $id): array
    {
        $req = Yii::$app->request;
        return (new FeedService())->addComment($this->currentUser()->getId(), $id, [
            'content' => $req->post('content'),
            'parentId' => $req->post('parentId'),
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
