# 汉韵同袍 后端（hytp-backend）

Yii2 advanced（PHP 8.3 + MySQL 8 + Redis），三入口应用 + 共享 common。

## 目录结构

```
api/        用户端 API（app-api）      命名空间 api\
merchant/   商家端 SaaS API（app-merchant） merchant\
admin/      管理端 API（app-admin）     admin\
common/     三端共享                    common\
  base/         ApiController（统一响应+异常兜底）
  enums/        ErrorCode（全局错误码）
  exceptions/   BizException（业务异常）
  models/ services/ behaviors/ dto/
console/    迁移、定时任务、队列 worker  console\
environments/ dev/prod 环境模板
```

## 环境

| 组件 | 版本 | 位置/说明 |
|------|------|-----------|
| PHP | 8.3.32 NTS | `E:\tool\php`（已入 PATH） |
| Composer | 2.10.2 | `E:\tool\composer`，已配阿里云镜像 |
| MySQL | 8.0.46 | `E:\tool\mysql\mysql-8.0.46-winx64`，库 `hytp` |
| Redis | 3.0.504 | `E:\tool\redis\Redis-x64-3.0.504` |

DB/Redis 连接配置在 `common/config/main-local.php`（**已被 .gitignore 忽略，不入库**）。
账号密码见本机 `F:\office_software\_phptmp\pw.txt`（root 与 hytp 应用账号）。

## 初始化（换机/新克隆后）

```bash
# 1. 安装依赖
composer install
# 2. 生成本地配置（首次）
php init --env=Development --overwrite=All
# 3. 编辑 common/config/main-local.php 填 DB/Redis
# 4. 建表
php yii migrate
```

## 本地启动（开发）

```bash
# 用户端 API
php -S 127.0.0.1:8688 -t api/web
# 商家端
php -S 127.0.0.1:8689 -t merchant/web
# 管理端
php -S 127.0.0.1:8690 -t admin/web
```

健康检查：`GET http://127.0.0.1:8688/site/ping` → `{"code":0,...,"data":{"db":true}}`

## 约定

- 统一响应 `{code,message,data}`，见 `docs/dev/03-后端API规范`。
- 控制器继承 `common\base\ApiController`：`return $data` 自动包 code=0；`throw new BizException($code,$msg)` 自动包错误码。
- 迁移命名 `m<yymmdd_hhmmss>_xxx`，全走 `php yii migrate`，禁止手工改库。
- 数据库设计见 `docs/dev/02-数据库设计`。
