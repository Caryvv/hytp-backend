<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use common\models\Shop;

/**
 * 商家端账号认证（账号 + 密码登录，aud=merchant）。
 *
 * 商家注册后 status=待审核，需管理端审核通过（status=正常）后方可上架商品。
 * 登录不校验审核状态（待审核商家可登录看进度），但上架等操作在 ProductService 内校验。
 */
class MerchantAuthService
{
    private JwtService $jwt;

    public function __construct(?JwtService $jwt = null)
    {
        $this->jwt = $jwt ?? new JwtService(JwtService::AUD_MERCHANT);
    }

    /**
     * 商家登录。
     *
     * @param array{account:?string, password:?string} $in
     * @return array 登录响应（token + shop）
     */
    public function login(array $in): array
    {
        $account = trim((string) ($in['account'] ?? ''));
        $password = (string) ($in['password'] ?? '');

        if ($account === '' || $password === '') {
            throw new BizException(ErrorCode::PARAM_INVALID, '请输入账号和密码');
        }

        $shop = Shop::findByAccount($account);
        if ($shop === null || !$shop->validatePassword($password)) {
            throw new BizException(ErrorCode::PARAM_INVALID, '账号或密码错误');
        }
        if ((int) $shop->status === Shop::STATUS_BANNED) {
            throw new BizException(ErrorCode::ACCOUNT_DISABLED, '商家账号已被封禁');
        }

        $tokens = $this->jwt->issue($shop->getId());

        return array_merge($tokens, [
            'shop' => $shop->toMerchantArray(),
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
