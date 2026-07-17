<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 平台与运营域表：admin_user、admin_role、admin_role_permission、admin_operation_log、sys_config。
 * 对齐 docs/dev/02-数据库设计 §9.6、§9.7。
 */
class m260717_100003_create_admin_tables extends Migration
{
    public function safeUp(): void
    {
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        // 后台角色
        $this->createTable('{{%admin_role}}', [
            'id' => $this->primaryKey()->unsigned(),
            'name' => $this->string(50)->notNull()->comment('超级管理员/普通管理员'),
            'remark' => $this->string(255)->notNull()->defaultValue(''),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);

        // 角色-权限点
        $this->createTable('{{%admin_role_permission}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'role_id' => $this->integer()->unsigned()->notNull(),
            'permission_key' => $this->string(50)->notNull()->comment('权限点如 shop:audit / product:audit / config:edit'),
        ], $opt);
        $this->createIndex('uq_role_permission', '{{%admin_role_permission}}', ['role_id', 'permission_key'], true);

        // 后台管理员
        $this->createTable('{{%admin_user}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'username' => $this->string(50)->notNull(),
            'password_hash' => $this->string(255)->notNull(),
            'real_name' => $this->string(50)->notNull()->defaultValue(''),
            'role_id' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'status' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('0正常 1禁用'),
            'last_login_at' => $this->integer()->null(),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('uq_admin_username', '{{%admin_user}}', 'username', true);

        // 后台操作日志（审核/处罚等写操作留痕）
        $this->createTable('{{%admin_operation_log}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'admin_id' => $this->bigInteger()->unsigned()->notNull(),
            'action' => $this->string(50)->notNull()->comment('操作动作，如 shop.audit'),
            'module' => $this->string(30)->notNull()->defaultValue('')->comment('模块，如 shop/product'),
            'detail' => $this->text()->null()->comment('操作详情'),
            'ip' => $this->string(45)->notNull()->defaultValue(''),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_oplog_admin', '{{%admin_operation_log}}', ['admin_id', 'created_at']);

        // 平台参数（佣金比例、保证金金额、活动规则等）
        $this->createTable('{{%sys_config}}', [
            'id' => $this->primaryKey()->unsigned(),
            'config_key' => $this->string(64)->notNull()->comment('参数键'),
            'config_value' => $this->text()->null()->comment('参数值'),
            'remark' => $this->string(255)->notNull()->defaultValue(''),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('uq_sys_config_key', '{{%sys_config}}', 'config_key', true);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%sys_config}}');
        $this->dropTable('{{%admin_operation_log}}');
        $this->dropTable('{{%admin_user}}');
        $this->dropTable('{{%admin_role_permission}}');
        $this->dropTable('{{%admin_role}}');
    }
}
