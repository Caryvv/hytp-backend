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
                // 品质保障金理赔（阶段3+ P1）
                'GET deposit-claims' => 'deposit-claim/index',
                'POST deposit-claims/<id:\d+>/arbitrate' => 'deposit-claim/arbitrate',
                // 动态巡查（先发后审·下架/恢复）
                'GET feeds' => 'feed/index',
                'POST feeds/<id:\d+>/audit' => 'feed/audit',
                'POST feeds/<id:\d+>/review' => 'feed/review',
                // 商家处罚（扣分/封禁/解封 + 信用流水）
                'GET shops/<id:\d+>/credit-logs' => 'penalty/credit-logs',
                'POST shops/<id:\d+>/penalty' => 'penalty/penalty',
                // 平台配置
                'GET configs' => 'config/index',
                'PUT configs/<key:[\w.:-]+>' => 'config/save',
                'DELETE configs/<key:[\w.:-]+>' => 'config/remove',
                // App 版本管理（APK 分片上传 + 版本 CRUD）
                'GET app-versions' => 'app-version/index',
                'POST app-versions/chunk' => 'app-version/chunk',
                'POST app-versions/merge' => 'app-version/merge',
                'POST app-versions' => 'app-version/create',
                'PUT app-versions/<id:\d+>' => 'app-version/update',
                'POST app-versions/<id:\d+>/toggle' => 'app-version/toggle',
                'DELETE app-versions/<id:\d+>' => 'app-version/delete',
                // 文件上传 OSS 直传凭证（content:manage）
                'GET upload/sts' => 'upload/sts-token',
                // 文旅+文化内容录入（content:manage）
                'GET contents' => 'content/index',
                'POST contents' => 'content/create',
                'PUT contents/<id:\d+>' => 'content/update',
                'POST contents/<id:\d+>/toggle' => 'content/toggle',
                'GET contents/<id:\d+>/signups' => 'content/signups',
                'DELETE contents/<id:\d+>' => 'content/delete',
                // 操作日志
                'GET logs' => 'log/index',
            ],
        ],
    ],
    'params' => $params,
];
