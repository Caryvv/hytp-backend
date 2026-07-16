<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 账号域相关表：user_oauth、user_profile_tag、member_order、address。
 * 对齐 docs/dev/02-数据库设计 §1.2-1.4、§9.1。
 */
class m260716_100002_create_user_related_tables extends Migration
{
    public function safeUp(): void
    {
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        // 第三方登录绑定
        $this->createTable('{{%user_oauth}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'provider' => $this->tinyInteger()->notNull()->comment('1微信 2QQ'),
            'openid' => $this->string(100)->notNull(),
            'unionid' => $this->string(100)->defaultValue(''),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('uq_oauth_provider_openid', '{{%user_oauth}}', ['provider', 'openid'], true);
        $this->createIndex('idx_oauth_user', '{{%user_oauth}}', 'user_id');

        // 用户兴趣标签（社交匹配）
        $this->createTable('{{%user_profile_tag}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'tag_type' => $this->string(20)->notNull()->comment('形制/风格/活动'),
            'tag_value' => $this->string(50)->notNull(),
            'weight' => $this->float()->notNull()->defaultValue(1),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_tag_user', '{{%user_profile_tag}}', 'user_id');

        // 会员开通记录
        $this->createTable('{{%member_order}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'plan' => $this->tinyInteger()->notNull()->comment('1月 2年'),
            'amount' => $this->decimal(10, 2)->notNull()->defaultValue(0),
            'pay_order_id' => $this->bigInteger()->unsigned()->null(),
            'start_at' => $this->integer()->null(),
            'expire_at' => $this->integer()->null(),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_member_user', '{{%member_order}}', 'user_id');

        // 收货地址
        $this->createTable('{{%address}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'name' => $this->string(50)->notNull(),
            'phone' => $this->string(20)->notNull(),
            'province' => $this->string(50)->notNull(),
            'city' => $this->string(50)->notNull(),
            'district' => $this->string(50)->defaultValue(''),
            'detail' => $this->string(255)->notNull(),
            'is_default' => $this->tinyInteger(1)->notNull()->defaultValue(0),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_address_user', '{{%address}}', 'user_id');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%address}}');
        $this->dropTable('{{%member_order}}');
        $this->dropTable('{{%user_profile_tag}}');
        $this->dropTable('{{%user_oauth}}');
    }
}
