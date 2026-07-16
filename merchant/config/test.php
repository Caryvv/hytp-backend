<?php

declare(strict_types=1);

return [
    'id' => 'app-merchant-tests',
    'components' => [
        'assetManager' => [
            'basePath' => __DIR__ . '/../web/assets',
        ],
        'urlManager' => [
            'showScriptName' => true,
        ],
        'request' => [
            'cookieValidationKey' => 'test',
        ],
        'mailer' => [
            'messageClass' => \yii\symfonymailer\Message::class,
            'viewPath' => '@common/mail',
        ],
    ],
];
