<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 用户举报动态：feed_report 表（hytp_social 库，与 feed 同库）。
 * 举报处置复用 feed:audit 权限点，不新增授权。
 *
 * ★分库后 $this->db 指向默认 hytp 库，feed 已 RENAME 到 hytp_social，
 *   故用 dbSocial 连接显式建表，不用 $this->createTable。
 */
class m260821_100001_create_feed_report extends Migration
{
    public function safeUp(): void
    {
        $social = Yii::$app->get('dbSocial');
        $opt = 'CHARACTER SET utf8mb4 ENGINE=InnoDB';

        $social->createCommand()->createTable('{{%feed_report}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'feed_id' => $this->bigInteger()->unsigned()->notNull()->comment('被举报的动态'),
            'user_id' => $this->bigInteger()->unsigned()->notNull()->comment('举报人'),
            'reason' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('举报类型 1广告 2违法 3色情 4人身攻击 5其他'),
            'detail' => $this->string(255)->notNull()->defaultValue('')->comment('补充说明'),
            'status' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('0待处理 1举报成立 2已忽略'),
            'handle_remark' => $this->string(255)->notNull()->defaultValue('')->comment('处理备注'),
            'handled_by' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0)->comment('处理管理员 id'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt)->execute();

        // 同一用户对同一动态只能有一条举报（防刷）
        $social->createCommand()->createIndex(
            'uq_report_feed_user',
            '{{%feed_report}}',
            ['feed_id', 'user_id'],
            true,
        )->execute();
        // 管理端待处理队列按状态+时间倒序
        $social->createCommand()->createIndex('idx_report_status', '{{%feed_report}}', ['status', 'id'])->execute();
    }

    public function safeDown(): void
    {
        Yii::$app->get('dbSocial')->createCommand()->dropTable('{{%feed_report}}')->execute();
    }
}
