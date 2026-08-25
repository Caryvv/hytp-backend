<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 评论软隐藏：feed_comment 加 status（1正常 0隐藏）。
 * 命中敏感词的评论落库但 status=0，仅作者本人可见、不计入评论数、对他人不可见。
 *
 * ★分库后 $this->db 指向默认 hytp 库，feed_comment 已 RENAME 到 hytp_social，
 *   故用 dbSocial 连接显式加列，不用 $this->addColumn。
 * 存量评论默认 1（正常）。
 */
class m260824_100001_feed_comment_status extends Migration
{
    public function safeUp(): void
    {
        Yii::$app->get('dbSocial')->createCommand()->addColumn(
            'feed_comment',
            'status',
            "TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0隐藏(命中敏感词软隐藏)'"
        )->execute();
    }

    public function safeDown(): void
    {
        Yii::$app->get('dbSocial')->createCommand()->dropColumn('feed_comment', 'status')->execute();
    }
}
