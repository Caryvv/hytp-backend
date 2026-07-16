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
    'sms.mock' => true,                   // true=开发 Mock（不真发，写日志）
    'sms.codeTtl' => 300,                 // 验证码有效期（秒）
    'sms.resendInterval' => 60,           // 同号重发间隔（秒）
    'sms.ipDailyLimit' => 50,             // 同 IP 每日发送上限
];
