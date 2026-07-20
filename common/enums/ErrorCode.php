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
    public const PRODUCT_NOT_FOUND = 1204;         // 商品不存在
    public const PRODUCT_STATUS_INVALID = 1205;    // 商品状态不允许该操作
    public const CATEGORY_NOT_FOUND = 1206;        // 分类不存在
    public const PRODUCT_PARAM_INVALID = 1207;     // 商品参数校验失败
    public const CART_EMPTY = 1208;                // 购物车为空
    public const CART_ITEM_INVALID = 1209;         // 购物车项无效
    public const ORDER_NOT_FOUND = 1210;           // 订单不存在
    public const ADDRESS_NOT_FOUND = 1211;         // 收货地址不存在
    public const ADDRESS_REQUIRED = 1212;          // 缺少收货地址
    public const SKU_NOT_FOUND = 1213;             // 规格不存在
    public const REVIEW_ALREADY_EXISTS = 1214;     // 已评价过
    public const REVIEW_NOT_ALLOWED = 1215;        // 当前订单不可评价
    public const REFUND_NOT_FOUND = 1216;          // 售后单不存在
    public const RENT_PARAM_INVALID = 1217;        // 租赁参数（租期）非法
    public const DEPOSIT_CLAIM_NOT_FOUND = 1218;   // 保障金索赔单不存在
    public const DEPOSIT_CLAIM_STATUS_INVALID = 1219; // 索赔单状态不允许该操作

    // 支付 1300-1399
    public const PAY_FAIL = 1301;
    public const PAY_ORDER_NOT_FOUND = 1302;       // 支付单不存在
    public const PAY_AMOUNT_MISMATCH = 1303;       // 支付金额不符
    public const PAY_ALREADY_PAID = 1304;          // 订单已支付
    public const REFUND_STATUS_INVALID = 1305;     // 售后状态不允许该操作

    // 社交 1400-1499
    public const FEED_NOT_FOUND = 1401;         // 动态不存在
    public const FEED_STATUS_INVALID = 1402;    // 动态状态不允许该操作
    public const COMMENT_NOT_FOUND = 1403;      // 评论不存在
    public const FOLLOW_SELF = 1404;            // 不能关注自己
    public const USER_NOT_FOUND = 1405;         // 用户不存在

    // 文旅 1500-1599
    // 文化/内容 1600-1699
    // 商家端 1700-1799
    public const SHOP_NOT_AUDITED = 1701;
    public const SHOP_NOT_FOUND = 1702;            // 商家不存在
    public const SHOP_STATUS_INVALID = 1703;       // 商家状态不允许该操作
    public const SHOP_ACCOUNT_EXISTS = 1704;       // 商家账号已存在
    public const ADMIN_NO_PERMISSION = 1705;       // 后台无该权限点

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
        self::PRODUCT_NOT_FOUND => '商品不存在',
        self::PRODUCT_STATUS_INVALID => '商品状态不允许该操作',
        self::CATEGORY_NOT_FOUND => '分类不存在',
        self::PRODUCT_PARAM_INVALID => '商品参数校验失败',
        self::CART_EMPTY => '购物车为空',
        self::CART_ITEM_INVALID => '购物车商品无效',
        self::ORDER_NOT_FOUND => '订单不存在',
        self::ADDRESS_NOT_FOUND => '收货地址不存在',
        self::ADDRESS_REQUIRED => '请先选择收货地址',
        self::SKU_NOT_FOUND => '商品规格不存在',
        self::REVIEW_ALREADY_EXISTS => '该商品已评价',
        self::REVIEW_NOT_ALLOWED => '当前订单不可评价',
        self::REFUND_NOT_FOUND => '售后单不存在',
        self::FEED_NOT_FOUND => '动态不存在',
        self::FEED_STATUS_INVALID => '动态状态不允许该操作',
        self::COMMENT_NOT_FOUND => '评论不存在',
        self::FOLLOW_SELF => '不能关注自己',
        self::USER_NOT_FOUND => '用户不存在',
        self::RENT_PARAM_INVALID => '租赁租期不合法',
        self::DEPOSIT_CLAIM_NOT_FOUND => '保障金理赔单不存在',
        self::DEPOSIT_CLAIM_STATUS_INVALID => '理赔单状态不允许该操作',
        self::PAY_FAIL => '支付失败',
        self::PAY_ORDER_NOT_FOUND => '支付单不存在',
        self::PAY_AMOUNT_MISMATCH => '支付金额不符',
        self::PAY_ALREADY_PAID => '订单已支付',
        self::REFUND_STATUS_INVALID => '售后状态不允许该操作',
        self::SHOP_NOT_AUDITED => '商家未通过审核',
        self::SHOP_NOT_FOUND => '商家不存在',
        self::SHOP_STATUS_INVALID => '商家状态不允许该操作',
        self::SHOP_ACCOUNT_EXISTS => '商家账号已存在',
        self::ADMIN_NO_PERMISSION => '无该操作权限',
        self::AI_UNAVAILABLE => 'AI 服务暂不可用',
        self::INTERNAL_ERROR => '服务器内部错误',
    ];

    public static function message(int $code): string
    {
        return self::MESSAGES[$code] ?? 'unknown error';
    }
}
