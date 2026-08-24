<?php

declare(strict_types=1);

namespace common\services;

use common\components\Redis;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\User;
use Yii;

/**
 * 账号认证业务（对齐 docs/dev/05-用户端-账号与通用 §5）。
 *
 * 登录/注册合一：
 *   - loginType=code：手机号 + 验证码。号未注册则自动创建（isNewUser=true）。
 *   - loginType=password：手机号 + 密码。号必须已注册且已设密码。
 */
class AuthService
{
    public const LOGIN_TYPE_CODE = 'code';
    public const LOGIN_TYPE_PASSWORD = 'password';

    private JwtService $jwt;
    private SmsService $sms;

    public function __construct(?JwtService $jwt = null, ?SmsService $sms = null)
    {
        $this->jwt = $jwt ?? new JwtService(JwtService::AUD_APP);
        $this->sms = $sms ?? new SmsService();
    }

    /**
     * 登录/注册。
     *
     * @param array{phone:?string, code:?string, password:?string, loginType:?string, ip:?string} $in
     * @return array 登录响应结构（含 token 与 user）
     */
    public function login(array $in): array
    {
        $phone = trim((string) ($in['phone'] ?? ''));
        $loginType = (string) ($in['loginType'] ?? self::LOGIN_TYPE_CODE);

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            throw new BizException(ErrorCode::PARAM_INVALID, '手机号格式不正确');
        }

        // 登录限速：按 手机号+IP 固定窗口，防验证码/密码爆破（配合 SmsService::verify 的错误锁定双保险）
        $this->checkLoginRate($phone, (string) ($in['ip'] ?? ''));

        $isNewUser = false;

        if ($loginType === self::LOGIN_TYPE_CODE) {
            $code = trim((string) ($in['code'] ?? ''));
            if ($code === '') {
                throw new BizException(ErrorCode::PARAM_INVALID, '请输入验证码');
            }
            // 登录场景校验（注册合一，统一用 login 场景）
            $this->sms->verify($phone, SmsService::SCENE_LOGIN, $code);

            $user = User::findByPhone($phone);
            if ($user === null) {
                $user = $this->register($phone);
                $isNewUser = true;
            }
        } elseif ($loginType === self::LOGIN_TYPE_PASSWORD) {
            $password = (string) ($in['password'] ?? '');
            if ($password === '') {
                throw new BizException(ErrorCode::PARAM_INVALID, '请输入密码');
            }
            $user = User::findByPhone($phone);
            if ($user === null || !$user->hasPassword() || !$user->validatePassword($password)) {
                throw new BizException(ErrorCode::PARAM_INVALID, '手机号或密码错误');
            }
        } else {
            throw new BizException(ErrorCode::PARAM_INVALID, '登录方式不支持');
        }

        if ((int) $user->status === User::STATUS_BANNED) {
            throw new BizException(ErrorCode::ACCOUNT_DISABLED);
        }

        $tokens = $this->jwt->issue($user->getId());

        return array_merge($tokens, [
            'user' => $user->toProfileArray(),
            'isNewUser' => $isNewUser,
        ]);
    }

    /**
     * 刷新 token。
     */
    public function refresh(string $refreshToken): array
    {
        if (trim($refreshToken) === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '缺少 refreshToken');
        }
        return $this->jwt->refresh($refreshToken);
    }

    /**
     * 退出登录。
     */
    public function logout(string $refreshToken): void
    {
        if (trim($refreshToken) !== '') {
            $this->jwt->revoke($refreshToken);
        }
    }

    /**
     * 登录限速：手机号+IP 固定窗口内限尝试次数，防爆破。
     * ponytail: 固定窗口够用；要严格限速再上滑窗/令牌桶。
     */
    private function checkLoginRate(string $phone, string $ip): void
    {
        $window = (int) (Yii::$app->params['login.attemptWindow'] ?? 60);
        $max = (int) (Yii::$app->params['login.maxAttempts'] ?? 10);
        if ($max <= 0) {
            return;
        }
        $redis = Redis::conn();
        // ip 可能为空（CLI/测试），退化为仅按手机号限
        $key = 'hytp:login:rate:' . $phone . ':' . ($ip !== '' ? $ip : 'noip');
        $count = (int) $redis->incr($key);
        if ($count === 1) {
            $redis->expire($key, $window);
        }
        if ($count > $max) {
            throw new BizException(ErrorCode::TOO_MANY_REQUESTS, '尝试过于频繁，请稍后再试');
        }
    }

    /**
     * 创建新用户（验证码注册，暂不设密码）。
     */
    private function register(string $phone): User
    {
        $user = new User();
        $user->phone = $phone;
        $user->password_hash = ''; // 未设密码，后续可在设置页补
        $user->nickname = '同袍' . substr($phone, -4);
        $user->balance = '1000.00'; // 新用户注册赠送 1000 代币
        $user->status = User::STATUS_ACTIVE;
        $user->generateAuthKey();

        if (!$user->save()) {
            // 并发下唯一键冲突：重查一次
            $exist = User::findByPhone($phone);
            if ($exist !== null) {
                return $exist;
            }
            Yii::error('user register fail: ' . json_encode($user->getErrors(), JSON_UNESCAPED_UNICODE), __METHOD__);
            throw new BizException(ErrorCode::INTERNAL_ERROR, '注册失败');
        }

        // TODO 阶段1后续：发放新人礼包券（对齐 05 §营销）
        return $user;
    }
}
