<?php

declare(strict_types=1);

namespace common\services;

use common\components\Redis;
use common\enums\ErrorCode;
use common\exceptions\BizException;
use Yii;

/**
 * 短信验证码服务（对齐 docs/dev/03-后端API规范 §8）。
 *
 * - 验证码 6 位数字，存 Redis，默认 5min 过期。
 * - 限流：同号 60s 一次；同 IP 每日上限。
 * - 开发 Mock 模式（params['sms.mock']=true）不真发，仅写日志，便于联调。
 * - 真实通道预留 sendReal()，接阿里云短信 SDK 时实现。
 *
 * Redis key：
 *   hytp:sms:code:{scene}:{phone}   -> code，TTL=codeTtl
 *   hytp:sms:cd:{scene}:{phone}     -> 1，TTL=resendInterval（重发冷却）
 *   hytp:sms:ip:{date}:{ip}         -> 计数，TTL=当日剩余
 */
class SmsService
{
    public const SCENE_LOGIN = 'login';
    public const SCENE_REGISTER = 'register';
    public const SCENE_RESET = 'reset';

    private const SCENES = [self::SCENE_LOGIN, self::SCENE_REGISTER, self::SCENE_RESET];

    /**
     * 发送验证码。
     *
     * @param string $phone 手机号
     * @param string $scene 场景
     * @param string|null $ip 请求 IP（限流用）
     * @return array{devCode?:string} Mock 模式下回带 devCode 便于联调
     */
    public function send(string $phone, string $scene, ?string $ip = null): array
    {
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            throw new BizException(ErrorCode::PARAM_INVALID, '手机号格式不正确');
        }
        if (!in_array($scene, self::SCENES, true)) {
            throw new BizException(ErrorCode::PARAM_INVALID, '短信场景不合法');
        }

        $redis = Redis::conn();

        // 同号重发冷却
        $cdKey = "hytp:sms:cd:{$scene}:{$phone}";
        if ((bool) $redis->exists($cdKey)) {
            throw new BizException(ErrorCode::TOO_MANY_REQUESTS, '发送过于频繁，请稍后再试');
        }

        // 同 IP 每日上限
        if ($ip !== null && $ip !== '') {
            $this->checkIpLimit($ip);
        }

        $code = $this->genCode();
        $codeTtl = (int) Yii::$app->params['sms.codeTtl'];
        $resend = (int) Yii::$app->params['sms.resendInterval'];

        $redis->set("hytp:sms:code:{$scene}:{$phone}", $code, 'EX', $codeTtl);
        $redis->set($cdKey, 1, 'EX', $resend);

        $mock = (bool) (Yii::$app->params['sms.mock'] ?? true);
        if ($mock) {
            Yii::info("[SMS-MOCK] phone={$phone} scene={$scene} code={$code}", __METHOD__);
            // 仅开发环境回带，方便无真实通道时联调
            return ['devCode' => $code];
        }

        try {
            $this->sendReal($phone, $scene, $code);
        } catch (\Throwable $e) {
            Yii::error("[SMS] send fail phone={$phone}: {$e->getMessage()}", __METHOD__);
            throw new BizException(ErrorCode::SMS_SEND_FAIL);
        }
        return [];
    }

    /**
     * 校验验证码；成功后删除（一次性）。失败抛 1102。
     */
    public function verify(string $phone, string $scene, string $code): void
    {
        $redis = Redis::conn();
        $key = "hytp:sms:code:{$scene}:{$phone}";
        $saved = $redis->get($key);

        if ($saved === null || $saved === false || (string) $saved === '') {
            throw new BizException(ErrorCode::SMS_CODE_INVALID, '验证码已过期，请重新获取');
        }
        if (!hash_equals((string) $saved, $code)) {
            throw new BizException(ErrorCode::SMS_CODE_INVALID);
        }
        // 一次性：验证通过即失效
        $redis->del($key);
    }

    // ---------------- 内部 ----------------

    private function checkIpLimit(string $ip): void
    {
        $redis = Redis::conn();
        $date = date('Ymd');
        $key = "hytp:sms:ip:{$date}:{$ip}";
        $limit = (int) Yii::$app->params['sms.ipDailyLimit'];

        $count = (int) $redis->incr($key);
        if ($count === 1) {
            // 首次设置当日过期（到次日 0 点）
            $redis->expireat($key, strtotime('tomorrow'));
        }
        if ($count > $limit) {
            throw new BizException(ErrorCode::TOO_MANY_REQUESTS, '今日发送次数已达上限');
        }
    }

    private function genCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 真实短信通道（阿里云短信 SDK）。接入时实现：
     * 用 params 里的 AccessKeyId/Secret/SignName/TemplateCode，
     * 按 scene 选模板，TemplateParam 传 {"code": $code}。
     */
    private function sendReal(string $phone, string $scene, string $code): void
    {
        throw new BizException(ErrorCode::SMS_SEND_FAIL, '真实短信通道未配置');
    }
}
