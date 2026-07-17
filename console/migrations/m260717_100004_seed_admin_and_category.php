<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 阶段2初始数据：超级管理员角色+权限点、默认超管账号、基础商品分类。
 *
 * 默认超管：admin / admin123（★仅开发环境，生产须改密或删除）。
 */
class m260717_100004_seed_admin_and_category extends Migration
{
    public function safeUp(): void
    {
        $now = time();

        // 超级管理员角色
        $this->insert('{{%admin_role}}', [
            'name' => '超级管理员',
            'remark' => '拥有全部权限',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = (int) $this->db->getLastInsertID();

        // 权限点
        foreach (['shop:audit', 'product:audit', 'config:edit'] as $perm) {
            $this->insert('{{%admin_role_permission}}', [
                'role_id' => $roleId,
                'permission_key' => $perm,
            ]);
        }

        // 默认超管账号 admin/admin123
        $this->insert('{{%admin_user}}', [
            'username' => 'admin',
            'password_hash' => Yii::$app->security->generatePasswordHash('admin123'),
            'real_name' => '超级管理员',
            'role_id' => $roleId,
            'status' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 基础分类（顶级）
        $categories = ['汉服上装', '汉服下装', '整套汉服', '配饰', '妆造服务', '摄影服务'];
        $sort = 0;
        foreach ($categories as $name) {
            $this->insert('{{%product_category}}', [
                'parent_id' => 0,
                'name' => $name,
                'level' => 1,
                'sort' => $sort++,
                'icon' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function safeDown(): void
    {
        $this->delete('{{%admin_user}}', ['username' => 'admin']);
        $this->delete('{{%product_category}}', ['level' => 1]);
        // 角色/权限：按名称清理
        $roleIds = (new \yii\db\Query())
            ->select('id')->from('{{%admin_role}}')
            ->where(['name' => '超级管理员'])->column($this->db);
        if ($roleIds !== []) {
            $this->delete('{{%admin_role_permission}}', ['role_id' => $roleIds]);
            $this->delete('{{%admin_role}}', ['id' => $roleIds]);
        }
    }
}
