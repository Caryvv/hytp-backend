# 汉韵同袍 单机部署（2C2G / 40GB SSD / 3M 带宽）

目标系统 **Debian 13（trixie）**，一台服务器跑全部服务。图片走 OSS 直传（不占本机磁盘/带宽），本机只跑 API 逻辑与小数据。

> 实测环境（腾讯云 trixie 镜像，2026-07 确认）：nginx 1.26 · **MariaDB 11.8** · Redis 8.0 · **PHP 8.4.23** · Node 20+。
> 已按此适配：FPM socket 用版本无关名 `/run/php/hytp-fpm.sock`；数据库为 MariaDB（Debian 官方源无 MySQL，Yii2 `mysql` 驱动完全兼容，后端零改）。

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
/var/www/hytp-backend/             ← PHP 后端（api/merchant/admin 三入口）
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
# php8.4-xml 提供 dom/simplexml（composer 解析依赖树时 codeception 等需要，缺了会卡）
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

### 2. 拉代码 + 建库 + 迁移

```bash
git clone <hytp-backend> /var/www/hytp-backend
cd /var/www/hytp-backend && composer install --no-dev --optimize-autoloader
```

建库 + 建业务库账号（项目分 **3 个库**：hytp 主库 / hytp_trade 交易 / hytp_social 社交）：

```bash
sudo mariadb <<'SQL'
CREATE DATABASE IF NOT EXISTS hytp        DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS hytp_trade  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS hytp_social DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'hytp'@'localhost' IDENTIFIED BY '换成强密码';
GRANT ALL PRIVILEGES ON hytp.*        TO 'hytp'@'localhost';
GRANT ALL PRIVILEGES ON hytp_trade.*  TO 'hytp'@'localhost';
GRANT ALL PRIVILEGES ON hytp_social.* TO 'hytp'@'localhost';
FLUSH PRIVILEGES;
SQL
```

先填 `common/config/main-local.php` 的三个 DB 连接（dsn/username/password，见 §4），再迁移：

```bash
php yii migrate --interactive=0     # 主库 hytp
# ★交易/社交库走各自 migrationPath 与 db 连接（对照 console 里的迁移分组，例如）：
# php yii migrate --interactive=0 --migrationPath=@console/migrations/trade  --db=dbTrade
# php yii migrate --interactive=0 --migrationPath=@console/migrations/social --db=dbSocial
# 具体分组名/连接名以 console/config 与 common/config 实际配置为准。
# 迁移后清缓存：rm -rf {api,merchant,admin,console}/runtime/cache/*
```

### 3. 前端构建 + AI 服务依赖

```bash
git clone <hytp-web> /var/www/hytp-web
cd /var/www/hytp-web/merchant && npm ci && npm run build   # 产出 dist/
cd /var/www/hytp-web/admin && npm ci && npm run build
cd /var/www/hytp-web/ai && npm ci --omit=dev               # AI 服务运行依赖
```

> 内存紧时前端可在本地构建后只把 `dist/` 传服务器，省掉服务器上装 devDependencies 的开销。

### 4. 配置文件就位

```bash
cp deploy/nginx/hytp.conf   /etc/nginx/conf.d/hytp.conf         # 改域名、按需开管理端 IP 白名单
cp deploy/php/hytp-fpm.conf /etc/php/8.4/fpm/pool.d/hytp.conf
cp deploy/mysql/hytp.cnf    /etc/mysql/mariadb.conf.d/hytp.cnf
cat deploy/redis/hytp.conf  >> /etc/redis/redis.conf            # 或放 /etc/redis/redis.conf.d/
cp deploy/systemd/hytp-ai.service /etc/systemd/system/
```

### 5. 填生产密钥（都在 gitignore 外，不入库）

- `common/config/main-local.php`：三个库的 DB 连接（dsn 指向 hytp / hytp_trade / hytp_social）+ §2 建的账号密码
- `common/config/params-local.php`：JWT 生产密钥、`ai.sign.secret`、`upload.sts.accessKeyId/Secret`
  ```bash
  php -r "echo bin2hex(random_bytes(32));"   # 生成 JWT 密钥（三端可不同）
  ```
- `common/config/params.php`：`upload.sts.enabled = true` + region/bucket/roleArn（非密钥，可入库）
- `hytp-web/ai/.env`：`DEEPSEEK_API_KEY` + `INTERNAL_SIGN_SECRET`（须与 params-local 的 `ai.sign.secret` 完全一致）

### 6. 权限 + 启动

```bash
chown -R www-data:www-data /var/www/hytp-backend /var/www/hytp-web
chmod 600 /var/www/hytp-backend/common/config/params-local.php \
          /var/www/hytp-backend/common/config/main-local.php \
          /var/www/hytp-web/ai/.env
mkdir -p /var/www/hytp-backend/api/web/uploads && chown www-data:www-data "$_"   # 中转上传兜底目录

systemctl restart php8.4-fpm mariadb redis-server nginx
systemctl daemon-reload && systemctl enable --now hytp-ai
```

### 7. HTTPS（Let's Encrypt）

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d api.example.com -d merchant.example.com -d admin.example.com
```

### 8. 验证

```bash
curl https://api.example.com/site/ping           # {"code":0,...,"db":true}
curl -I https://merchant.example.com/            # 200 + index.html
curl http://127.0.0.1:8790/health                # AI 服务 {"ok":true}
systemctl status hytp-ai php8.4-fpm mariadb      # 均 active (running)
```

Android 客户端把 baseUrl 改成 `https://api.example.com/`。

---

## ⚠️ 上线安全清单（逐条确认，别裸奔）

- [ ] **改管理端默认密码**：现在是 `admin/admin123`，暴露公网 + 默认口令 = 任何人进后台。改强密码，**并给 admin 子域配 IP 白名单**（nginx 里已留注释）。
- [ ] **换 JWT 生产密钥**：`params-local.php` 现在是 `dev_access_...` 开发值，必须换随机长串（三端可不同）。旧 token 会失效，属预期。
- [ ] **密钥文件权限 600**：`params-local.php` / `main-local.php` / `ai/.env`（含 DeepSeek key、OSS AK、JWT、HMAC secret）。
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
