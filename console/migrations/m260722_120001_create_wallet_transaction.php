<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 同袍币钱包流水表 wallet_transaction（hytp_trade 库）。
 *
 * 币制：user.balance 仍存 DECIMAL(10,2) 元；1 同袍币 = 0.01 元 = 1 分。
 * 流水 amount / balance_after 直接以「同袍币」整数记账（+入账 -出账），展示无需换算。
 * 覆盖充值 / 任务奖励(二期) / 消费 / 退款 / 系统赠送，为二期任务系统预留统一入口。
 *
 * txn_no 唯一 —— 每条流水自身单号。ref_type/ref_id 为业务关联（非唯一，
 * 同一订单可有消费+退款两条），充值幂等由 service 按 ref 查重保证。
 *
 * ★分库后 Migration $this->db 指向默认 hytp 库，本表属 hytp_trade，
 *   故显式取 dbTrade 连接建表，不用 $this->createTable。
 */
class m260722_120001_create_wallet_transaction extends Migration
{
    public function safeUp(): void
    {
        $trade = Yii::$app->get('dbTrade');
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        $trade->createCommand()->createTable('{{%wallet_transaction}}', [
            'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'txn_no' => "VARCHAR(32) NOT NULL COMMENT '流水单号'",
            'user_id' => 'BIGINT UNSIGNED NOT NULL',
            'type' => "TINYINT NOT NULL COMMENT '1充值 2任务奖励 3消费 4退款 5系统赠送'",
            'amount' => "BIGINT NOT NULL COMMENT '同袍币变动，+入账 -出账'",
            'balance_after' => "BIGINT NOT NULL COMMENT '变动后余额（同袍币）'",
            'channel' => "TINYINT NOT NULL DEFAULT 0 COMMENT '0无 1同袍币 2微信 3支付宝'",
            'ref_type' => "VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联业务类型 order/recharge/task'",
            'ref_id' => "VARCHAR(64) NOT NULL DEFAULT '' COMMENT '关联业务ID/单号'",
            'remark' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'status' => "TINYINT NOT NULL DEFAULT 1 COMMENT '0待到账 1已到账 2失败'",
            'created_at' => 'INT NOT NULL DEFAULT 0',
            'updated_at' => 'INT NOT NULL DEFAULT 0',
        ], $opt)->execute();

        $trade->createCommand()->createIndex('uq_wallet_txn_no', '{{%wallet_transaction}}', 'txn_no', true)->execute();
        $trade->createCommand()->createIndex('idx_wallet_user', '{{%wallet_transaction}}', ['user_id', 'created_at'])->execute();
        // 充值/业务查重用（非唯一：同订单有消费+退款两条）
        $trade->createCommand()->createIndex('idx_wallet_ref', '{{%wallet_transaction}}', ['ref_type', 'ref_id'])->execute();
    }

    public function safeDown(): void
    {
        Yii::$app->get('dbTrade')->createCommand()->dropTable('{{%wallet_transaction}}')->execute();
    }
}
