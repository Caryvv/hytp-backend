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
    'sms.globalDailyLimit' => 5000,        // 全局每日发送熔断（保护短信账单，防绕过号/IP 限流后烧余额）
    'sms.maxVerifyFail' => 5,              // 单号验证码连续错误上限，超则锁定并作废当前码
    'sms.verifyLockTtl' => 600,            // 验证码错误锁定时长（秒）

    // 登录接口防爆破：按 手机号+IP 滑动窗口限速
    'login.attemptWindow' => 60,           // 限速窗口（秒）
    'login.maxAttempts' => 10,             // 窗口内最大登录尝试次数

    // 人机验证（滑块/行为验证码），发短信前置。captcha.mock=true 时任意非空 token 通过（联调用）
    'captcha.enabled' => true,
    'captcha.mock' => true,

    // AI 微服务（情感分析等，内网 HTTP + HMAC；密钥在 params-local 覆盖）
    'ai.enabled' => true,                              // false 时直接走规则版，不发 HTTP
    'ai.baseUrl' => 'http://127.0.0.1:8790',           // hytp-web/ai 微服务地址
    'ai.timeout' => 5,                                 // 同步调用超时（秒），超时/失败回退规则
    'ai.sign.secret' => 'CHANGE_ME_ai_sign_secret',    // 与 AI 服务 INTERNAL_SIGN_SECRET 一致，生产在 params-local 覆盖
    'ai.sign.ttl' => 300,                              // 时间戳容差（秒）

    // 文件上传
    'upload.driver' => 'local',            // local=本地存储, oss=阿里云OSS（预留服务器中转）
    'upload.baseUrl' => '',                // 文件访问基础 URL，留空用相对路径

    // App APK 下载基础 URL（合并分片后拼下载地址）。指向 api 站点的 /downloads/ 目录。
    // 内测：http://124.220.15.182/downloads ；上 HTTPS/域名后改这里即可。留空则用相对 /downloads/。
    'app.apkBaseUrl' => 'http://124.220.15.182/downloads',

    // OSS 客户端直传（STS 临时凭证）。upload.sts.enabled=false 时客户端回退服务器中转上传。
    // 上线前在阿里云控制台准备：
    //   ① 创建 OSS bucket（公读或配 CDN），开启 CORS（允许 PUT、来源 App 域/*、暴露 ETag）
    //   ② 创建 RAM 角色，信任主账号；授权策略仅允许该 bucket 的 oss:PutObject
    //   ③ 主账号 AccessKey（AK/SK 仅填 params-local，勿入库）
    //   ④ 记录 roleArn（acs:ram::<账号id>:role/<角色名>）、region、bucket、ossEndpoint
    'upload.sts.enabled' => true,                          // true 开启客户端直传
    'upload.sts.region' => 'oss-cn-shanghai',               // OSS 区域
    'upload.sts.bucket' => 'oss-cn-shangha',                              // bucket 名
    'upload.sts.ossEndpoint' => 'oss-cn-shanghai.aliyuncs.com', // OSS 访问域名（客户端上传目标）
    'upload.sts.endpoint' => 'https://sts.aliyuncs.com/',   // STS 服务端点
    'upload.sts.roleArn' => 'acs:ram::1386042690219152:role/hytp',                             // RAM 角色 ARN
    'upload.sts.durationSeconds' => 900,                    // 临时凭证有效期（秒）
    // AK/SK 在 params-local 覆盖，勿提交：
    'upload.sts.accessKeyId' => '',
    'upload.sts.accessKeySecret' => '',
];
