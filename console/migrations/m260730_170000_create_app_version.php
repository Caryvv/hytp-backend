<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * App 版本表 app_version（hytp 主库）——应用内更新检查。
 *
 * 管理端暂不提供 CRUD，seed 写入当前版本（version_code=1）。
 */
class m260730_170000_create_app_version extends Migration
{
    public function safeUp(): void
    {
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        $this->createTable('{{%app_version}}', [
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'platform' => "VARCHAR(20) NOT NULL DEFAULT 'android' COMMENT '平台 android/ios'",
            'version_code' => "INT NOT NULL DEFAULT 1 COMMENT '版本号(递增整数)'",
            'version_name' => "VARCHAR(30) NOT NULL DEFAULT '' COMMENT '版本名 如 1.0.0'",
            'update_log' => "TEXT NULL COMMENT '更新说明'",
            'download_url' => "VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'APK 下载地址'",
            'force_update' => "TINYINT NOT NULL DEFAULT 0 COMMENT '1=强制更新'",
            'min_supported_code' => "INT NOT NULL DEFAULT 0 COMMENT '低于此版本号强制升级'",
            'enabled' => "TINYINT NOT NULL DEFAULT 1 COMMENT '1=启用 0=下线'",
            'created_at' => 'INT NOT NULL DEFAULT 0',
            'updated_at' => 'INT NOT NULL DEFAULT 0',
        ], $opt);

        $this->createIndex('idx_app_version_lookup', '{{%app_version}}', ['platform', 'enabled', 'version_code']);

        $now = time();
        $this->insert('{{%app_version}}', [
            'platform' => 'android',
            'version_code' => 1,
            'version_name' => '1.0',
            'update_log' => '首个版本',
            'download_url' => '',
            'force_update' => 0,
            'min_supported_code' => 0,
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%app_version}}');
    }
}
