<?php

declare(strict_types=1);

namespace common\enums;

/**
 * 全局错误码（对齐 docs/dev/03-后端API规范 §4）。
 * 分段：0 成功；1000-1099 通用；1100-1199 账号；1200-1299 交易；
 * 1300-1399 支付；1400-1499 社交；1500-1599 文旅；1600-1699 文化/内容；
 * 1700-1799 商家端；1800-1899 AI；5000+ 服务端内部错误。
 */
class ErrorCode
{
    // 成功
    public const SUCCESS = 0;

    // 通用 1000-1099
    public const PARAM_INVALID = 1001;      // 参数校验失败
    public const UNAUTHORIZED = 1002;       // 未登录 / token 失效
    public const FORBIDDEN = 1003;          // 无权限
    public const TOO_MANY_REQUESTS = 1004;  // 请求过于频繁
    public const NOT_FOUND = 1005;          // 资源不存在

    // 账号/会员 1100-1199
    public const SMS_SEND_FAIL = 1101;
    public const SMS_CODE_INVALID = 1102;
    public const ACCOUNT_DISABLED = 1103;
    public const OAUTH_FAIL = 1104;

    // 商品/交易 1200-1299
    public const STOCK_NOT_ENOUGH = 1201;
    public const PRODUCT_OFF_SHELF = 1202;
    public const ORDER_STATUS_INVALID = 1203;

    // 支付 1300-1399
    public const PAY_FAIL = 1301;

    // 社交 1400-1499
    // 文旅 1500-1599
    // 文化/内容 1600-1699
    // 商家端 1700-1799
    public const SHOP_NOT_AUDITED = 1701;

    // AI 1800-1899
    public const AI_UNAVAILABLE = 1801;

    // 服务端内部
    public const INTERNAL_ERROR = 5000;

    /** 默认文案，业务可覆盖。 */
    public const MESSAGES = [
        self::SUCCESS => 'success',
        self::PARAM_INVALID => '参数校验失败',
        self::UNAUTHORIZED => '未登录或登录已失效',
        self::FORBIDDEN => '无权限',
        self::TOO_MANY_REQUESTS => '请求过于频繁',
        self::NOT_FOUND => '资源不存在',
        self::SMS_SEND_FAIL => '验证码发送失败',
        self::SMS_CODE_INVALID => '验证码错误或已过期',
        self::ACCOUNT_DISABLED => '账号已被禁用',
        self::OAUTH_FAIL => '第三方登录失败',
        self::STOCK_NOT_ENOUGH => '库存不足',
        self::PRODUCT_OFF_SHELF => '商品已下架',
        self::ORDER_STATUS_INVALID => '订单状态不允许该操作',
        self::PAY_FAIL => '支付失败',
        self::SHOP_NOT_AUDITED => '商家未通过审核',
        self::AI_UNAVAILABLE => 'AI 服务暂不可用',
        self::INTERNAL_ERROR => '服务器内部错误',
    ];

    public static function message(int $code): string
    {
        return self::MESSAGES[$code] ?? 'unknown error';
    }
}
