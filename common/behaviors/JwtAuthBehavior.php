<?php

declare(strict_types=1);

namespace common\behaviors;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use common\services\JwtService;
use Yii;
use yii\base\ActionFilter;
use yii\base\InvalidConfigException;

/**
 * JWT 鉴权过滤器（对齐 docs/dev/03-后端API规范 §5）。
 *
 * 用法（控制器 behaviors()）：
 *   'authenticator' => [
 *       'class' => JwtAuthBehavior::class,
 *       'aud' => JwtService::AUD_APP,
 *       'except' => ['login', 'refresh'],   // 完全放行
 *       'optional' => ['index'],            // 可选登录：有 token 则解析，无则匿名
 *   ]
 *
 * 校验成功后 Yii::$app->user 为登录用户；失败抛 1002。
 * enableSession=false，仅内存态（当次请求）。
 */
class JwtAuthBehavior extends ActionFilter
{
    /** 受众，三端不同（app/merchant/admin）。 */
    public $aud = JwtService::AUD_APP;

    // 注意：$only / $except 由父类 yii\base\ActionFilter 声明（无类型），
    // 子类不能再加类型声明，否则 PHP 报 "must not be defined"。直接复用父类的 $except。

    /** 可选登录的 action id（有 token 则解析设置身份，无 token 也放行）。 */
    public $optional = [];

    public function beforeAction($action): bool
    {
        $id = $action->id;

        if (in_array($id, $this->except, true)) {
            return true;
        }

        $token = $this->extractToken();
        $isOptional = in_array($id, $this->optional, true);

        if ($token === null) {
            if ($isOptional) {
                return true;
            }
            throw new BizException(ErrorCode::UNAUTHORIZED, '未提供登录凭证');
        }

        $jwt = new JwtService($this->aud);
        $userId = $jwt->verifyAccess($token); // 失败抛 1002

        $user = User::findIdentity($userId);
        if ($user === null) {
            // 用户不存在 / 已封禁
            if ($isOptional) {
                return true;
            }
            throw new BizException(ErrorCode::UNAUTHORIZED, '账号状态异常');
        }

        // enableSession=false，仅设置当次请求身份
        Yii::$app->user->setIdentity($user);
        return true;
    }

    /**
     * 从 Authorization: Bearer <token> 提取 token；无则 null。
     */
    private function extractToken(): ?string
    {
        $auth = Yii::$app->request->headers->get('Authorization');
        if ($auth === null || $auth === '') {
            return null;
        }
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
