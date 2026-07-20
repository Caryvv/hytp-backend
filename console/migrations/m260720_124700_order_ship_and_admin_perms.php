<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 阶段3+ 商家/管理端订单管理：
 * ① shop_order 加物流字段 express_company / express_no（商家发货填写）。
 * ② 给超管角色补授订单管理权限点 order:manage / refund:arbitrate。
 */
class m260720_124700_order_ship_and_admin_perms extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%shop_order}}', 'express_company', "VARCHAR(50) NOT NULL DEFAULT '' COMMENT '物流公司' AFTER `remark`");
        $this->addColumn('{{%shop_order}}', 'express_no', "VARCHAR(50) NOT NULL DEFAULT '' COMMENT '物流单号' AFTER `express_company`");

        // 给现有超管角色补订单权限点（幂等：先查角色）
        $roleIds = (new \yii\db\Query())
            ->select('id')->from('{{%admin_role}}')
            ->where(['name' => '超级管理员'])->column($this->db);
        foreach ($roleIds as $roleId) {
            foreach (['order:manage', 'refund:arbitrate'] as $perm) {
                $exists = (new \yii\db\Query())->from('{{%admin_role_permission}}')
                    ->where(['role_id' => $roleId, 'permission_key' => $perm])->exists($this->db);
                if (!$exists) {
                    $this->insert('{{%admin_role_permission}}', [
                        'role_id' => $roleId,
                        'permission_key' => $perm,
                    ]);
                }
            }
        }
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%shop_order}}', 'express_no');
        $this->dropColumn('{{%shop_order}}', 'express_company');
        $this->delete('{{%admin_role_permission}}', ['permission_key' => ['order:manage', 'refund:arbitrate']]);
    }
}
