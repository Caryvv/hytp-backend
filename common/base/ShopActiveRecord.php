<?php

declare(strict_types=1);

namespace common\base;

use Yii;
use yii\db\ActiveRecord;
use yii\db\Connection;

/**
 * 商家域基类 —— 绑定 dbShop 连接（hytp_shop 库）。
 */
abstract class ShopActiveRecord extends ActiveRecord
{
    public static function getDb(): Connection
    {
        /** @var Connection $conn */
        $conn = Yii::$app->get('dbShop');
        return $conn;
    }
}
