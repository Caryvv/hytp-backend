<?php

declare(strict_types=1);

namespace common\base;

use Yii;
use yii\db\ActiveRecord;
use yii\db\Connection;

/**
 * 管理域基类 —— 绑定 dbAdmin 连接（hytp_admin 库）。
 */
abstract class AdminActiveRecord extends ActiveRecord
{
    public static function getDb(): Connection
    {
        /** @var Connection $conn */
        $conn = Yii::$app->get('dbAdmin');
        return $conn;
    }
}
