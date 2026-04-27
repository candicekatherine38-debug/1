# 宝塔面板部署说明

以下步骤以站点目录 `/www/wwwroot/admin_secure` 为例。

## 1. 上传代码

在宝塔「文件」里上传整个 `secure_v2` 目录，建议最终路径：

```text
/www/wwwroot/admin_secure
```

不要把旧系统目录直接覆盖。先新建站点、验证无误后再切换域名。

## 2. 创建站点

宝塔面板：

1. 网站 -> 添加站点。
2. 域名填写你的域名。
3. PHP 版本选择 PHP 8.1 或 PHP 8.2。
4. 根目录选择：

```text
/www/wwwroot/admin_secure/public
```

注意：根目录必须是 `public`，不能是 `/www/wwwroot/admin_secure`。

## 3. 创建数据库

宝塔面板：

1. 数据库 -> 添加数据库。
2. 数据库名建议：`admin_system_v2`。
3. 用户名建议：`admin_system_v2`。
4. 记录宝塔生成的数据库密码。
5. 导入：

```text
secure_v2/database/schema.sql
```

如果要迁移旧数据，再审阅并导入：

```text
secure_v2/database/migrate_from_v1.sql
```

## 4. 配置 .env

复制：

```text
secure_v2/.env.bt.example
```

为：

```text
secure_v2/.env
```

修改 `APP_URL`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`。

## 5. 设置伪静态

如果使用 Nginx，在宝塔站点设置 -> 伪静态，粘贴：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~* \.(?:sql|env|ini|log|bak|zip|tar|gz)$ {
    deny all;
}

location ^~ /storage/uploads/ {
    location ~ \.php(?:/|$) {
        deny all;
    }
}
```

如果使用 Apache，`public/.htaccess` 已经提供基础重写规则。

## 6. 防跨站目录限制

宝塔站点设置 -> 网站目录：

建议开启「防跨站攻击」，并确认运行目录为：

```text
/www/wwwroot/admin_secure/public
```

如果宝塔自动生成 `.user.ini`，可以保留。参考模板在：

```text
bt/open_basedir.user.ini
```

## 7. 创建超级管理员

宝塔终端执行：

```bash
cd /www/wwwroot/admin_secure
php scripts/create_admin.php admin "your-strong-password"
```

## 8. 配置计划任务

宝塔面板 -> 计划任务：

- 任务类型：Shell 脚本
- 执行周期：每分钟
- 脚本内容：

```bash
cd /www/wwwroot/admin_secure && php scripts/schedule.php
```

## 9. 上线前检查

按 `ACCEPTANCE_CHECKLIST.md` 和 `SECURITY_CHECKLIST.md` 逐项验收。

特别注意：

- 不要开放 MySQL 公网访问。
- 不要使用旧系统泄露过的数据库密码。
- 不要使用旧系统泄露过的短信 API token。
- 确认 `/upload/image` 和 `/upload/video` 未登录无法访问。
