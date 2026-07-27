<?php

declare(strict_types=1);

namespace common\services;

use common\models\ProductReview;
use GuzzleHttp\Client;
use Yii;

/**
 * 情感分析 AI 客户端 —— 调 hytp-web/ai 微服务（内网 HTTP + HMAC 签名）。
 *
 * doc 13 §5/§7：同步调用，超时/失败由调用方回退规则版（本类失败返 null，绝不抛断主流程）。
 * 密钥 ai.sign.secret 在 params-local 覆盖，须与 AI 服务 INTERNAL_SIGN_SECRET 一致。
 */
class AiSentimentService
{
    /**
     * 批量情感分析。成功返回与 $texts 等长的结果数组，任何失败（未启用/网络/超时/格式）返 null。
     *
     * @param string[] $texts
     * @return array<int,array{sentiment:int,keywords:array<int,string>}>|null
     */
    public function analyze(array $texts): ?array
    {
        if ($texts === []) {
            return [];
        }
        $params = Yii::$app->params;
        if (empty($params['ai.enabled'])) {
            return null;
        }
        $baseUrl = rtrim((string) ($params['ai.baseUrl'] ?? ''), '/');
        $secret = (string) ($params['ai.sign.secret'] ?? '');
        if ($baseUrl === '' || $secret === '') {
            return null;
        }

        // 签名与发送用同一份字节（rawBody），否则 HMAC 对不上
        $rawBody = (string) json_encode(['texts' => array_values($texts)], JSON_UNESCAPED_UNICODE);
        $ts = (string) time();
        $sign = hash_hmac('sha256', $ts . "\n" . $rawBody, $secret);

        try {
            $client = new Client(['timeout' => (float) ($params['ai.timeout'] ?? 5)]);
            $resp = $client->post($baseUrl . '/ai/sentiment', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Timestamp' => $ts,
                    'X-Internal-Sign' => $sign,
                ],
                'body' => $rawBody,
                'http_errors' => false,
            ]);
            if ($resp->getStatusCode() !== 200) {
                Yii::warning('AI 情感分析非 200: ' . $resp->getStatusCode() . ' ' . $resp->getBody(), 'ai');
                return null;
            }
            /** @var array{results?:array<int,array{sentiment?:mixed,keywords?:mixed}>} $data */
            $data = json_decode((string) $resp->getBody(), true) ?: [];
            $results = $data['results'] ?? null;
            if (!is_array($results) || count($results) !== count($texts)) {
                return null;
            }
            return $this->normalize($results);
        } catch (\Throwable $e) {
            Yii::warning('AI 情感分析异常: ' . $e->getMessage(), 'ai');
            return null;
        }
    }

    /**
     * 规整 AI 返回：情感越界落中性，keywords 强制字符串数组。
     *
     * @param array<int,array{sentiment?:mixed,keywords?:mixed}> $results
     * @return array<int,array{sentiment:int,keywords:array<int,string>}>
     */
    private function normalize(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $s = (int) ($r['sentiment'] ?? ProductReview::SENTIMENT_NEUTRAL);
            if (!in_array($s, [
                ProductReview::SENTIMENT_NEGATIVE,
                ProductReview::SENTIMENT_NEUTRAL,
                ProductReview::SENTIMENT_POSITIVE,
            ], true)) {
                $s = ProductReview::SENTIMENT_NEUTRAL;
            }
            $keywords = [];
            if (isset($r['keywords']) && is_array($r['keywords'])) {
                foreach ($r['keywords'] as $k) {
                    $kw = trim((string) $k);
                    if ($kw !== '') {
                        $keywords[] = $kw;
                    }
                }
            }
            $out[] = ['sentiment' => $s, 'keywords' => array_slice($keywords, 0, 5)];
        }
        return $out;
    }
}
