<?php

declare(strict_types=1);

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/yiisoft/yii2/Yii.php';

Yii::setAlias('@common', __DIR__ . '/common');
Yii::setAlias('@api', __DIR__ . '/api');
Yii::setAlias('@merchant', __DIR__ . '/merchant');
Yii::setAlias('@admin', __DIR__ . '/admin');
Yii::setAlias('@console', __DIR__ . '/console');
