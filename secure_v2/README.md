# Secure V2

这是对原系统的功能兼容重写版本，目标是保留业务能力，同时修复原代码中的越权、串租户缓存、公开上传、弱随机链接码等问题。

## 功能

- 管理员登录、超级管理员、子管理员
- 分组管理
- 手机号池管理、批量导入、启用禁用、最大使用次数
- 链接池管理、批量生成、按手机号生成、导出
- 公共链接访问、分配手机号、拉取验证码
- 使用说明管理、图片/视频上传
- 定时任务：重置手机号使用次数、删除过期手机号

## 部署

1. 创建数据库并导入 `database/schema.sql`。
2. 复制 `.env.example` 为 `.env`，修改数据库账号、站点域名和 Session 名称。
3. Web 服务器根目录指向 `secure_v2/public`。
4. PHP 版本建议 8.1+，需要 PDO MySQL、fileinfo、json 扩展。
5. 定时任务每分钟执行一次：

```bash
php /path/to/secure_v2/scripts/schedule.php
```

## 宝塔面板部署

如果使用宝塔面板，优先看：

```text
bt/BT_DEPLOY.md
```

宝塔站点根目录必须设置为：

```text
/www/wwwroot/admin_secure/public
```

Nginx 伪静态可以直接复制：

```text
bt/nginx-rewrite.conf
```

生产环境配置模板：

```text
.env.bt.example
```

后台所有 `POST` 请求都需要带 CSRF：

```http
X-CSRF-Token: <login-response-csrf-or-/admin/csrf>
```

## 创建管理员

导入 SQL 后运行：

```bash
php scripts/create_admin.php admin "your-strong-password"
```

不要使用旧系统里的管理员密码，并删除旧系统 `.env` 中泄露过的数据库账号。

## 从旧库迁移

先导入 `database/schema.sql`，再审阅并执行 `database/migrate_from_v1.sql`。默认旧库名为 `admin_system`，新库名为 `admin_system_v2`。

迁移后建议立即重置：

- 所有管理员密码
- 所有短信 API token
- 数据库账号密码

## 安全改动

- 所有后台写接口要求登录、CSRF 校验和分组归属校验。
- 超级管理员操作显式检查 `is_super`。
- 上传接口在鉴权之后才可访问。
- 链接码使用 `random_bytes`，不再使用 `str_shuffle`。
- 缓存不再跨管理员共享敏感列表。
- 所有数据库写入通过 PDO 预处理完成。
- 公共链接只操作所属分组内的手机号轮询状态。
