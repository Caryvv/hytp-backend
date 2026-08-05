<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 文旅 + 文化传承 内容模块（合并一张表，type 区分）+ 三张用户互动表。
 * 内容+互动性质接近社交域，落 hytp_social 库，复用 SocialActiveRecord。
 *
 * ★分库后 $this->db 指向 hytp 主库，社交库表必须用 dbSocial 连接的 createCommand 显式建
 * （参考 m260722_100001_feed_off_reason_and_perm.php）。
 */
class m260805_100001_create_content_tables extends Migration
{
    public function safeUp(): void
    {
        /** @var \yii\db\Connection $social */
        $social = Yii::$app->get('dbSocial');
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        // 内容主表（文旅 type=1 / 文化传承 type=2）
        $social->createCommand()->createTable('{{%content}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'type' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('1文旅 2文化传承'),
            'title' => $this->string(120)->notNull()->comment('标题'),
            'cover' => $this->string(255)->notNull()->defaultValue('')->comment('封面图'),
            'images' => $this->json()->null()->comment('图集 URL 列表'),
            'detail' => 'MEDIUMTEXT NULL COMMENT \'图文正文\'',
            'city' => $this->string(50)->notNull()->defaultValue('')->comment('城市/地点'),
            'category' => $this->string(50)->notNull()->defaultValue('')->comment('分类/主题标签'),
            'like_count' => $this->integer()->notNull()->defaultValue(0),
            'favorite_count' => $this->integer()->notNull()->defaultValue(0),
            'signup_count' => $this->integer()->notNull()->defaultValue(0)->comment('有效报名人数'),
            'status' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('0下架 1上线'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt)->execute();
        $social->createCommand()->createIndex('idx_content_type_status', '{{%content}}', ['type', 'status', 'created_at'])->execute();

        // 点赞（唯一键防重复）
        $social->createCommand()->createTable('{{%content_like}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'content_id' => $this->bigInteger()->unsigned()->notNull(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt)->execute();
        $social->createCommand()->createIndex('uq_content_like', '{{%content_like}}', ['content_id', 'user_id'], true)->execute();

        // 收藏（唯一键防重复）
        $social->createCommand()->createTable('{{%content_favorite}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'content_id' => $this->bigInteger()->unsigned()->notNull(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt)->execute();
        $social->createCommand()->createIndex('uq_content_favorite', '{{%content_favorite}}', ['content_id', 'user_id'], true)->execute();

        // 报名预约（唯一键：一人对一内容一条，取消/再报名走 status 翻转）
        $social->createCommand()->createTable('{{%content_signup}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'content_id' => $this->bigInteger()->unsigned()->notNull(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'name' => $this->string(50)->notNull()->defaultValue('')->comment('报名人姓名'),
            'phone' => $this->string(20)->notNull()->defaultValue('')->comment('报名人手机'),
            'quantity' => $this->integer()->notNull()->defaultValue(1)->comment('报名人数'),
            'status' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('0已取消 1报名中'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt)->execute();
        $social->createCommand()->createIndex('uq_content_signup', '{{%content_signup}}', ['content_id', 'user_id'], true)->execute();
    }

    public function safeDown(): void
    {
        /** @var \yii\db\Connection $social */
        $social = Yii::$app->get('dbSocial');
        $social->createCommand()->dropTable('{{%content_signup}}')->execute();
        $social->createCommand()->dropTable('{{%content_favorite}}')->execute();
        $social->createCommand()->dropTable('{{%content_like}}')->execute();
        $social->createCommand()->dropTable('{{%content}}')->execute();
    }
}
