<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 商品域表：product_category、product、product_sku、product_review。
 * 对齐 docs/dev/02-数据库设计 §3.1、§3.2、§3.4、§3.9。
 */
class m260717_100002_create_product_tables extends Migration
{
    public function safeUp(): void
    {
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        // 商品分类（树形）
        $this->createTable('{{%product_category}}', [
            'id' => $this->primaryKey()->unsigned(),
            'parent_id' => $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('父分类，0为顶级'),
            'name' => $this->string(50)->notNull(),
            'level' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('层级 1顶级'),
            'sort' => $this->integer()->notNull()->defaultValue(0)->comment('排序，越小越前'),
            'icon' => $this->string(255)->notNull()->defaultValue(''),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_category_parent', '{{%product_category}}', ['parent_id', 'sort']);

        // 商品主表（含售卖/租赁/定制/妆造/摄影）
        $this->createTable('{{%product}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'shop_id' => $this->bigInteger()->unsigned()->notNull()->comment('所属商家'),
            'title' => $this->string(120)->notNull(),
            'category_id' => $this->integer()->unsigned()->notNull()->defaultValue(0)->comment('分类'),
            'forme_dynasty' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('形制朝代 1秦汉 2魏晋 3唐 4宋 5明 0其他'),
            'forme_type' => $this->string(30)->notNull()->defaultValue('')->comment('形制（曲裾/襦裙/袄裙/马面裙…）'),
            'style' => $this->string(30)->notNull()->defaultValue('')->comment('风格（原创/改良/童装…）'),
            'trade_type' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('1售卖 2租赁 3定制 4服务(妆造/摄影)'),
            'price' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('售价/日租金/起拍价'),
            'cover' => $this->string(255)->notNull()->defaultValue('')->comment('主图'),
            'images' => $this->json()->null()->comment('图集'),
            'detail' => 'MEDIUMTEXT NULL COMMENT \'图文详情\'',
            'tryon_model_url' => $this->string(255)->null()->comment('虚拟试穿素材'),
            'stock' => $this->integer()->notNull()->defaultValue(0)->comment('库存/可租数量'),
            'is_original' => $this->tinyInteger(1)->notNull()->defaultValue(0)->comment('是否原创认证'),
            'sales' => $this->integer()->notNull()->defaultValue(0)->comment('累计销量'),
            'rating' => $this->decimal(3, 2)->notNull()->defaultValue(0)->comment('综合评分'),
            'status' => $this->tinyInteger()->notNull()->defaultValue(2)->comment('0下架 1在售 2审核中 3违规下架'),
            'audit_remark' => $this->string(255)->notNull()->defaultValue('')->comment('审核驳回理由'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        // 用户端列表：在售+分类+销量排序；商家端：本店商品
        $this->createIndex('idx_product_list', '{{%product}}', ['status', 'category_id', 'sales']);
        $this->createIndex('idx_product_shop', '{{%product}}', ['shop_id', 'status']);
        $this->createIndex('idx_product_forme', '{{%product}}', ['forme_dynasty', 'forme_type']);

        // 商品规格（尺码/颜色）
        $this->createTable('{{%product_sku}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'product_id' => $this->bigInteger()->unsigned()->notNull(),
            'spec_json' => $this->json()->null()->comment('规格：尺码/颜色'),
            'price' => $this->decimal(10, 2)->notNull()->defaultValue(0),
            'stock' => $this->integer()->notNull()->defaultValue(0),
            'sku_code' => $this->string(50)->notNull()->defaultValue(''),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_sku_product', '{{%product_sku}}', 'product_id');

        // 商品评价（用户端只读展示，情感分析源；提交属阶段3）
        $this->createTable('{{%product_review}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'order_id' => $this->bigInteger()->unsigned()->null(),
            'product_id' => $this->bigInteger()->unsigned()->notNull(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'rating' => $this->tinyInteger()->notNull()->defaultValue(5)->comment('1-5 星'),
            'content' => $this->text()->null()->comment('评价内容'),
            'images' => $this->json()->null()->comment('晒图'),
            'sentiment' => $this->tinyInteger()->null()->comment('AI情感：0负 1中 2正'),
            'keywords' => $this->json()->null()->comment('AI提取关键词'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_review_product', '{{%product_review}}', ['product_id', 'created_at']);
        $this->createIndex('idx_review_sentiment', '{{%product_review}}', 'sentiment');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%product_review}}');
        $this->dropTable('{{%product_sku}}');
        $this->dropTable('{{%product}}');
        $this->dropTable('{{%product_category}}');
    }
}
