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
                'POST orders/rent' => 'order/rent',
                'POST orders/<orderNo:\d+>/cancel' => 'order/cancel',
                'POST orders/<orderNo:\d+>/confirm' => 'order/confirm',
                'POST orders/<orderNo:\d+>/return' => 'order/return',
                'POST orders/<orderNo:\d+>/refund' => 'order/refund',
                'POST orders/<orderNo:\d+>/review' => 'order/review',
                'POST orders/<orderNo:\d+>/deposit-claim' => 'order/deposit-claim',
                // 支付（Mock）
                'POST pay' => 'payment/pay',
                'POST pay/mock/confirm' => 'payment/mock-confirm',
                // 同袍币充值（Mock）
                'POST wallet/recharge' => 'wallet/recharge',
                'POST wallet/recharge/mock/confirm' => 'wallet/mock-confirm',
                'POST wallet/withdraw' => 'wallet/withdraw',
                // 任务系统（赚同袍币）
                'GET tasks' => 'task/index',
                'POST tasks/claim' => 'task/claim',
                // 文件上传
                'POST upload' => 'upload/upload',
                // 首页（免登录）
                'GET home/banners' => 'home/banners',
                'GET home/feed' => 'home/feed',
                // 社交（阶段4 P0，均需登录）
                'GET feeds' => 'feed/index',
                'POST feeds' => 'feed/create',
                'GET feeds/<id:\d+>' => 'feed/view',
                'DELETE feeds/<id:\d+>' => 'feed/delete',
                'POST feeds/<id:\d+>/like' => 'feed/like',
                'POST feeds/<id:\d+>/unlike' => 'feed/unlike',
                'POST feeds/<id:\d+>/favorite' => 'feed/favorite',
                'POST feeds/<id:\d+>/unfavorite' => 'feed/unfavorite',
                'POST feeds/<id:\d+>/share' => 'feed/share',
                'POST feeds/<id:\d+>/tip' => 'feed/tip',
                'GET feeds/<id:\d+>/comments' => 'feed/comments',
                'POST feeds/<id:\d+>/comments' => 'feed/comment',
                'POST users/<id:\d+>/follow' => 'user/follow',
                'POST users/<id:\d+>/unfollow' => 'user/unfollow',
                'GET users/<id:\d+>/profile' => 'user/public-profile',
                'GET users/<id:\d+>/feeds' => 'user/user-feeds',
                // 私信（阶段4 P1，轮询）
                'GET chat/conversations' => 'chat/conversations',
                'POST chat/open' => 'chat/open',
                'GET chat/messages' => 'chat/messages',
                'POST chat/messages' => 'chat/send',
                // 社群
                'GET groups' => 'group/index',
                'POST groups' => 'group/create',
                'GET groups/mine' => 'group/mine',
                'GET groups/<id:\d+>' => 'group/view',
                'POST groups/<id:\d+>/join' => 'group/join',
                'POST groups/<id:\d+>/quit' => 'group/quit',
                'GET groups/<id:\d+>/members' => 'group/members',
                'GET groups/<id:\d+>/messages' => 'group/messages',
                'POST groups/<id:\d+>/messages' => 'group/send',
            ],
        ],
    ],
    'params' => $params,
];
