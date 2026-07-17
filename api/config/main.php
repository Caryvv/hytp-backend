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
            ],
        ],
    ],
    'params' => $params,
];
