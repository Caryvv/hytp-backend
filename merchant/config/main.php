<?php

declare(strict_types=1);

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php',
);

return [
    'id' => 'app-merchant',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'merchant\controllers',
    'components' => [
        'request' => [
            'csrfParam' => '_csrf-merchant',
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
            'identityClass' => \common\models\Shop::class,
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
                // 认证与入驻
                'POST auth/login' => 'auth/login',
                'POST auth/refresh' => 'auth/refresh',
                'POST auth/logout' => 'auth/logout',
                'POST register' => 'auth/register',
                // 店铺信息与资质
                'GET shop' => 'shop/info',
                'PUT shop' => 'shop/update',
                'PATCH shop' => 'shop/update',
                'GET qualifications' => 'shop/qualifications',
                'POST qualifications' => 'shop/add-qualification',
                // 商品管理
                'GET products' => 'product/index',
                'POST products' => 'product/create',
                'GET products/<id:\d+>' => 'product/view',
                'PUT products/<id:\d+>' => 'product/update',
                'PATCH products/<id:\d+>' => 'product/update',
                'POST products/<id:\d+>/toggle' => 'product/toggle',
                'PUT products/<id:\d+>/stock' => 'product/stock',
                // 订单管理（阶段3+）
                'GET orders' => 'order/index',
                'GET orders/<orderNo:\d+>' => 'order/view',
                'POST orders/<orderNo:\d+>/ship' => 'order/ship',
                'POST orders/<orderNo:\d+>/confirm-return' => 'order/confirm-return',
                'GET refunds' => 'order/refunds',
                'POST refunds/<id:\d+>/handle' => 'order/handle-refund',
                // 数据驾驶舱
                'GET dashboard/review-keywords' => 'dashboard/review-keywords',
                // 图片上传（OSS 直传临时凭证）
                'GET upload/sts' => 'upload/sts-token',
            ],
        ],
    ],
    'params' => $params,
];
