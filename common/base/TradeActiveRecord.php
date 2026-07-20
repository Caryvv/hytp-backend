<?php

declare(strict_types=1);

namespace common\base;

use Yii;
use yii\db\ActiveRecord;
use yii\db\Connection;

/**
 * 交易域 + 商品域基类 —— 绑定 dbTrade 连接（hytp_trade 库）。
 * 商品与交易合并同库，保下单扣库存强一致事务在单连接内完整。
 */
abstract class TradeActiveRecord extends ActiveRecord
{
    public static function getDb(): Connection
    {
        /** @var Connection $conn */
        $conn = Yii::$app->get('dbTrade');
        return $conn;
    }
}
