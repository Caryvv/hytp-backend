<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 交易P1：租赁 + 品质保障金赔付。
 * ① shop_order 加 deposit_amount(押金) / deposit_refunded(押金是否已退) / returned_at(归还时间)。
 * ② 新建 deposit_claim(品质保障金赔付记录，对齐 docs/dev/02-数据库设计 §3.11)。
 * ③ 给超管角色补权限点 deposit:arbitrate。
 */
class m260720_150800_rent_and_deposit_claim extends Migration
{
    public function safeUp(): void
    {
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        // 押金字段（addColumn 用 SQL 字符串，避 phpstan ColumnSchemaBuilder 类型报错）
        $this->addColumn('{{%shop_order}}', 'deposit_amount', "DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '押金（租赁）' AFTER `commission`");
        $this->addColumn('{{%shop_order}}', 'deposit_refunded', "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '押金是否已退' AFTER `deposit_amount`");
        $this->addColumn('{{%shop_order}}', 'returned_at', "INT NULL COMMENT '归还确认时间' AFTER `finished_at`");

        // 品质保障金赔付记录
        $this->createTable('{{%deposit_claim}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'order_id' => $this->bigInteger()->unsigned()->notNull(),
            'shop_id' => $this->bigInteger()->unsigned()->notNull(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('索赔/赔付金额'),
            'reason' => $this->string(255)->notNull()->defaultValue('')->comment('山品/质量不符等'),
            'evidence' => $this->json()->null()->comment('证据图'),
            'status' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('0待判定 1成立赔付 2驳回'),
            'handle_remark' => $this->string(255)->notNull()->defaultValue('')->comment('管理员判定备注'),
            'admin_id' => $this->bigInteger()->unsigned()->null()->comment('判定管理员'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_claim_order', '{{%deposit_claim}}', 'order_id');
        $this->createIndex('idx_claim_shop', '{{%deposit_claim}}', 'shop_id');
        $this->createIndex('idx_claim_status', '{{%deposit_claim}}', 'status');

        // 超管补权限点 deposit:arbitrate（幂等）
        $roleIds = (new \yii\db\Query())
            ->select('id')->from('{{%admin_role}}')
            ->where(['name' => '超级管理员'])->column($this->db);
        foreach ($roleIds as $roleId) {
            $exists = (new \yii\db\Query())->from('{{%admin_role_permission}}')
                ->where(['role_id' => $roleId, 'permission_key' => 'deposit:arbitrate'])->exists($this->db);
            if (!$exists) {
                $this->insert('{{%admin_role_permission}}', [
                    'role_id' => $roleId,
                    'permission_key' => 'deposit:arbitrate',
                ]);
            }
        }
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%deposit_claim}}');
        $this->dropColumn('{{%shop_order}}', 'returned_at');
        $this->dropColumn('{{%shop_order}}', 'deposit_refunded');
        $this->dropColumn('{{%shop_order}}', 'deposit_amount');
        $this->delete('{{%admin_role_permission}}', ['permission_key' => 'deposit:arbitrate']);
    }
}
