<?php

declare(strict_types=1);

use yii\db\Migration;
use yii\db\Query;

/**
 * 管理端处罚 + 平台配置：
 * ① 给超管角色补授权限点 shop:penalty。
 * ② seed 平台佣金比例 trade.commission_rate=0.06（此前只有代码默认值，表里没有，补上使其可在配置页编辑）。
 *
 * ★admin_role_permission / sys_config 均在 hytp_admin 库，用 dbAdmin 连接（分库后 $this->db 指向 hytp 主库）。
 */
class m260730_162000_penalty_perm_and_config_seed extends Migration
{
    public function safeUp(): void
    {
        $admin = Yii::$app->get('dbAdmin');

        // 幂等补授 shop:penalty 给超管角色
        $roleIds = (new Query())
            ->select('id')->from('admin_role')
            ->where(['name' => '超级管理员'])->column($admin);
        foreach ($roleIds as $roleId) {
            $exists = (new Query())->from('admin_role_permission')
                ->where(['role_id' => $roleId, 'permission_key' => 'shop:penalty'])
                ->exists($admin);
            if (!$exists) {
                $admin->createCommand()->insert('admin_role_permission', [
                    'role_id' => $roleId,
                    'permission_key' => 'shop:penalty',
                ])->execute();
            }
        }

        // 幂等 seed 佣金比例
        $has = (new Query())->from('sys_config')
            ->where(['config_key' => 'trade.commission_rate'])->exists($admin);
        if (!$has) {
            $now = time();
            $admin->createCommand()->insert('sys_config', [
                'config_key' => 'trade.commission_rate',
                'config_value' => '0.06',
                'remark' => '平台佣金比例',
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
        }
    }

    public function safeDown(): void
    {
        $admin = Yii::$app->get('dbAdmin');
        $admin->createCommand()->delete('admin_role_permission', ['permission_key' => 'shop:penalty'])->execute();
        $admin->createCommand()->delete('sys_config', ['config_key' => 'trade.commission_rate'])->execute();
    }
}
