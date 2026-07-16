<?php

declare(strict_types=1);

namespace common\components;

use Yii;
use yii\redis\Connection;

/**
 * Redis 连接的类型化访问入口。
 *
 * Yii 的 redis 组件是运行时注册的（配置在 *-local.php），
 * 直接 `Yii::$app->redis` 静态分析无法识别类型（phpstan 报 property.notFound）。
 * 统一通过本入口拿连接，既有类型提示又便于将来替换实现。
 */
final class Redis
{
    public static function conn(): Connection
    {
        /** @var Connection $conn */
        $conn = Yii::$app->get('redis');
        return $conn;
    }
}
