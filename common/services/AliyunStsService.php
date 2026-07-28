<?php

declare(strict_types=1);

namespace common\services;

use GuzzleHttp\Client;
use Yii;

/**
 * 阿里云 STS 临时凭证（供客户端直传 OSS，字节不经服务器）。
 *
 * 用现成 guzzle 手签 AssumeRole（RPC 风格 HMAC-SHA1，无新依赖），换取限权临时凭证：
 * 只允许在该用户目录前缀下 PutObject，凭证短期过期。主账号 AK 仅存服务端 params-local。
 *
 * doc 14/OSS 直传：未配置时 enabled()=false，客户端回退服务器中转上传，本地开发不受影响。
 * ponytail: RPC v1 手签，无需引 aliyun SDK；签名逻辑对齐官方黄金样例可确定性自检。
 */
class AliyunStsService
{
    /** STS 是否已启用且配置完整（缺任一必填项即视为未启用，客户端回退中转）。 */
    public function enabled(): bool
    {
        $p = Yii::$app->params;
        return !empty($p['upload.sts.enabled'])
            && !empty($p['upload.sts.accessKeyId'])
            && !empty($p['upload.sts.accessKeySecret'])
            && !empty($p['upload.sts.roleArn'])
            && !empty($p['upload.sts.bucket']);
    }

    /**
     * 换取临时凭证。返回客户端直传 OSS 所需的全部信息。
     *
     * @return array{
     *   enabled:bool, accessKeyId:string, accessKeySecret:string, securityToken:string,
     *   expiration:string, region:string, bucket:string, endpoint:string, dir:string
     * }
     * @throws \RuntimeException STS 调用失败（控制器捕获后返 enabled=false 让客户端回退）
     */
    public function assumeRole(int $userId): array
    {
        $p = Yii::$app->params;
        $region = (string) ($p['upload.sts.region'] ?? 'oss-cn-hangzhou');
        $bucket = (string) $p['upload.sts.bucket'];
        // 用户目录前缀：app/{userId}/{YYYYMM}/，STS 权限也限制在此前缀，越权写不进去
        $dir = 'app/' . $userId . '/' . date('Ym') . '/';

        $policy = json_encode([
            'Version' => '1',
            'Statement' => [[
                'Effect' => 'Allow',
                'Action' => ['oss:PutObject'],
                'Resource' => ['acs:oss:*:*:' . $bucket . '/' . $dir . '*'],
            ]],
        ], JSON_UNESCAPED_SLASHES);

        $params = [
            'Action' => 'AssumeRole',
            'RoleArn' => (string) $p['upload.sts.roleArn'],
            'RoleSessionName' => 'hytp-' . $userId,
            'DurationSeconds' => (string) ($p['upload.sts.durationSeconds'] ?? 900),
            'Policy' => (string) $policy,
            'Format' => 'JSON',
            'Version' => '2015-04-01',
            'AccessKeyId' => (string) $p['upload.sts.accessKeyId'],
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $params['Signature'] = $this->signRpc('GET', $params, (string) $p['upload.sts.accessKeySecret']);

        $stsEndpoint = (string) ($p['upload.sts.endpoint'] ?? 'https://sts.aliyuncs.com/');
        $client = new Client(['timeout' => 5]);
        $resp = $client->get($stsEndpoint, ['query' => $params, 'http_errors' => false]);
        if ($resp->getStatusCode() !== 200) {
            throw new \RuntimeException('STS AssumeRole 失败: ' . $resp->getStatusCode() . ' ' . $resp->getBody());
        }
        /** @var array{Credentials?:array{AccessKeyId?:string,AccessKeySecret?:string,SecurityToken?:string,Expiration?:string}} $data */
        $data = json_decode((string) $resp->getBody(), true) ?: [];
        $cred = $data['Credentials'] ?? null;
        if (!is_array($cred) || empty($cred['SecurityToken'])) {
            throw new \RuntimeException('STS 响应缺少 Credentials');
        }

        return [
            'enabled' => true,
            'accessKeyId' => (string) $cred['AccessKeyId'],
            'accessKeySecret' => (string) $cred['AccessKeySecret'],
            'securityToken' => (string) $cred['SecurityToken'],
            'expiration' => (string) $cred['Expiration'],
            'region' => $region,
            'bucket' => $bucket,
            'endpoint' => (string) ($p['upload.sts.ossEndpoint'] ?? $region . '.aliyuncs.com'),
            'dir' => $dir,
        ];
    }

    /**
     * 阿里云 RPC v1 签名（HMAC-SHA1）。对齐官方文档黄金样例，可确定性自检。
     * StringToSign = Method + "&" + enc("/") + "&" + enc(排序后的规范化 query)
     *
     * @param array<string,string> $params 不含 Signature 的全部参数
     */
    public function signRpc(string $method, array $params, string $accessKeySecret): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $this->percentEncode($k) . '=' . $this->percentEncode((string) $v);
        }
        $canonical = implode('&', $pairs);
        $stringToSign = $method . '&' . $this->percentEncode('/') . '&' . $this->percentEncode($canonical);
        return base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
    }

    /** 阿里云 POP 专用百分号编码：空格→%20，*→%2A，%7E→~。 */
    private function percentEncode(string $value): string
    {
        $res = rawurlencode($value);
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], $res);
    }
}
