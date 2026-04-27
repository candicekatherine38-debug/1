# Security Checklist

上线前请逐项完成：

- 删除旧系统公网入口，或至少关闭旧系统后台写接口。
- 更换 MySQL 密码，并确认数据库不暴露公网。
- 为本系统创建最小权限数据库账号，只允许访问 `admin_system_v2`。
- 使用 `php scripts/create_admin.php admin <strong-password>` 创建或重置超级管理员。
- 删除 `database/schema.sql` 中的演示账号，或导入后立即重置。
- Web 根目录只指向 `secure_v2/public`。
- 确认 `secure_v2/.env` 不在 Web 可访问目录内。
- 给 `/storage/uploads` 配置禁止执行 PHP。
- 后台启用 HTTPS。
- 检查服务器是否还有旧开发者账号、SSH key、面板账号和计划任务。
- 重置所有短信 API token，不再使用被旧系统保存过的 token。
- 保存旧系统访问日志、数据库日志和服务器登录日志作为取证材料。
