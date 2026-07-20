<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 社交域 P0 表（阶段4）：feed、feed_comment、feed_like、feed_favorite、follow。
 * 对齐 docs/dev/02-数据库设计 §4.1-4.3。另给 user 加社交计数字段。
 * 私聊/群聊/活动/技能互助表本轮不建。不建物理外键；图片/视频存 URL（JSON 列）。
 */
class m260720_163600_create_social_tables extends Migration
{
    public function safeUp(): void
    {
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        // 动态
        $this->createTable('{{%feed}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'user_id' => $this->bigInteger()->unsigned()->notNull()->comment('作者'),
            'content' => $this->text()->notNull()->comment('文案'),
            'media_type' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('1图文 2短视频 3直播回放'),
            'media' => $this->json()->null()->comment('图/视频 URL 列表'),
            'tags' => $this->json()->null()->comment('形制/场景/单品标签'),
            'product_ids' => $this->json()->null()->comment('关联商品（种草）'),
            'city' => $this->string(50)->notNull()->defaultValue(''),
            'like_count' => $this->integer()->notNull()->defaultValue(0),
            'comment_count' => $this->integer()->notNull()->defaultValue(0),
            'favorite_count' => $this->integer()->notNull()->defaultValue(0),
            'share_count' => $this->integer()->notNull()->defaultValue(0),
            'status' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('0待审 1正常 2下架'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_feed_user', '{{%feed}}', ['user_id', 'created_at']);
        $this->createIndex('idx_feed_status', '{{%feed}}', ['status', 'created_at']);

        // 评论（盖楼 parent_id）
        $this->createTable('{{%feed_comment}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'feed_id' => $this->bigInteger()->unsigned()->notNull(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'parent_id' => $this->bigInteger()->unsigned()->null()->comment('盖楼父评论'),
            'content' => $this->string(500)->notNull(),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_comment_feed', '{{%feed_comment}}', ['feed_id', 'id']);

        // 点赞（唯一键防重复）
        $this->createTable('{{%feed_like}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'feed_id' => $this->bigInteger()->unsigned()->notNull(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('uq_like', '{{%feed_like}}', ['feed_id', 'user_id'], true);

        // 收藏（唯一键防重复）
        $this->createTable('{{%feed_favorite}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'feed_id' => $this->bigInteger()->unsigned()->notNull(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('uq_favorite', '{{%feed_favorite}}', ['feed_id', 'user_id'], true);

        // 关注关系（唯一键 user+follow_user）
        $this->createTable('{{%follow}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'user_id' => $this->bigInteger()->unsigned()->notNull()->comment('关注发起者'),
            'follow_user_id' => $this->bigInteger()->unsigned()->notNull()->comment('被关注者'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('uq_follow', '{{%follow}}', ['user_id', 'follow_user_id'], true);
        $this->createIndex('idx_follow_target', '{{%follow}}', 'follow_user_id');

        // user 社交计数字段
        $this->addColumn('{{%user}}', 'follower_count', "INT NOT NULL DEFAULT 0 COMMENT '粉丝数'");
        $this->addColumn('{{%user}}', 'following_count', "INT NOT NULL DEFAULT 0 COMMENT '关注数'");
        $this->addColumn('{{%user}}', 'feed_count', "INT NOT NULL DEFAULT 0 COMMENT '动态数'");
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%user}}', 'feed_count');
        $this->dropColumn('{{%user}}', 'following_count');
        $this->dropColumn('{{%user}}', 'follower_count');
        $this->dropTable('{{%follow}}');
        $this->dropTable('{{%feed_favorite}}');
        $this->dropTable('{{%feed_like}}');
        $this->dropTable('{{%feed_comment}}');
        $this->dropTable('{{%feed}}');
    }
}
