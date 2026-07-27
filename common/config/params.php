<?php

declare(strict_types=1);

return [
    'adminEmail' => 'admin@example.com',
    'supportEmail' => 'support@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',
    'user.passwordResetTokenExpire' => 3600,
    'user.passwordMinLength' => 8,

    // JWT（密钥在 *-local.php 覆盖，勿把真实密钥提交入库）
    'jwt.accessTtl' => 2 * 3600,          // access token 有效期（秒）
    'jwt.refreshTtl' => 30 * 24 * 3600,   // refresh token 有效期（秒）
    'jwt.issuer' => 'hytp',
    'jwt.accessSecret' => 'CHANGE_ME_access_secret',   // 生产必须在 params-local 覆盖
    'jwt.refreshSecret' => 'CHANGE_ME_refresh_secret', // 生产必须在 params-local 覆盖

    // 短信验证码
    'sms.driver' => 'mock',                // mock=开发Mock, aliyun=真实阿里云（预留）
    'sms.mock' => true,                    // true=开发 Mock（不真发，写日志）
    'sms.codeTtl' => 300,                  // 验证码有效期（秒）
    'sms.resendInterval' => 60,            // 同号重发间隔（秒）
    'sms.ipDailyLimit' => 50,              // 同 IP 每日发送上限

    // AI 微服务（情感分析等，内网 HTTP + HMAC；密钥在 params-local 覆盖）
    'ai.enabled' => true,                              // false 时直接走规则版，不发 HTTP
    'ai.baseUrl' => 'http://127.0.0.1:8790',           // hytp-web/ai 微服务地址
    'ai.timeout' => 5,                                 // 同步调用超时（秒），超时/失败回退规则
    'ai.sign.secret' => 'CHANGE_ME_ai_sign_secret',    // 与 AI 服务 INTERNAL_SIGN_SECRET 一致，生产在 params-local 覆盖
    'ai.sign.ttl' => 300,                              // 时间戳容差（秒）

    // 文件上传
    'upload.driver' => 'local',            // local=本地存储, oss=阿里云OSS（预留）
    'upload.baseUrl' => '',                // 文件访问基础 URL，留空用相对路径
    // OSS 预留配置（driver=oss 时生效）
    // 'upload.oss.accessKeyId' => '',
    // 'upload.oss.accessKeySecret' => '',
    // 'upload.oss.endpoint' => '',
    // 'upload.oss.bucket' => '',
];
