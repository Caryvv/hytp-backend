<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 任务领奖记录表 user_task（hytp_trade 库）—— 记录每个用户每个任务(每周期)的完成/领奖。
 *
 * 任务定义写死在 TaskService::TASKS 常量（v1 仅 4 个固定任务，不建定义表）。
 * period_key：每日任务存 date('Ymd')，一次性任务存 ''，配合唯一键实现幂等防重复领奖。
 * txn_no 冗余关联 wallet_transaction 便于对账。
 *
 * ★分库后 Migration $this->db 指向默认 hytp 库，本表属 hytp_trade，
 *   故显式取 dbTrade 连接建表。
 */
class m260722_130001_create_user_task extends Migration
{
    public function safeUp(): void
    {
        $trade = Yii::$app->get('dbTrade');
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        $trade->createCommand()->createTable('{{%user_task}}', [
            'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'user_id' => 'BIGINT UNSIGNED NOT NULL',
            'task_key' => "VARCHAR(32) NOT NULL COMMENT '任务标识 signin/publish_feed/follow_user/first_order'",
            'period_key' => "VARCHAR(32) NOT NULL DEFAULT '' COMMENT '周期键：每日存Ymd，一次性存空'",
            'reward_coin' => "BIGINT NOT NULL DEFAULT 0 COMMENT '发放同袍币'",
            'txn_no' => "VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联 wallet_transaction 单号'",
            'created_at' => 'INT NOT NULL DEFAULT 0',
        ], $opt)->execute();

        // 幂等第二道防线：同用户同任务同周期只能一条
        $trade->createCommand()->createIndex(
            'uq_user_task',
            '{{%user_task}}',
            ['user_id', 'task_key', 'period_key'],
            true
        )->execute();
        $trade->createCommand()->createIndex('idx_user_task_user', '{{%user_task}}', ['user_id', 'created_at'])->execute();
    }

    public function safeDown(): void
    {
        Yii::$app->get('dbTrade')->createCommand()->dropTable('{{%user_task}}')->execute();
    }
}
