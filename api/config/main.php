<?php

declare(strict_types=1);

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php',
);

return [
    'id' => 'app-api',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'api\controllers',
    'components' => [
        'request' => [
            'csrfParam' => '_csrf-api',
            'enableCsrfValidation' => false,
            'parsers' => [
                'application/json' => \yii\web\JsonParser::class,
            ],
        ],
        'response' => [
            'format' => \yii\web\Response::FORMAT_JSON,
            'charset' => 'UTF-8',
        ],
        'user' => [
            'identityClass' => \common\models\User::class,
            'enableAutoLogin' => false,
            'enableSession' => false,
            'loginUrl' => null,
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'enableStrictParsing' => false,
            'showScriptName' => false,
            'rules' => [
                // 用户资料：GET 查询 / PUT 更新（对齐 05 §5）
                'GET user/profile' => 'user/profile',
                'PUT user/profile' => 'user/update-profile',
                'PATCH user/profile' => 'user/update-profile',
                // 交易区只读浏览（白名单免登录，对齐 08）
                'GET categories' => 'category/index',
                'GET products' => 'product/index',
                'POST products/search' => 'product/search',
                'GET products/<id:\d+>' => 'product/view',
                'GET products/<id:\d+>/reviews' => 'product/reviews',
                'GET shops/<id:\d+>' => 'shop/view',
                'GET shops/<id:\d+>/products' => 'shop/products',
                // 交易闭环（阶段3，均需登录，对齐 08 §6）
                // 购物车
                'GET cart' => 'cart/index',
                'POST cart' => 'cart/add',
                'PUT cart/<id:\d+>' => 'cart/update',
                'DELETE cart/<id:\d+>' => 'cart/delete',
                'DELETE cart' => 'cart/clear',
                // 收货地址
                'GET addresses' => 'address/index',
                'POST addresses' => 'address/create',
                'PUT addresses/<id:\d+>' => 'address/update',
                'DELETE addresses/<id:\d+>' => 'address/delete',
                'POST addresses/<id:\d+>/default' => 'address/set-default',
                // 订单
                'POST orders/preview' => 'order/preview',
                'POST orders' => 'order/create',
                'GET orders' => 'order/index',
                'GET orders/<orderNo:\d+>' => 'order/view',
                'POST orders/<orderNo:\d+>/cancel' => 'order/cancel',
                'POST orders/<orderNo:\d+>/confirm' => 'order/confirm',
                'POST orders/<orderNo:\d+>/refund' => 'order/refund',
                'POST orders/<orderNo:\d+>/review' => 'order/review',
                // 支付（Mock）
                'POST pay' => 'payment/pay',
                'POST pay/mock/confirm' => 'payment/mock-confirm',
            ],
        ],
    ],
    'params' => $params,
];
