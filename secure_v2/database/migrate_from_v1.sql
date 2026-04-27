-- Run this after importing secure_v2/database/schema.sql.
-- It assumes the old database is `admin_system` and the new database is `admin_system_v2`.
-- Review the SQL before running in production.

INSERT INTO admin_system_v2.admin
  (id, username, password, is_super, parent_id, status, created_at, updated_at)
SELECT
  id, username, password, is_super, parent_id, status,
  COALESCE(create_time, NOW()), COALESCE(update_time, NOW())
FROM admin_system.admin
ON DUPLICATE KEY UPDATE
  username = VALUES(username),
  password = VALUES(password),
  is_super = VALUES(is_super),
  parent_id = VALUES(parent_id),
  status = VALUES(status),
  updated_at = VALUES(updated_at);

INSERT INTO admin_system_v2.groups
  (id, admin_id, name, remark, reset_phone_usage_enabled, reset_phone_usage_time,
   delete_expire_phones_enabled, delete_expire_phones_hours, last_reset_date, created_at, updated_at)
SELECT
  id, admin_id, name, remark,
  COALESCE(reset_phone_usage_enabled, 0),
  COALESCE(reset_phone_usage_time, '00:00'),
  COALESCE(delete_expire_phones_enabled, 0),
  COALESCE(delete_expire_phones_hours, 24),
  last_reset_date,
  COALESCE(create_time, NOW()), COALESCE(update_time, NOW())
FROM admin_system.groups
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  remark = VALUES(remark),
  updated_at = VALUES(updated_at);

INSERT INTO admin_system_v2.phone_pool
  (id, group_id, admin_id, phone, api_url, max_uses, used_count, use_rand, status, disable_time, created_at, updated_at)
SELECT
  id, group_id, admin_id, phone, api_url,
  COALESCE(max_uses, 1), COALESCE(used_count, 0), COALESCE(use_rand, 0),
  COALESCE(status, 1), disable_time, COALESCE(create_time, NOW()), COALESCE(update_time, NOW())
FROM admin_system.phone_pool
ON DUPLICATE KEY UPDATE
  api_url = VALUES(api_url),
  max_uses = VALUES(max_uses),
  used_count = VALUES(used_count),
  status = VALUES(status),
  updated_at = VALUES(updated_at);

INSERT INTO admin_system_v2.link_pool
  (id, group_id, phone_id, link_code, expire_minutes, first_access_time, verify_code,
   interface_type, access_count, status, created_at, updated_at)
SELECT
  id, group_id, NULLIF(phone_id, 0), link_code, expire_minutes, first_access_time,
  NULLIF(verify_code, ''), COALESCE(interface_type, 'A'), COALESCE(access_count, 0),
  COALESCE(status, 1), COALESCE(create_time, NOW()), COALESCE(update_time, NOW())
FROM admin_system.link_pool
ON DUPLICATE KEY UPDATE
  phone_id = VALUES(phone_id),
  verify_code = VALUES(verify_code),
  status = VALUES(status),
  updated_at = VALUES(updated_at);

INSERT INTO admin_system_v2.instructions
  (id, group_id, content, media_type, media_url, created_at, updated_at)
SELECT
  id, group_id, content, media_type, media_url,
  COALESCE(create_time, NOW()), COALESCE(update_time, NOW())
FROM admin_system.instructions
ON DUPLICATE KEY UPDATE
  content = VALUES(content),
  media_type = VALUES(media_type),
  media_url = VALUES(media_url),
  updated_at = VALUES(updated_at);

INSERT INTO admin_system_v2.phone_verification_log
  (id, phone_id, code, created_at)
SELECT id, phone_id, code, created_at
FROM admin_system.phone_verification_log
ON DUPLICATE KEY UPDATE
  code = VALUES(code),
  created_at = VALUES(created_at);
