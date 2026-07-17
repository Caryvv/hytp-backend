<?php

declare(strict_types=1);

namespace admin\base;

use common\base\ApiController;
use common\behaviors\JwtAuthBehavior;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\AdminUser;
use common\services\JwtService;
use Yii;

/**
 * 管理端需登录控制器基类：JWT(aud=admin) + RBAC 权限点校验 + 当前管理员。
 */
abstract class AdminBaseController extends ApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => JwtAuthBehavior::class,
            'aud' => JwtService::AUD_ADMIN,
            'identityClass' => AdminUser::class,
        ];
        return $behaviors;
    }

    protected function currentAdmin(): AdminUser
    {
        /** @var AdminUser|null $admin */
        $admin = Yii::$app->user->identity;
        if ($admin === null) {
            throw new BizException(ErrorCode::UNAUTHORIZED);
        }
        return $admin;
    }

    /**
     * 校验当前管理员是否拥有指定权限点，缺失抛 1705。
     */
    protected function requirePermission(string $key): AdminUser
    {
        $admin = $this->currentAdmin();
        if (!$admin->hasPermission($key)) {
            throw new BizException(ErrorCode::ADMIN_NO_PERMISSION);
        }
        return $admin;
    }
}
