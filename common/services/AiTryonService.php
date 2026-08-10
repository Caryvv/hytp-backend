<?php

declare(strict_types=1);

namespace common\services;

use common\enums\ErrorCode;
use common\exceptions\BizException;
use GuzzleHttp\Client;
use Yii;

/**
 * AI 试衣客户端 —— 调 hytp-web/ai 微服务 /ai/tryon（内网 HTTP + HMAC 签名），
 * 微服务再转发阿里云百炼 OutfitAnyone/aitryon 异步任务。
 *
 * 与 AiQaService 同一套 HMAC 网关；但试衣无兜底文案，失败即抛 TRYON_FAILED。
 * 密钥 ai.sign.secret 须与 AI 服务 INTERNAL_SIGN_SECRET 一致。
 */
class AiTryonService
{
    /**
     * 提交试衣任务，返回阿里云异步任务 id。
     *
     * @throws BizException AI 未启用/调用失败
     */
    public function submit(string $personUrl, string $garmentUrl): string
    {
        $data = $this->call('/ai/tryon', ['personUrl' => $personUrl, 'garmentUrl' => $garmentUrl]);
        $taskId = trim((string) ($data['taskId'] ?? ''));
        if ($taskId === '') {
            throw new BizException(ErrorCode::TRYON_FAILED);
        }
        return $taskId;
    }

    /**
     * 查询试衣任务；成功时 imageUrl 为已转存自有 OSS 的永久 URL。
     *
     * @return array{status:string, imageUrl:string, failReason:string}
     * @throws BizException
     */
    public function query(string $taskId): array
    {
        $data = $this->call('/ai/tryon/query', ['taskId' => $taskId]);
        return [
            'status' => (string) ($data['status'] ?? 'PENDING'),
            'imageUrl' => (string) ($data['imageUrl'] ?? ''),
            'failReason' => (string) ($data['failReason'] ?? ''),
        ];
    }

    /**
     * 内网 HMAC 调用 AI 微服务。签名与发送用同一份字节（rawBody），否则 HMAC 对不上。
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     * @throws BizException
     */
    private function call(string $path, array $body): array
    {
        $params = Yii::$app->params;
        if (empty($params['ai.enabled'])) {
            throw new BizException(ErrorCode::AI_UNAVAILABLE);
        }
        $baseUrl = rtrim((string) ($params['ai.baseUrl'] ?? ''), '/');
        $secret = (string) ($params['ai.sign.secret'] ?? '');
        if ($baseUrl === '' || $secret === '') {
            throw new BizException(ErrorCode::AI_UNAVAILABLE);
        }

        $rawBody = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
        $ts = (string) time();
        $sign = hash_hmac('sha256', $ts . "\n" . $rawBody, $secret);

        try {
            // 试衣提交/查询本身是轻量转发（阿里云异步，秒回 task_id），放宽到 20s 兜住结果图转存
            $client = new Client(['timeout' => (float) ($params['ai.timeout'] ?? 5) + 15]);
            $resp = $client->post($baseUrl . $path, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Timestamp' => $ts,
                    'X-Internal-Sign' => $sign,
                ],
                'body' => $rawBody,
                'http_errors' => false,
            ]);
            if ($resp->getStatusCode() !== 200) {
                Yii::warning('AI 试衣非 200: ' . $resp->getStatusCode() . ' ' . $resp->getBody(), 'ai');
                throw new BizException(ErrorCode::TRYON_FAILED);
            }
            /** @var array<string,mixed> $data */
            $data = json_decode((string) $resp->getBody(), true) ?: [];
            return $data;
        } catch (BizException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Yii::warning('AI 试衣异常: ' . $e->getMessage(), 'ai');
            throw new BizException(ErrorCode::TRYON_FAILED);
        }
    }
}
