-- 汉韵同袍 后端按业务域分库（同实例多 database）· 一次性数据迁移
-- 需 root 执行。账号域 + migration 历史表留在 hytp（默认 db 连接）。
-- 交易域+商品域合并到 hytp_trade（保下单扣库存强一致事务同库）。
-- 执行后须清各入口 runtime/cache（schema 缓存）。

-- 1. 建库（utf8mb4_0900_ai_ci，对齐现有）
CREATE DATABASE IF NOT EXISTS `hytp_shop`   DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS `hytp_trade`  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS `hytp_admin`  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS `hytp_social` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- 2. 授权现有 hytp 用户对 4 个新库全权限（同用户 → 单连接跨库读可用）
GRANT ALL PRIVILEGES ON `hytp_shop`.*   TO 'hytp'@'localhost';
GRANT ALL PRIVILEGES ON `hytp_trade`.*  TO 'hytp'@'localhost';
GRANT ALL PRIVILEGES ON `hytp_admin`.*  TO 'hytp'@'localhost';
GRANT ALL PRIVILEGES ON `hytp_social`.* TO 'hytp'@'localhost';
FLUSH PRIVILEGES;

-- 3. 搬表（同实例 RENAME 瞬时、保留数据与 seed）
-- 3.1 商家域 → hytp_shop
RENAME TABLE
  `hytp`.`shop`               TO `hytp_shop`.`shop`,
  `hytp`.`shop_qualification` TO `hytp_shop`.`shop_qualification`,
  `hytp`.`shop_credit_log`    TO `hytp_shop`.`shop_credit_log`;

-- 3.2 商品域 + 交易域 → hytp_trade
RENAME TABLE
  `hytp`.`product_category` TO `hytp_trade`.`product_category`,
  `hytp`.`product`          TO `hytp_trade`.`product`,
  `hytp`.`product_sku`      TO `hytp_trade`.`product_sku`,
  `hytp`.`product_review`   TO `hytp_trade`.`product_review`,
  `hytp`.`cart`             TO `hytp_trade`.`cart`,
  `hytp`.`shop_order`       TO `hytp_trade`.`shop_order`,
  `hytp`.`order_item`       TO `hytp_trade`.`order_item`,
  `hytp`.`payment`          TO `hytp_trade`.`payment`,
  `hytp`.`order_refund`     TO `hytp_trade`.`order_refund`,
  `hytp`.`deposit_claim`    TO `hytp_trade`.`deposit_claim`;

-- 3.3 管理域 → hytp_admin
RENAME TABLE
  `hytp`.`admin_role`            TO `hytp_admin`.`admin_role`,
  `hytp`.`admin_role_permission` TO `hytp_admin`.`admin_role_permission`,
  `hytp`.`admin_user`            TO `hytp_admin`.`admin_user`,
  `hytp`.`admin_operation_log`   TO `hytp_admin`.`admin_operation_log`,
  `hytp`.`sys_config`            TO `hytp_admin`.`sys_config`;

-- 3.4 社交域 → hytp_social
RENAME TABLE
  `hytp`.`feed`              TO `hytp_social`.`feed`,
  `hytp`.`feed_comment`      TO `hytp_social`.`feed_comment`,
  `hytp`.`feed_like`         TO `hytp_social`.`feed_like`,
  `hytp`.`feed_favorite`     TO `hytp_social`.`feed_favorite`,
  `hytp`.`follow`            TO `hytp_social`.`follow`,
  `hytp`.`chat_conversation` TO `hytp_social`.`chat_conversation`,
  `hytp`.`chat_message`      TO `hytp_social`.`chat_message`,
  `hytp`.`social_group`      TO `hytp_social`.`social_group`,
  `hytp`.`group_member`      TO `hytp_social`.`group_member`,
  `hytp`.`group_message`     TO `hytp_social`.`group_message`;

-- 账号域（user/user_oauth/user_profile_tag/member_order/address）+ migration 留在 hytp，不动。
