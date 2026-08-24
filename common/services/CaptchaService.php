<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use Yii;

/**
 * 人机验证（滑块/行为验证码），发短信前置，防脚本批量遍历手机号刷短信。
 *
 * - captcha.enabled=false：完全跳过（本地纯后端联调用）。
 * - captcha.mock=true：任意非空 token 通过（前端未接入滑块 SDK 时联调用）。
 * - 真实通道：接阿里云/腾讯验证码 SDK，用 token 调其校验接口。见 verifyReal()。
 */
class CaptchaService
{
    /**
     * 校验人机验证 token。不通过抛 BizException。
     * token 为空且已启用 → CAPTCHA_REQUIRED；校验失败 → CAPTCHA_INVALID。
     */
    public function verify(?string $token): void
    {
        $params = Yii::$app->params;
        if (empty($params['captcha.enabled'])) {
            return; // 未启用，跳过
        }

        $token = trim((string) $token);
        if ($token === '') {
            throw new BizException(ErrorCode::CAPTCHA_REQUIRED);
        }

        if (!empty($params['captcha.mock'])) {
            return; // Mock：任意非空 token 通过
        }

        if (!$this->verifyReal($token)) {
            throw new BizException(ErrorCode::CAPTCHA_INVALID);
        }
    }

    /**
     * 真实验证码通道（阿里云/腾讯滑块）。接入时实现：
     * 用 params 里的 AccessKey + SceneId，拿前端回传的 token 调 VerifyIntelligentCaptcha，
     * 返回 body.Result.VerifyResult 是否为 true。
     */
    private function verifyReal(string $token): bool
    {
        throw new BizException(ErrorCode::CAPTCHA_INVALID, '真实验证码通道未配置');
    }
}
