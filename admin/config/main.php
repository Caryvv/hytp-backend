<?php

declare(strict_types=1);

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php'
);

return [
    'id' => 'app-admin',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'admin\controllers',
    'bootstrap' => ['log'],
    'modules' => [],
    'components' => [
        'request' => [
            'csrfParam' => '_csrf-admin',
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
            'identityClass' => \common\models\AdminUser::class,
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
                // 认证
                'POST auth/login' => 'auth/login',
                'POST auth/refresh' => 'auth/refresh',
                'POST auth/logout' => 'auth/logout',
                // 概览
                'GET dashboard' => 'dashboard/index',
                // 商家审核
                'GET shops' => 'shop/index',
                'POST shops/<id:\d+>/audit' => 'shop/audit',
                // 商品审核
                'GET products' => 'product/index',
                'POST products/<id:\d+>/audit' => 'product/audit',
                // 订单监控 + 售后仲裁（阶段3+）
                'GET orders' => 'order/index',
                'GET orders/<orderNo:\d+>' => 'order/view',
                'GET refunds' => 'order/refunds',
                'POST refunds/<id:\d+>/arbitrate' => 'order/arbitrate',
                // 操作日志
                'GET logs' => 'log/index',
            ],
        ],
    ],
    'params' => $params,
];
