<?php

declare(strict_types=1);

use yii\db\Migration;
use yii\db\Query;

/**
 * 给超管角色补授权限点 content:manage（文旅+文化内容录入）。
 *
 * ★admin_role_permission 在 hytp_admin 库，用 dbAdmin 连接（分库后 $this->db 指向 hytp 主库）。
 */
class m260805_100002_seed_content_perm extends Migration
{
    public function safeUp(): void
    {
        $admin = Yii::$app->get('dbAdmin');

        $roleIds = (new Query())
            ->select('id')->from('admin_role')
            ->where(['name' => '超级管理员'])->column($admin);
        foreach ($roleIds as $roleId) {
            $exists = (new Query())->from('admin_role_permission')
                ->where(['role_id' => $roleId, 'permission_key' => 'content:manage'])
                ->exists($admin);
            if (!$exists) {
                $admin->createCommand()->insert('admin_role_permission', [
                    'role_id' => $roleId,
                    'permission_key' => 'content:manage',
                ])->execute();
            }
        }
    }

    public function safeDown(): void
    {
        $admin = Yii::$app->get('dbAdmin');
        $admin->createCommand()->delete('admin_role_permission', ['permission_key' => 'content:manage'])->execute();
    }
}
