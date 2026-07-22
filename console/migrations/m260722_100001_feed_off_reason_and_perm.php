<?php

declare(strict_types=1);

use yii\db\Migration;
use yii\db\Query;

/**
 * 动态内容审核（先发后审·巡查下架）：
 * ① feed 表加 off_reason（下架理由）—— feed 在 hytp_social 库，用 dbSocial 连接。
 * ② 给超管角色补授权限点 feed:audit —— admin_role_permission 在 hytp_admin 库，用 dbAdmin 连接。
 *
 * ★分库后 $this->db 指向默认 hytp 库，feed/admin_role_permission 已 RENAME 到别的库，
 *   所以这里显式取对应连接执行，不能用 $this->addColumn。
 */
class m260722_100001_feed_off_reason_and_perm extends Migration
{
    public function safeUp(): void
    {
        $social = Yii::$app->get('dbSocial');
        $admin = Yii::$app->get('dbAdmin');

        $social->createCommand()->addColumn(
            'feed',
            'off_reason',
            "VARCHAR(255) NOT NULL DEFAULT '' COMMENT '违规下架理由'"
        )->execute();

        // 幂等补授 feed:audit 给超管角色
        $roleIds = (new Query())
            ->select('id')->from('admin_role')
            ->where(['name' => '超级管理员'])->column($admin);
        foreach ($roleIds as $roleId) {
            $exists = (new Query())->from('admin_role_permission')
                ->where(['role_id' => $roleId, 'permission_key' => 'feed:audit'])
                ->exists($admin);
            if (!$exists) {
                $admin->createCommand()->insert('admin_role_permission', [
                    'role_id' => $roleId,
                    'permission_key' => 'feed:audit',
                ])->execute();
            }
        }
    }

    public function safeDown(): void
    {
        $social = Yii::$app->get('dbSocial');
        $admin = Yii::$app->get('dbAdmin');

        $social->createCommand()->dropColumn('feed', 'off_reason')->execute();
        $admin->createCommand()->delete('admin_role_permission', ['permission_key' => 'feed:audit'])->execute();
    }
}
