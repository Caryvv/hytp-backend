<?php

declare(strict_types=1);

namespace common\services;

use GuzzleHttp\Client;
use Yii;

/**
 * 汉服知识问答 AI 客户端 —— 调 hytp-web/ai 微服务 /ai/qa（内网 HTTP + HMAC 签名）。
 *
 * doc 13 §3：同步调用；失败/未启用返兜底文案（hitKnowledge=false），不抛断主流程。
 * 密钥 ai.sign.secret 在 params-local 覆盖，须与 AI 服务 INTERNAL_SIGN_SECRET 一致。
 */
class AiQaService
{
    /**
     * 问答。
     *
     * @param string $question 本轮提问
     * @param array<int,array{role:string,content:string}> $history 最近若干轮对话
     * @return array{answer:string,hitKnowledge:bool}
     */
    public function ask(string $question, array $history = []): array
    {
        $params = Yii::$app->params;
        if (empty($params['ai.enabled'])) {
            return $this->fallback();
        }
        $baseUrl = rtrim((string) ($params['ai.baseUrl'] ?? ''), '/');
        $secret = (string) ($params['ai.sign.secret'] ?? '');
        if ($baseUrl === '' || $secret === '') {
            return $this->fallback();
        }

        // 签名与发送用同一份字节（rawBody），否则 HMAC 对不上
        $rawBody = (string) json_encode(
            ['question' => $question, 'history' => array_values($history)],
            JSON_UNESCAPED_UNICODE,
        );
        $ts = (string) time();
        $sign = hash_hmac('sha256', $ts . "\n" . $rawBody, $secret);

        try {
            $client = new Client(['timeout' => (float) ($params['ai.timeout'] ?? 5) + 10]); // 问答比情感慢，放宽
            $resp = $client->post($baseUrl . '/ai/qa', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Timestamp' => $ts,
                    'X-Internal-Sign' => $sign,
                ],
                'body' => $rawBody,
                'http_errors' => false,
            ]);
            if ($resp->getStatusCode() !== 200) {
                Yii::warning('AI 问答非 200: ' . $resp->getStatusCode() . ' ' . $resp->getBody(), 'ai');
                return $this->fallback();
            }
            /** @var array{answer?:mixed,hitKnowledge?:mixed} $data */
            $data = json_decode((string) $resp->getBody(), true) ?: [];
            $answer = trim((string) ($data['answer'] ?? ''));
            if ($answer === '') {
                return $this->fallback();
            }
            return [
                'answer' => $answer,
                'hitKnowledge' => ($data['hitKnowledge'] ?? false) !== false,
            ];
        } catch (\Throwable $e) {
            Yii::warning('AI 问答异常: ' . $e->getMessage(), 'ai');
            return $this->fallback();
        }
    }

    /** AI 不可用时的兜底：引导用户去社区求助。 */
    private function fallback(): array
    {
        return [
            'answer' => '智能助手暂时不在线，你可以去「同袍社交圈」发帖求助，热心同袍会帮你解答～',
            'hitKnowledge' => false,
        ];
    }
}
