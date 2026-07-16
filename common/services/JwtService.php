<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use Firebase\JWT\ExpiredException;
use common\components\Redis;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Yii;

/**
 * JWT 签发与校验（对齐 docs/dev/03-后端API规范 §5）。
 *
 * - accessToken：HS256，短期（默认 2h），payload {sub,aud,exp,iat,jti,typ:access}。
 * - refreshToken：HS256，长期（默认 30d），jti 存 Redis 白名单，可主动拉黑。
 * - aud 区分三端（app/merchant/admin），密钥可不同，互不通用。
 *
 * refresh 白名单 key：hytp:jwt:refresh:{aud}:{jti} -> userId，TTL=refreshTtl。
 */
class JwtService
{
    public const ALG = 'HS256';

    public const AUD_APP = 'app';
    public const AUD_MERCHANT = 'merchant';
    public const AUD_ADMIN = 'admin';

    public const TYP_ACCESS = 'access';
    public const TYP_REFRESH = 'refresh';

    private string $aud;

    public function __construct(string $aud = self::AUD_APP)
    {
        $this->aud = $aud;
    }

    /**
     * 签发一对 token。
     *
     * @return array{accessToken:string, refreshToken:string, expiresIn:int}
     */
    public function issue(int $userId): array
    {
        $now = time();
        $accessTtl = (int) Yii::$app->params['jwt.accessTtl'];
        $refreshTtl = (int) Yii::$app->params['jwt.refreshTtl'];

        $access = $this->encode([
            'sub' => $userId,
            'aud' => $this->aud,
            'typ' => self::TYP_ACCESS,
            'iat' => $now,
            'exp' => $now + $accessTtl,
            'jti' => $this->genJti(),
        ], self::TYP_ACCESS);

        $refreshJti = $this->genJti();
        $refresh = $this->encode([
            'sub' => $userId,
            'aud' => $this->aud,
            'typ' => self::TYP_REFRESH,
            'iat' => $now,
            'exp' => $now + $refreshTtl,
            'jti' => $refreshJti,
        ], self::TYP_REFRESH);

        // refreshToken 白名单：只有在册 jti 才可用于刷新
        $this->whitelistPut($refreshJti, $userId, $refreshTtl);

        return [
            'accessToken' => $access,
            'refreshToken' => $refresh,
            'expiresIn' => $accessTtl,
        ];
    }

    /**
     * 校验 accessToken，返回 userId；失败抛 1002。
     */
    public function verifyAccess(string $token): int
    {
        $payload = $this->decode($token, self::TYP_ACCESS);
        if (($payload['typ'] ?? null) !== self::TYP_ACCESS) {
            throw new BizException(ErrorCode::UNAUTHORIZED, 'token 类型错误');
        }
        return (int) $payload['sub'];
    }

    /**
     * 用 refreshToken 换新 token 对：校验签名+白名单，旋转 jti（旧的拉黑）。
     * 失败抛 1002。
     *
     * @return array{accessToken:string, refreshToken:string, expiresIn:int}
     */
    public function refresh(string $refreshToken): array
    {
        $payload = $this->decode($refreshToken, self::TYP_REFRESH);
        if (($payload['typ'] ?? null) !== self::TYP_REFRESH) {
            throw new BizException(ErrorCode::UNAUTHORIZED, 'token 类型错误');
        }
        $jti = (string) ($payload['jti'] ?? '');
        $userId = (int) $payload['sub'];

        if ($jti === '' || !$this->whitelistHas($jti)) {
            // 已被拉黑 / 已使用 / 不存在
            throw new BizException(ErrorCode::UNAUTHORIZED, 'refreshToken 已失效');
        }

        // 旋转：旧 refresh 立即失效，签发新的一对
        $this->whitelistRemove($jti);
        return $this->issue($userId);
    }

    /**
     * 退出登录：拉黑该 refreshToken 的 jti（幂等）。
     */
    public function revoke(string $refreshToken): void
    {
        try {
            $payload = $this->decode($refreshToken, self::TYP_REFRESH);
        } catch (\Throwable $e) {
            // 已过期/非法也视为已退出，静默
            return;
        }
        $jti = (string) ($payload['jti'] ?? '');
        if ($jti !== '') {
            $this->whitelistRemove($jti);
        }
    }

    // ---------------- 内部 ----------------

    private function encode(array $payload, string $typ): string
    {
        return JWT::encode($payload, $this->secret($typ), self::ALG);
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(string $token, string $typ): array
    {
        if ($token === '') {
            throw new BizException(ErrorCode::UNAUTHORIZED, 'token 为空');
        }
        try {
            $decoded = JWT::decode($token, new Key($this->secret($typ), self::ALG));
        } catch (ExpiredException $e) {
            throw new BizException(ErrorCode::UNAUTHORIZED, 'token 已过期');
        } catch (\Throwable $e) {
            throw new BizException(ErrorCode::UNAUTHORIZED, 'token 无效');
        }
        $arr = (array) $decoded;
        if (($arr['aud'] ?? null) !== $this->aud) {
            throw new BizException(ErrorCode::UNAUTHORIZED, 'token 受众不匹配');
        }
        return $arr;
    }

    private function secret(string $typ): string
    {
        $key = $typ === self::TYP_REFRESH ? 'jwt.refreshSecret' : 'jwt.accessSecret';
        $secret = (string) (Yii::$app->params[$key] ?? '');
        if ($secret === '' || str_starts_with($secret, 'CHANGE_ME')) {
            throw new BizException(ErrorCode::INTERNAL_ERROR, 'JWT 密钥未配置');
        }
        return $secret;
    }

    private function genJti(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function whitelistKey(string $jti): string
    {
        return "hytp:jwt:refresh:{$this->aud}:{$jti}";
    }

    private function whitelistPut(string $jti, int $userId, int $ttl): void
    {
        Redis::conn()->set($this->whitelistKey($jti), $userId, 'EX', $ttl);
    }

    private function whitelistHas(string $jti): bool
    {
        return (bool) Redis::conn()->exists($this->whitelistKey($jti));
    }

    private function whitelistRemove(string $jti): void
    {
        Redis::conn()->del($this->whitelistKey($jti));
    }
}
