<?php

/**
 * The manifest of files that are local to specific environment.
 * 三入口应用：api（用户端）/ merchant（商家端）/ admin（管理端）+ console。
 */
return [
    'Development' => [
        'path' => 'dev',
        'setWritable' => [
            'api/runtime',
            'api/web/assets',
            'merchant/runtime',
            'merchant/web/assets',
            'admin/runtime',
            'admin/web/assets',
            'console/runtime',
        ],
        'setExecutable' => [
            'yii',
            'yii_test',
        ],
        'setCookieValidationKey' => [
            'api/config/main-local.php',
            'merchant/config/main-local.php',
            'admin/config/main-local.php',
            'common/config/codeception-local.php',
        ],
    ],
    'Production' => [
        'path' => 'prod',
        'setWritable' => [
            'api/runtime',
            'api/web/assets',
            'merchant/runtime',
            'merchant/web/assets',
            'admin/runtime',
            'admin/web/assets',
            'console/runtime',
        ],
        'setExecutable' => [
            'yii',
        ],
        'setCookieValidationKey' => [
            'api/config/main-local.php',
            'merchant/config/main-local.php',
            'admin/config/main-local.php',
        ],
    ],
];
