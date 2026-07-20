<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 社交 P1：私信 + 社群。对齐 docs/dev/02-数据库设计 §4.4-4.5。
 * 建 chat_conversation / chat_message / social_group / group_member / group_message。
 * 群聊消息独立 group_message 表（无 to_user/is_read）。轮询拉取，不建物理外键。
 * 活动/技能互助/精准匹配本轮不做。
 */
class m260720_174600_create_chat_group_tables extends Migration
{
    public function safeUp(): void
    {
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        // 私信会话（user_a < user_b 有序对，唯一防重复会话）
        $this->createTable('{{%chat_conversation}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'user_a' => $this->bigInteger()->unsigned()->notNull()->comment('较小 userId'),
            'user_b' => $this->bigInteger()->unsigned()->notNull()->comment('较大 userId'),
            'last_msg' => $this->string(255)->notNull()->defaultValue('')->comment('最后消息摘要'),
            'last_at' => $this->integer()->notNull()->defaultValue(0)->comment('最后消息时间'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('uq_conversation_pair', '{{%chat_conversation}}', ['user_a', 'user_b'], true);
        $this->createIndex('idx_conversation_a', '{{%chat_conversation}}', ['user_a', 'last_at']);
        $this->createIndex('idx_conversation_b', '{{%chat_conversation}}', ['user_b', 'last_at']);

        // 私信消息
        $this->createTable('{{%chat_message}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'conversation_id' => $this->bigInteger()->unsigned()->notNull(),
            'from_user' => $this->bigInteger()->unsigned()->notNull(),
            'to_user' => $this->bigInteger()->unsigned()->notNull(),
            'content' => $this->string(1000)->notNull(),
            'msg_type' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('1文本 2图片'),
            'is_read' => $this->tinyInteger(1)->notNull()->defaultValue(0),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_msg_conversation', '{{%chat_message}}', ['conversation_id', 'id']);

        // 社群
        $this->createTable('{{%social_group}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'name' => $this->string(50)->notNull(),
            'type' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('1地域 2形制 3兴趣 4男性同袍'),
            'owner_id' => $this->bigInteger()->unsigned()->notNull()->comment('群主'),
            'avatar' => $this->string(255)->notNull()->defaultValue(''),
            'intro' => $this->string(255)->notNull()->defaultValue('')->comment('群简介'),
            'city' => $this->string(50)->notNull()->defaultValue(''),
            'member_count' => $this->integer()->notNull()->defaultValue(0),
            'status' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('0解散 1正常'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_group_type', '{{%social_group}}', ['type', 'status']);
        $this->createIndex('idx_group_owner', '{{%social_group}}', 'owner_id');

        // 群成员（唯一键 group+user 防重复加入）
        $this->createTable('{{%group_member}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'group_id' => $this->bigInteger()->unsigned()->notNull(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'role' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('0成员 1管理 2群主'),
            'joined_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('uq_group_member', '{{%group_member}}', ['group_id', 'user_id'], true);
        $this->createIndex('idx_member_user', '{{%group_member}}', 'user_id');

        // 群消息
        $this->createTable('{{%group_message}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'group_id' => $this->bigInteger()->unsigned()->notNull(),
            'from_user' => $this->bigInteger()->unsigned()->notNull(),
            'content' => $this->string(1000)->notNull(),
            'msg_type' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('1文本 2图片'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_gmsg_group', '{{%group_message}}', ['group_id', 'id']);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%group_message}}');
        $this->dropTable('{{%group_member}}');
        $this->dropTable('{{%social_group}}');
        $this->dropTable('{{%chat_message}}');
        $this->dropTable('{{%chat_conversation}}');
    }
}
