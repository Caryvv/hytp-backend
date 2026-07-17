<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 商家域表：shop、shop_qualification、shop_credit_log。
 * 对齐 docs/dev/02-数据库设计 §2.1-2.3。
 */
class m260717_100001_create_shop_tables extends Migration
{
    public function safeUp(): void
    {
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        // 商家/店铺主表
        $this->createTable('{{%shop}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'account' => $this->string(50)->notNull()->comment('商家登录账号'),
            'password_hash' => $this->string(255)->notNull()->comment('密码哈希'),
            'name' => $this->string(100)->notNull()->defaultValue('')->comment('店铺名'),
            'logo' => $this->string(255)->notNull()->defaultValue('')->comment('店铺logo URL'),
            'type' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('1原创品牌 2手作匠人 3租赁 4妆造 5摄影 6文旅 7非遗'),
            'region' => $this->string(50)->notNull()->defaultValue('')->comment('产区（菏泽/江浙/广州/川渝…）'),
            'contact_name' => $this->string(50)->notNull()->defaultValue('')->comment('联系人'),
            'contact_phone' => $this->string(20)->notNull()->defaultValue('')->comment('联系电话'),
            'credit_score' => $this->integer()->notNull()->defaultValue(100)->comment('信用分（初始100）'),
            'deposit' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('品质保障金余额'),
            'status' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('0待审核 1正常 2驳回 3封禁'),
            'audit_remark' => $this->string(255)->notNull()->defaultValue('')->comment('驳回理由'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('uq_shop_account', '{{%shop}}', 'account', true);
        $this->createIndex('idx_shop_status', '{{%shop}}', ['status', 'type']);

        // 商家资质材料
        $this->createTable('{{%shop_qualification}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'shop_id' => $this->bigInteger()->unsigned()->notNull(),
            'qual_type' => $this->string(30)->notNull()->comment('营业执照/原创证明/授权协议'),
            'file_url' => $this->string(255)->notNull()->comment('材料文件URL（OSS）'),
            'status' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('0待审 1通过 2驳回'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_qual_shop', '{{%shop_qualification}}', 'shop_id');

        // 信用变更流水（差评/违规扣分、好评加分）
        $this->createTable('{{%shop_credit_log}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'shop_id' => $this->bigInteger()->unsigned()->notNull(),
            'change' => $this->integer()->notNull()->defaultValue(0)->comment('±分'),
            'reason' => $this->string(255)->notNull()->defaultValue('')->comment('变更原因'),
            'ref_type' => $this->string(30)->notNull()->defaultValue('')->comment('关联类型（订单/评价/处罚）'),
            'ref_id' => $this->bigInteger()->unsigned()->null()->comment('关联ID'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_credit_shop', '{{%shop_credit_log}}', 'shop_id');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%shop_credit_log}}');
        $this->dropTable('{{%shop_qualification}}');
        $this->dropTable('{{%shop}}');
    }
}
