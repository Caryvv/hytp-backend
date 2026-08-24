<?php

declare(strict_types=1);

namespace common\services;

use GuzzleHttp\Client;
use Yii;

/**
 * 图片内容审核客户端 —— 调 hytp-web/ai 微服务 /ai/image-audit（内网 HTTP + HMAC），
 * 微服务再转发阿里云内容安全（green-cip / ImageModeration）。
 *
 * 与 AiTryonService 同一套 HMAC 网关。审核是发布主流程的旁路：
 * 服务未启用/超时/异常一律"放行"（返回 pass=true），不阻塞用户发动态，
 * 交文字敏感词（SensitiveWordService）+ 管理端人工巡查兜底。
 */
class ContentAuditService
{
    /**
     * 审核一组图片 URL。任一张命中风险返回 false。
     * 服务不可用/异常时返回 true（放行，不阻塞发布）。
     *
     * @param string[] $urls
     */
    public function imagesPass(array $urls): bool
    {
        $urls = array_values(array_filter(array_map('strval', $urls), static fn (string $u): bool => $u !== ''));
        if ($urls === []) {
            return true;
        }

        $params = Yii::$app->params;
        if (empty($params['ai.enabled'])) {
            return true; // AI 网关未启用，放行
        }
        $baseUrl = rtrim((string) ($params['ai.baseUrl'] ?? ''), '/');
        $secret = (string) ($params['ai.sign.secret'] ?? '');
        if ($baseUrl === '' || $secret === '') {
            return true;
        }

        $rawBody = (string) json_encode(['urls' => $urls], JSON_UNESCAPED_UNICODE);
        $ts = (string) time();
        $sign = hash_hmac('sha256', $ts . "\n" . $rawBody, $secret);

        try {
            // 逐张同步送审，按图片数放宽超时（每张阿里云机审约 1~2s）
            $timeout = (float) ($params['ai.timeout'] ?? 5) + count($urls) * 2;
            $client = new Client(['timeout' => $timeout]);
            $resp = $client->post($baseUrl . '/ai/image-audit', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Timestamp' => $ts,
                    'X-Internal-Sign' => $sign,
                ],
                'body' => $rawBody,
                'http_errors' => false,
            ]);
            if ($resp->getStatusCode() !== 200) {
                Yii::warning('图片审核非 200: ' . $resp->getStatusCode(), 'ai');
                return true; // 审核链路异常，放行交人工
            }
            /** @var array<string,mixed> $data */
            $data = json_decode((string) $resp->getBody(), true) ?: [];
            // degraded=true 表示服务侧未配/异常已放行；pass 缺省按放行处理
            return (bool) ($data['pass'] ?? true);
        } catch (\Throwable $e) {
            Yii::warning('图片审核异常: ' . $e->getMessage(), 'ai');
            return true; // 不阻塞发布
        }
    }
}
