<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * AI 试衣：tryon_task（试衣任务，记阿里云异步任务状态）+ user_avatar（用户可复用形象照）。
 * 落 hytp_social 库（与 content 一致，复用 SocialActiveRecord）。
 *
 * ★分库后 $this->db 指向 hytp 主库，社交库表必须用 dbSocial 连接的 createCommand 显式建。
 * ★COLLATE 不硬编码：MySQL8/MariaDB 对 utf8mb4_0900_* 不兼容，只指定 charset 继承各库默认。
 */
class m260810_100001_create_tryon_tables extends Migration
{
    public function safeUp(): void
    {
        /** @var \yii\db\Connection $social */
        $social = Yii::$app->get('dbSocial');
        $opt = 'CHARACTER SET utf8mb4 ENGINE=InnoDB';

        // 试衣任务
        $social->createCommand()->createTable('{{%tryon_task}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'product_id' => $this->bigInteger()->unsigned()->notNull()->comment('试穿的商品'),
            'person_url' => $this->string(500)->notNull()->comment('人物照 OSS URL'),
            'garment_url' => $this->string(500)->notNull()->comment('服装图 OSS URL(product.tryon_model_url)'),
            'aliyun_task_id' => $this->string(64)->notNull()->defaultValue('')->comment('阿里云异步任务 id'),
            'status' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('0处理中 1成功 2失败'),
            'result_url' => $this->string(500)->notNull()->defaultValue('')->comment('结果图(已转存自有 OSS 的永久 URL)'),
            'fail_reason' => $this->string(255)->notNull()->defaultValue(''),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt)->execute();
        $social->createCommand()->createIndex('idx_tryon_user', '{{%tryon_task}}', ['user_id', 'id'])->execute();

        // 用户可复用形象照（一人可存多张）
        $social->createCommand()->createTable('{{%user_avatar}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'image_url' => $this->string(500)->notNull()->comment('形象照 OSS URL'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt)->execute();
        $social->createCommand()->createIndex('idx_avatar_user', '{{%user_avatar}}', ['user_id', 'id'])->execute();
    }

    public function safeDown(): void
    {
        /** @var \yii\db\Connection $social */
        $social = Yii::$app->get('dbSocial');
        $social->createCommand()->dropTable('{{%user_avatar}}')->execute();
        $social->createCommand()->dropTable('{{%tryon_task}}')->execute();
    }
}
