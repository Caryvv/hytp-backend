<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * tryon_task 加软删除标记 deleted（0正常 1已删）。
 * status 已占用(0处理中/1成功/2失败)，删除用独立字段。列表查询过滤 deleted=0。
 *
 * ★tryon_task 在 hytp_social 库，用 dbSocial 连接显式加列（分库后 $this->db 指向主库）。
 */
class m260811_100001_tryon_task_soft_delete extends Migration
{
    public function safeUp(): void
    {
        /** @var \yii\db\Connection $social */
        $social = Yii::$app->get('dbSocial');
        $social->createCommand()
            ->addColumn('{{%tryon_task}}', 'deleted', "TINYINT NOT NULL DEFAULT 0 COMMENT '0正常 1已删(软删)'")
            ->execute();
    }

    public function safeDown(): void
    {
        /** @var \yii\db\Connection $social */
        $social = Yii::$app->get('dbSocial');
        $social->createCommand()->dropColumn('{{%tryon_task}}', 'deleted')->execute();
    }
}
