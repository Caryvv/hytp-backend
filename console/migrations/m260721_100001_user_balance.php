<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * user 表加 balance 字段（代币余额），用于代币支付。
 */
class m260721_100001_user_balance extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%user}}', 'balance', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '代币余额' AFTER `feed_count`");
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%user}}', 'balance');
    }
}
