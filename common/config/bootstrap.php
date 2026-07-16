<?php

declare(strict_types=1);

Yii::setAlias('@common', dirname(__DIR__));
Yii::setAlias('@api', dirname(dirname(__DIR__)) . '/api');
Yii::setAlias('@merchant', dirname(dirname(__DIR__)) . '/merchant');
Yii::setAlias('@admin', dirname(dirname(__DIR__)) . '/admin');
Yii::setAlias('@console', dirname(dirname(__DIR__)) . '/console');
