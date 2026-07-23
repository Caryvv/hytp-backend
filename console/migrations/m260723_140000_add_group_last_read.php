<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * group_member 加 last_read_id：该成员在此群已读到的最大消息 id（群未读游标）。
 * 群是 1 消息 N 成员，用每成员游标比私信逐条 is_read 省。
 * 未读 = count(group_message where id > last_read_id and from_user != me)。
 *
 * ★group_member 在 hytp_social 库（已核实 SHOW TABLES），故显式取 dbSocial 加列。
 */
class m260723_140000_add_group_last_read extends Migration
{
    public function safeUp(): void
    {
        Yii::$app->get('dbSocial')->createCommand()
            ->addColumn('{{%group_member}}', 'last_read_id', "BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已读到的最大群消息id' AFTER role")
            ->execute();
    }

    public function safeDown(): void
    {
        Yii::$app->get('dbSocial')->createCommand()
            ->dropColumn('{{%group_member}}', 'last_read_id')
            ->execute();
    }
}
