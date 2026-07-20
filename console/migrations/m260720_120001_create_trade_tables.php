<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * 交易域表（阶段3 P0 核心闭环）：cart、shop_order、order_item、payment、order_refund。
 * 对齐 docs/dev/02-数据库设计 §3.3、§3.5、§3.6、§3.7、§3.8。
 * 不建物理外键（关系应用层维护，便于分库迁移）；金额 DECIMAL(10,2)；时间戳 INT 秒。
 */
class m260720_120001_create_trade_tables extends Migration
{
    public function safeUp(): void
    {
        $opt = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci ENGINE=InnoDB';

        // 购物车
        $this->createTable('{{%cart}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'product_id' => $this->bigInteger()->unsigned()->notNull(),
            'sku_id' => $this->bigInteger()->unsigned()->null()->comment('规格，无规格为空'),
            'qty' => $this->integer()->notNull()->defaultValue(1)->comment('数量'),
            'trade_type' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('1售卖 2租赁 3定制 4服务'),
            'rent_start' => $this->integer()->null()->comment('租赁起（本轮不用）'),
            'rent_end' => $this->integer()->null()->comment('租赁止（本轮不用）'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_cart_user', '{{%cart}}', 'user_id');
        // 同用户同商品同规格唯一（加购合并数量）
        $this->createIndex('uq_cart_item', '{{%cart}}', ['user_id', 'product_id', 'sku_id'], true);

        // 订单主表（表名 shop_order 避开保留字 order）
        $this->createTable('{{%shop_order}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'order_no' => $this->string(32)->notNull()->comment('业务单号'),
            'user_id' => $this->bigInteger()->unsigned()->notNull()->comment('买家'),
            'shop_id' => $this->bigInteger()->unsigned()->notNull()->comment('卖家'),
            'type' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('1购买 2租赁 3定制 4文旅 5服务'),
            'total_amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('应付'),
            'pay_amount' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('实付'),
            'commission' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('平台佣金'),
            'status' => $this->tinyInteger()->notNull()->defaultValue(0)
                ->comment('0待付款 1待发货 2待收货 4已完成 5已取消 6退款/售后'),
            'rent_start' => $this->integer()->null(),
            'rent_end' => $this->integer()->null(),
            'address_id' => $this->bigInteger()->unsigned()->null()->comment('收货地址'),
            'address_snapshot' => $this->json()->null()->comment('下单时地址快照'),
            'remark' => $this->string(255)->notNull()->defaultValue(''),
            'paid_at' => $this->integer()->null(),
            'shipped_at' => $this->integer()->null(),
            'finished_at' => $this->integer()->null(),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('uq_order_no', '{{%shop_order}}', 'order_no', true);
        $this->createIndex('idx_order_user', '{{%shop_order}}', ['user_id', 'status', 'created_at']);
        $this->createIndex('idx_order_shop', '{{%shop_order}}', ['shop_id', 'status', 'created_at']);

        // 订单明细（下单快照，防商品改价/下架影响历史单）
        $this->createTable('{{%order_item}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'order_id' => $this->bigInteger()->unsigned()->notNull(),
            'product_id' => $this->bigInteger()->unsigned()->notNull(),
            'sku_id' => $this->bigInteger()->unsigned()->null(),
            'title_snapshot' => $this->string(120)->notNull()->defaultValue('')->comment('商品标题快照'),
            'spec_snapshot' => $this->json()->null()->comment('规格快照'),
            'price' => $this->decimal(10, 2)->notNull()->defaultValue(0)->comment('成交单价'),
            'qty' => $this->integer()->notNull()->defaultValue(1),
            'image_snapshot' => $this->string(255)->notNull()->defaultValue('')->comment('主图快照'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_item_order', '{{%order_item}}', 'order_id');

        // 支付流水
        $this->createTable('{{%payment}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'pay_no' => $this->string(32)->notNull()->comment('平台支付单号'),
            'order_id' => $this->bigInteger()->unsigned()->notNull(),
            'channel' => $this->tinyInteger()->notNull()->defaultValue(1)->comment('1微信 2支付宝'),
            'amount' => $this->decimal(10, 2)->notNull()->defaultValue(0),
            'status' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('0待支付 1已支付 2失败 3已退款'),
            'trade_no' => $this->string(64)->notNull()->defaultValue('')->comment('第三方交易号（Mock 留空）'),
            'notify_at' => $this->integer()->null()->comment('回调到账时间'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('uq_pay_no', '{{%payment}}', 'pay_no', true);
        $this->createIndex('idx_pay_order', '{{%payment}}', 'order_id');

        // 售后/退款
        $this->createTable('{{%order_refund}}', [
            'id' => $this->bigPrimaryKey()->unsigned(),
            'order_id' => $this->bigInteger()->unsigned()->notNull(),
            'user_id' => $this->bigInteger()->unsigned()->notNull(),
            'reason' => $this->string(255)->notNull()->defaultValue(''),
            'amount' => $this->decimal(10, 2)->notNull()->defaultValue(0),
            'status' => $this->tinyInteger()->notNull()->defaultValue(0)
                ->comment('0申请中 1同意 2拒绝 3已完成'),
            'evidence' => $this->json()->null()->comment('凭证图'),
            'handle_remark' => $this->string(255)->notNull()->defaultValue('')->comment('处理备注'),
            'created_at' => $this->integer()->notNull()->defaultValue(0),
            'updated_at' => $this->integer()->notNull()->defaultValue(0),
        ], $opt);
        $this->createIndex('idx_refund_order', '{{%order_refund}}', 'order_id');
        $this->createIndex('idx_refund_user', '{{%order_refund}}', 'user_id');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%order_refund}}');
        $this->dropTable('{{%payment}}');
        $this->dropTable('{{%order_item}}');
        $this->dropTable('{{%shop_order}}');
        $this->dropTable('{{%cart}}');
    }
}
