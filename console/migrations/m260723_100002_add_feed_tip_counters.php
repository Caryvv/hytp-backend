<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * feed 表加打赏计数：tip_count(打赏人次)、tip_coin(累计收到同袍币)。
 * 复用 Feed::updateAllCounters 计数模式，动态卡片展示打赏热度（社交证明）。
 *
 * ★feed 表在 hytp_social 库（已核实 SHOW TABLES），故显式取 dbSocial 加列。
 */
class m260723_100002_add_feed_tip_counters extends Migration
{
    public function safeUp(): void
    {
        $social = Yii::$app->get('dbSocial');
        $social->createCommand()->addColumn('{{%feed}}', 'tip_count', "INT NOT NULL DEFAULT 0 COMMENT '打赏人次' AFTER share_count")->execute();
        $social->createCommand()->addColumn('{{%feed}}', 'tip_coin', "BIGINT NOT NULL DEFAULT 0 COMMENT '累计收到同袍币' AFTER tip_count")->execute();
    }

    public function safeDown(): void
    {
        $social = Yii::$app->get('dbSocial');
        $social->createCommand()->dropColumn('{{%feed}}', 'tip_coin')->execute();
        $social->createCommand()->dropColumn('{{%feed}}', 'tip_count')->execute();
    }
}
