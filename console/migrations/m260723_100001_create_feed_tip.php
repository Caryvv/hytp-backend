<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 动态打赏记录表 feed_tip（hytp_trade 库，与 wallet_transaction 同库）。
 *
 * 打赏是用户→用户转账：打赏者 debit(TYPE_CONSUME)、作者 credit(TYPE_GIFT)。
 * tip_no = 客户端 Idempotency-Key(UUID)，唯一键防并发/重试重复扣款（幂等锚点）。
 * txn_no 冗余关联打赏者出账流水便于对账。
 *
 * ★分库后 Migration $this->db 指向默认 hytp 库，本表属 hytp_trade，故显式取 dbTrade。
 */
class m260723_100001_create_feed_tip extends Migration
{
    public function safeUp(): void
    {
        $trade = Yii::$app->get('dbTrade');
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        $trade->createCommand()->createTable('{{%feed_tip}}', [
            'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'tip_no' => "VARCHAR(64) NOT NULL COMMENT '客户端 Idempotency-Key(UUID)，幂等/防重'",
            'feed_id' => 'BIGINT UNSIGNED NOT NULL',
            'from_user_id' => "BIGINT UNSIGNED NOT NULL COMMENT '打赏者'",
            'to_user_id' => "BIGINT UNSIGNED NOT NULL COMMENT '作者'",
            'coin' => "BIGINT NOT NULL DEFAULT 0 COMMENT '打赏同袍币'",
            'txn_no' => "VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联打赏者出账流水单号'",
            'created_at' => 'INT NOT NULL DEFAULT 0',
        ], $opt)->execute();

        // 幂等核心：同 tip_no 只能一条
        $trade->createCommand()->createIndex('uq_feed_tip_no', '{{%feed_tip}}', ['tip_no'], true)->execute();
        $trade->createCommand()->createIndex('idx_feed_tip_feed', '{{%feed_tip}}', ['feed_id'])->execute();
        $trade->createCommand()->createIndex('idx_feed_tip_from', '{{%feed_tip}}', ['from_user_id', 'created_at'])->execute();
    }

    public function safeDown(): void
    {
        Yii::$app->get('dbTrade')->createCommand()->dropTable('{{%feed_tip}}')->execute();
    }
}
