<?php

declare(strict_types=1);

namespace common\base;

use Yii;
use yii\db\ActiveRecord;
use yii\db\Connection;

/**
 * 社交域基类 —— 绑定 dbSocial 连接（hytp_social 库）。
 */
abstract class SocialActiveRecord extends ActiveRecord
{
    public static function getDb(): Connection
    {
        /** @var Connection $conn */
        $conn = Yii::$app->get('dbSocial');
        return $conn;
    }
}
