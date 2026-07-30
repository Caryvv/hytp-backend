# 汉韵同袍 单机部署（2C2G / 40GB SSD / 3M 带宽）

目标系统 **Debian 13（trixie）**，一台服务器跑全部服务。图片走 OSS 直传（不占本机磁盘/带宽），本机只跑 API 逻辑与小数据。

> 实测环境（腾讯云 trixie 镜像，2026-07 全线跑通）：nginx 1.26 · **MariaDB 11.8** · Redis 8.0 · **PHP 8.4.23** · Node 20+。
> 已按此适配：FPM socket 用版本无关名 `/run/php/hytp-fpm.sock`；数据库为 MariaDB（Debian 官方源无 MySQL，Yii2 `mysql` 驱动完全兼容，后端零改）。

> **⚠️ 本文档每一步都经过 2026-07 实测修订，下面几个坑照旧文档会翻车，务必按此走：**
> 1. 项目实为 **5 个库**（不是 3 个），且 **`php yii migrate` 无法重建**（迁移是扁平目录 + 手工 RANAME 分库的），只能 **mysqldump 开发库→导入**（见 §2）。
> 2. 开发机是 MySQL 8，dump 里的 collation MariaDB 不认，导入前必须 sed 替换（见 §2）。
> 3. `guzzle` 陷在 require-dev 里但却是运行时真依赖，`composer install` **不能加 `--no-dev`**（见 §2）。
> 4. **每个入口** api/merchant/admin 各需自己的 `config/params-local.php` + `config/main-local.php`（见 §5）。
> 5. `chown` 必须在**建完所有密钥文件之后**做，否则 root 属主的 `.env` www-data 读不了（见 §6）。

## 组件与内存预算（2G 是硬约束）

| 组件                 | 常驻内存         | 配置文件                                                 |
| -------------------- | ---------------- | -------------------------------------------------------- |
| 系统 / OS            | ~250MB           | —                                                       |
| MariaDB 11.8（调优） | ~350–450MB      | `mysql/hytp.cnf`（innodb 256M、关 performance_schema） |
| Redis（封顶）        | ~80MB            | `redis/hytp.conf`（maxmemory 128M、纯缓存）            |
| PHP-FPM（6 worker）  | ~270MB           | `php/hytp-fpm.conf`（pm.max_children=6）               |
| Node AI 微服务       | ~120MB           | `systemd/hytp-ai.service`（MemoryMax 256M）            |
| Nginx                | ~30MB            | `nginx/hytp.conf`                                      |
| **合计**       | **~1.2GB** | 余 ~0.8GB                                                |

**必须挂 2GB swap** 兜底突发：

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

不用 Docker：2G 内存下原生装比容器省内存、少一层运维。

## 目录约定

```
/var/www/hytp-backend/             ← PHP 后端（api/merchant/admin 三入口 + common/console）
/var/www/hytp-web/merchant/dist/   ← 商家端构建产物
/var/www/hytp-web/admin/dist/      ← 管理端构建产物
/var/www/hytp-web/ai/              ← Node AI 微服务
```

## 部署步骤

### 1. 装依赖

```bash
apt update && apt install -y nginx mariadb-server redis-server \
  php8.4-fpm php8.4-mysql php8.4-redis php8.4-curl php8.4-mbstring php8.4-bcmath php8.4-gd php8.4-xml \
  git unzip ca-certificates
# php8.4-xml 提供 dom/simplexml（composer 解析依赖树时 codeception 等需要，缺了会卡在依赖解析）
# composer
curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer
# node 20+（AI 服务需原生 --env-file / --import tsx）
curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt install -y nodejs
```

数据库初始化（**命令是 `mariadb-secure-installation`，Debian 13 无 `mysql_` 前缀符号链接**）：

```bash
mariadb-secure-installation
# root 默认 unix_socket 认证（本机 sudo mariadb 直接进，不靠密码）——"设置 root 密码"可回车跳过，
# 保持 socket 认证反而更安全；其余（删匿名用户/删 test 库/禁远程 root）一路 Y。
```

### 2. 拉代码 + 装依赖 + 建库 + 导入数据

```bash
git clone https://github.com/Caryvv/hytp-backend.git /var/www/hytp-backend
cd /var/www/hytp-backend
git config --global --add safe.directory /var/www/hytp-backend   # 消 root 拉取仓库的 dubious ownership

# ★不能加 --no-dev！guzzle（AI 情感/问答、OSS 直传都用它 new GuzzleHttp\Client）
#   陷在 require-dev 里（被 codeception 间接带入），--no-dev 会漏装它→运行时报
#   "Class GuzzleHttp\Client not found"，AI 返"暂时不在线"、OSS 传图直接炸。
#   代价：vendor 多几十 MB dev 包，MVP 机器无所谓。
composer install --optimize-autoloader
```

**建 5 个业务库 + 账号**（项目按业务域分 **5 个库**：hytp 账号 / hytp_shop 商家 / hytp_trade 交易+商品 / hytp_admin 管理 / hytp_social 社交）：

```bash
sudo mariadb <<'SQL'
CREATE DATABASE IF NOT EXISTS hytp        DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS hytp_shop   DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS hytp_trade  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS hytp_admin  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS hytp_social DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'hytp'@'localhost' IDENTIFIED BY 'dfbr2J#auMFmk!B';
GRANT ALL PRIVILEGES ON hytp.*        TO 'hytp'@'localhost';
GRANT ALL PRIVILEGES ON hytp_shop.*   TO 'hytp'@'localhost';
GRANT ALL PRIVILEGES ON hytp_trade.*  TO 'hytp'@'localhost';
GRANT ALL PRIVILEGES ON hytp_admin.*  TO 'hytp'@'localhost';
GRANT ALL PRIVILEGES ON hytp_social.* TO 'hytp'@'localhost';
FLUSH PRIVILEGES;
SQL
```

**★表结构靠 mysqldump 导入，不能用 `php yii migrate` 重建。** 原因：`console/migrations/` 是扁平目录，早期迁移 `$this->createTable()` 全写进默认 `hytp` 库，之后的分库是**手工 RENAME 挪表**完成的（仓库里没有 renameTable 迁移代码）。全新库跑 migrate 会：早期表全砸进 hytp，后期迁移（m260722+）去 `dbTrade`/`dbSocial` ALTER 时找不到表→中断。所以从开发机 dump：

```bash
# 【开发机 Windows Git Bash】导出 5 库（含种子数据：admin 账号、商品分类、home_banner）
cd /e/tool/mysql/mysql-8.0.46-winx64/bin
for db in hytp hytp_shop hytp_trade hytp_admin hytp_social; do
  ./mysqldump.exe -u hytp -p'hytp_faa63efcb0d82bb1' --single-transaction \
    --no-tablespaces --column-statistics=0 --default-character-set=utf8mb4 \
    "$db" > "/f/office_software/_phptmp/${db}.sql"
done
# scp 传到服务器（PowerShell 里 * 不展开，要用 F:\ 反斜杠列全 5 个文件；Git Bash 里 * 正常）
```

```bash
# 【服务器】★MariaDB 不认 MySQL 8 的 utf8mb4_0900_* collation，导入前必须替换，否则每张表报错
sed -i 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g; s/utf8mb4_0900_as_cs/utf8mb4_bin/g' /path/to/*.sql

for db in hytp hytp_shop hytp_trade hytp_admin hytp_social; do
  mysql -u hytp -p'dfbr2J#auMFmk!B' "$db" < "/path/to/${db}.sql"
done

rm -rf /var/www/hytp-backend/{api,merchant,admin,console}/runtime/cache/*
```

### 3. 前端构建 + AI 服务依赖

```bash
git clone https://github.com/Caryvv/hytp-web.git /var/www/hytp-web
cd /var/www/hytp-web/merchant && npm ci && npm run build   # 产出 dist/
cd /var/www/hytp-web/admin && npm ci && npm run build
cd /var/www/hytp-web/ai && npm ci --omit=dev               # tsx 已在 dependencies，--omit=dev 安全
```

> 内存紧时前端可在本地构建后只把 `dist/` 传服务器，省掉服务器上装 devDependencies 的开销。

### 4. 配置文件就位

deploy 下的 nginx/redis 配置已修正（无重复 gzip、redis 无行尾注释），可直接 cp/追加：

```bash
cp /var/www/hytp-backend/deploy/nginx/hytp.conf   /etc/nginx/conf.d/hytp.conf         # 改域名、按需开管理端 IP 白名单
cp /var/www/hytp-backend/deploy/php/hytp-fpm.conf /etc/php/8.4/fpm/pool.d/hytp.conf
cp /var/www/hytp-backend/deploy/mysql/hytp.cnf    /etc/mysql/mariadb.conf.d/hytp.cnf
cat /var/www/hytp-backend/deploy/redis/hytp.conf  >> /etc/redis/redis.conf            # 或放 /etc/redis/redis.conf.d/
cp /var/www/hytp-backend/deploy/systemd/hytp-ai.service /etc/systemd/system/

# ★Debian 默认站点占了 default_server，与本配置冲突（尤其无域名走 §7 的 IP 方案时），删其软链（可逆）
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

### 5. 填生产密钥（都在 gitignore 外，不入库）

**★每个入口都要有自己的 local 配置**，缺任一个入口的 `params-local.php` 会导致该入口 PHP Fatal（`Failed opening required .../config/params-local.php`）→ FPM 空响应（生产 `display_errors=Off`，连日志都不生成，极难排查）。真实密钥集中在 `common/config/params-local.php`，各入口那份留空即可：

```bash
cd /var/www/hytp-backend
# 三入口各建空 params-local + 带随机 cookieValidationKey 的 main-local
# （入口 main-local 别照抄开发机——它带 if(YII_ENV_DEV) 加载 debug/gii，--no-dev 没装会 fatal）
for e in api merchant admin; do
  echo '<?php return [];' > $e/config/params-local.php
  KEY=$(php -r 'echo bin2hex(random_bytes(16));')
  printf "<?php\nreturn ['components'=>['request'=>['cookieValidationKey'=>'%s']]];\n" "$KEY" > $e/config/main-local.php
done
```

集中密钥文件（照 `*-local.php.example` / `.env.example` 填）：

- `common/config/main-local.php`：**5 个** DB 连接（`db`/`dbShop`/`dbTrade`/`dbAdmin`/`dbSocial`，dsn 指向对应库）+ §2 建的账号密码。
  开发机的 `main-local.php` 里密码是 `hytp_faa63efcb0d82bb1`，替换成生产密码时 **sed 用单引号**（密码含 `!`，双引号会触发 bash 历史展开报 `event not found`）：
  ```bash
  sed -i 's/hytp_faa63efcb0d82bb1/dfbr2J#auMFmk!B/g' common/config/main-local.php
  ```
- `common/config/params-local.php`：JWT 生产密钥、`ai.sign.secret`、`upload.sts.accessKeyId/Secret`
  ```bash
  php -r "echo bin2hex(random_bytes(32));"   # 生成 JWT 密钥（三端可不同）
  ```
- `common/config/params.php`：`upload.sts.enabled = true` + region/bucket/roleArn（非密钥，可入库）
- `hytp-web/ai/.env`：`DEEPSEEK_API_KEY` + `INTERNAL_SIGN_SECRET`（**须与 params-local 的 `ai.sign.secret` 完全一致**，否则 AI 接口全 401）

### 6. 权限 + 启动

```bash
# ★chown 必须在建完上面所有密钥文件之后做。若某个 .env/params-local 是后补建的（root 属主 0600），
#   www-data 读不了——node --env-file 会报 ".env: not found"（不区分不存在/无权限），php-fpm 则白屏。
#   补建密钥文件后要单独再 chown 一次。
chown -R www-data:www-data /var/www/hytp-backend /var/www/hytp-web
chmod 600 /var/www/hytp-backend/common/config/params-local.php \
          /var/www/hytp-backend/common/config/main-local.php \
          /var/www/hytp-web/ai/.env
mkdir -p /var/www/hytp-backend/api/web/uploads && chown www-data:www-data "$_"   # 中转上传兜底目录

systemctl restart php8.4-fpm mariadb redis-server nginx
systemctl daemon-reload && systemctl enable --now hytp-ai
curl http://127.0.0.1:8790/health    # AI 服务 {"ok":true}；起不来看 journalctl -u hytp-ai
```

### 7. 对外访问：域名 + HTTPS，或无域名走 HTTP+IP

**A. 有域名（正式）** — 三入口按子域分 server。大陆服务器须先 **ICP 备案**（备主域名 1 个即覆盖所有子域，子域不单独备；未备案腾讯云封 80/443，certbot 验证连不进）：

```bash
apt install -y certbot python3-certbot-nginx
# 先把 nginx 配置里的 server_name 从 *.example.com 改成真实域名，DNS 三条 A 记录指向本机
certbot --nginx -d api.你的域名 -d merchant.你的域名 -d admin.你的域名
```

**B. 无域名/未备案（内测）** — 走 HTTP + IP。三入口靠域名分 server，IP 访问只能给一个默认站点，优先给 api（Android 联调用）：

```bash
# 给第一个 server（api）加 default_server + server_name _
sed -i '0,/    listen 80;/s//    listen 80 default_server;/' /etc/nginx/conf.d/hytp.conf
sed -i 's/    server_name api\.example\.com;/    server_name _;/' /etc/nginx/conf.d/hytp.conf
nginx -t && systemctl reload nginx
```
- 腾讯云安全组放行 **80**（本机 curl 通 ≠ 公网通）。
- Android：debug `BASE_URL` 改 `http://服务器IP/`；`network_security_config.xml` 明文白名单加该 IP（Android 9+ 默认禁明文）。
- merchant/admin 用 IP 暂访问不了（server_name 还是 example.com），要看 Web 后台需各分端口。
- **裸暴露的公网 80 会被扫描机器人扫**（日志里 `webui/`、`geoserver/web/` 等 404 噪音），内测后要收口。

### 8. 验证

```bash
# 有域名：curl https://api.你的域名/site/ping ；无域名(方案B)：直接 curl 本机/公网 IP
curl http://127.0.0.1/site/ping                  # {"code":0,...,"db":true}
curl http://127.0.0.1:8790/health                # AI 服务 {"ok":true}
systemctl is-active hytp-ai php8.4-fpm mariadb redis-server nginx   # 均 active
```

> ★`sudo -u www-data php api/web/index.php` 从 CLI 直接跑必报 `InvalidConfigException: Unable to determine the request URI`——这是 web 入口无 HTTP 上下文的**误报**，别被它误导；判据永远看走 nginx 的 curl 是否返 `code:0`。

Android 客户端把 baseUrl 改成 `https://api.你的域名/`（或方案 B 的 `http://服务器IP/`）。

---

## ⚠️ 上线安全清单（逐条确认，别裸奔）

- [ ] **改管理端默认密码**：现在是 `admin/admin123`（dump 种子数据带的），暴露公网 + 默认口令 = 任何人进后台。改强密码，**并给 admin 子域配 IP 白名单**（nginx 里已留注释），别用裸 IP 暴露 admin。
- [ ] **换 JWT 生产密钥**：`params-local.php` 现在是 `dev_access_...` 开发值，必须换随机长串（三端可不同）。旧 token 会失效，属预期。
- [ ] **密钥文件权限 600 + 属主 www-data**：`params-local.php` / `main-local.php` / `ai/.env`（含 DeepSeek key、OSS AK、JWT、HMAC secret）。
- [ ] **AI 服务不对公网**：`hytp-ai` 只 listen 127.0.0.1:8790，nginx 不反代它（HMAC 内网调用）。
- [ ] **MariaDB / Redis 只监听本机**：Redis 已 bind 127.0.0.1；MariaDB 确认 `bind-address = 127.0.0.1`（Debian 默认即本机）。
- [ ] **OSS bucket CORS 收窄**：上线后把 CORS 来源从 `*` 改成实际 App/Web 域。
- [ ] **防火墙**：只放 80/443/SSH，其余端口（3306/6379/8790/8788…）不对外。
- [ ] **关 YII_DEBUG**：生产入口 `index.php` 确认 `YII_ENV=prod`、`YII_DEBUG=false`（否则报错页泄露栈信息）。

## 容量提醒（这台机器的天花板）

- **3M 带宽 ≈ 375KB/s**：图片已走 OSS，但页面/接口仍过本机。并发高时是瓶颈，建议 OSS 前挂 CDN、静态资源也可上 CDN。
- **200GB/月流量**：图片走 OSS 后本机流量主要是 HTML/JS/API，压力小很多；仍建议监控。
- **40GB SSD**：日志要轮转（logrotate），MariaDB 数据增长要盯着。
- 这套配置适合 **MVP / 内测 / 小体量上线**；用户量起来后先拆 MariaDB 到独立实例、再加机器。
