<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 用户表（对齐 docs/dev/02-数据库设计 §1.1）。
 */
class m260716_100001_create_user_table extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        $this->createTable('{{%user}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'phone' => $this->string(20)->notNull()->comment('手机号（登录）'),
            'password_hash' => $this->string(255)->notNull()->comment('密码哈希'),
            'nickname' => $this->string(50)->defaultValue('')->comment('昵称'),
            'avatar' => $this->string(255)->defaultValue('')->comment('头像URL'),
            'gender' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('0未知 1男 2女'),
            'birthday' => $this->date()->null()->comment('生日'),
            'city' => $this->string(50)->defaultValue('')->comment('常驻城市'),
            'member_level' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('0普通 1高级会员'),
            'member_expire_at' => $this->integer()->null()->comment('会员到期时间'),
            'status' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('0正常 1封禁'),
            'reg_source' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('注册来源渠道'),
            'auth_key' => $this->string(64)->notNull()->defaultValue('')->comment('登录态key'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $tableOptions);

        $this->createIndex('uq_user_phone', '{{%user}}', 'phone', true);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%user}}');
    }
}
