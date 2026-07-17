<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\AdminUser;

/**
 * 管理端账号认证（用户名 + 密码登录，aud=admin）。
 */
class AdminAuthService
{
    private JwtService $jwt;

    public function __construct(?JwtService $jwt = null)
    {
        $this->jwt = $jwt ?? new JwtService(JwtService::AUD_ADMIN);
    }

    /**
     * 管理员登录。
     *
     * @param array{username:?string, password:?string} $in
     * @return array token + admin + permissions
     */
    public function login(array $in): array
    {
        $username = trim((string) ($in['username'] ?? ''));
        $password = (string) ($in['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '请输入用户名和密码');
        }

        $admin = AdminUser::findByUsername($username);
        if ($admin === null || !$admin->validatePassword($password)) {
            throw new BizException(ErrorCode::PARAM_INVALID, '用户名或密码错误');
        }
        if ((int) $admin->status === AdminUser::STATUS_DISABLED) {
            throw new BizException(ErrorCode::ACCOUNT_DISABLED, '账号已被禁用');
        }

        $admin->last_login_at = time();
        $admin->save(false, ['last_login_at']);

        $tokens = $this->jwt->issue($admin->getId());

        return array_merge($tokens, [
            'admin' => $admin->toArray(),
            'permissions' => $admin->permissionKeys(),
        ]);
    }

    public function refresh(string $refreshToken): array
    {
        if (trim($refreshToken) === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '缺少 refreshToken');
        }
        return $this->jwt->refresh($refreshToken);
    }

    public function logout(string $refreshToken): void
    {
        if (trim($refreshToken) !== '') {
            $this->jwt->revoke($refreshToken);
        }
    }
}
