<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 首页轮播图表 home_banner（hytp 库）。
 *
 * 管理端暂不提供 CRUD 接口，seed 写入测试数据。
 */
class m260723_000001_create_home_banner extends Migration
{
    public function safeUp(): void
    {
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        $this->createTable('{{%home_banner}}', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'title' => "VARCHAR(100) NOT NULL DEFAULT '' COMMENT '展示标题'",
            'image_url' => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '图片 URL'",
            'link_type' => "TINYINT NOT NULL DEFAULT 0 COMMENT '0=无跳转 1=商品 2=外部链接'",
            'link_value' => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT '跳转目标'",
            'sort_order' => "INT NOT NULL DEFAULT 0 COMMENT '排序(越小越前)'",
            'status' => "TINYINT NOT NULL DEFAULT 1 COMMENT '1=启用 0=禁用'",
            'created_at' => 'INT NOT NULL DEFAULT 0',
            'updated_at' => 'INT NOT NULL DEFAULT 0',
        ], $opt);

        $this->createIndex('idx_home_banner_status_sort', '{{%home_banner}}', ['status', 'sort_order']);

        // seed 2 条测试 banner
        $now = time();
        $this->insert('{{%home_banner}}', [
            'title' => '国风雅韵 · 汉服之美',
            'image_url' => 'https://via.placeholder.com/720x280/D6E0E6/2E4756?text=%E5%9B%BD%E9%A3%8E%E9%9B%85%E9%9F%B5%C2%B7%E6%B1%89%E6%9C%8D%E4%B9%8B%E7%BE%8E',
            'link_type' => 0,
            'link_value' => '',
            'sort_order' => 0,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insert('{{%home_banner}}', [
            'title' => '新人礼包限时领取',
            'image_url' => 'https://via.placeholder.com/720x280/F6DCDC/C8464B?text=%E6%96%B0%E4%BA%BA%E7%A4%BC%E5%8C%85%E9%99%90%E6%97%B6%E9%A2%86%E5%8F%96',
            'link_type' => 0,
            'link_value' => '',
            'sort_order' => 1,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%home_banner}}');
    }
}
