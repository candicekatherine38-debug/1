<?php
declare(strict_types=1);

namespace SecureV2\Controllers;

use SecureV2\Audit;
use SecureV2\Auth;
use SecureV2\Config;
use SecureV2\Db;
use SecureV2\Guard;
use SecureV2\PhoneApi;
use SecureV2\Request;
use SecureV2\Response;
use SecureV2\Util;

abstract class Controller
{
    protected static function view(string $name): void
    {
        $file = dirname(__DIR__) . '/views/' . $name . '.html';
        if (!is_file($file)) {
            Response::html('View not found', 500);
            return;
        }
        Response::html((string)file_get_contents($file));
    }

    protected static function admin(): array
    {
        return Auth::requireLogin();
    }

    protected static function ints(array $values): array
    {
        return array_values(array_filter(array_map('intval', $values), fn (int $id) => $id > 0));
    }

    protected static function page(Request $r): array
    {
        $page = max(1, (int)$r->input('page', 1));
        $size = min(1000, max(1, (int)$r->input('pageSize', 20)));
        return [$page, $size, ($page - 1) * $size];
    }

    protected static function validInterface(string $type): string
    {
        return in_array($type, ['A', 'B', 'C'], true) ? $type : 'A';
    }

    protected static function expireMinutes(mixed $value): int
    {
        $minutes = (int)$value;
        if ($minutes < 5 || $minutes > 1440) {
            Response::json(['code' => 0, 'msg' => 'expire_minutes must be between 5 and 1440']);
            exit;
        }
        return $minutes;
    }

    protected static function idListSql(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}

final class AuthController extends Controller
{
    public static function loginPage(Request $r): void
    {
        self::view('login');
    }

    public static function login(Request $r): void
    {
        $username = trim((string)$r->input('username', ''));
        $password = (string)$r->input('password', '');
        $admin = Db::one('SELECT * FROM admin WHERE username = ?', [$username]);
        if (!$admin || !password_verify($password, $admin['password'])) {
            Audit::log('login_failed', ['username' => $username]);
            Response::json(['code' => 0, 'msg' => 'invalid username or password']);
            return;
        }
        if ((int)$admin['status'] !== 1) {
            Response::json(['code' => 0, 'msg' => 'account disabled']);
            return;
        }
        Auth::login($admin);
        Db::exec('UPDATE admin SET last_login_at = ? WHERE id = ?', [Util::now(), $admin['id']]);
        Audit::log('login_success');
        Response::json(['code' => 1, 'msg' => 'login success', 'csrf' => $_SESSION['csrf'], 'redirect_url' => '/group']);
    }

    public static function logout(Request $r): void
    {
        Audit::log('logout');
        Auth::logout();
        Response::redirect('/admin/login');
    }

    public static function csrf(Request $r): void
    {
        self::admin();
        Response::json(['code' => 1, 'csrf' => $_SESSION['csrf']]);
    }

    public static function me(Request $r): void
    {
        $admin = self::admin();
        Response::json([
            'code' => 1,
            'data' => [
                'id' => (int)$admin['id'],
                'username' => $admin['username'],
                'is_super' => (int)$admin['is_super'] === 1,
                'switched_by_super' => !empty($_SESSION['switched_by_super']),
                'original_username' => $_SESSION['original_username'] ?? null,
            ],
            'csrf' => $_SESSION['csrf'],
        ]);
    }

    public static function adminPage(Request $r): void
    {
        self::admin();
        self::view('admin');
    }

    public static function changePasswordPage(Request $r): void
    {
        self::admin();
        self::view('change_password');
    }
}

final class AdminController extends Controller
{
    public static function list(Request $r): void
    {
        $admin = Auth::requireSuper();
        $rows = Db::all(
            'SELECT id, username, parent_id, status, is_super, created_at FROM admin WHERE is_super = 0 AND parent_id = ? ORDER BY id DESC',
            [$admin['id']]
        );
        Response::json(['code' => 1, 'data' => $rows]);
    }

    public static function create(Request $r): void
    {
        $admin = Auth::requireSuper();
        $username = trim((string)$r->input('username', ''));
        $password = (string)$r->input('password', '');
        if ($username === '' || strlen($password) < 8) {
            Response::json(['code' => 0, 'msg' => 'username required and password must be at least 8 chars']);
            return;
        }
        Db::insert(
            'INSERT INTO admin (username, password, is_super, parent_id, status) VALUES (?, ?, 0, ?, 1)',
            [$username, password_hash($password, PASSWORD_DEFAULT), $admin['id']]
        );
        Audit::log('admin_create', ['username' => $username]);
        Response::json(['code' => 1, 'msg' => 'created']);
    }

    public static function delete(Request $r): void
    {
        $admin = Auth::requireSuper();
        $id = (int)$r->input('id', 0);
        if ($id <= 0 || $id === (int)$admin['id']) {
            Response::json(['code' => 0, 'msg' => 'invalid admin id']);
            return;
        }
        $affected = Db::exec('DELETE FROM admin WHERE id = ? AND parent_id = ? AND is_super = 0', [$id, $admin['id']]);
        Audit::log('admin_delete', ['id' => $id, 'affected' => $affected]);
        Response::json(['code' => $affected ? 1 : 0, 'msg' => $affected ? 'deleted' : 'not found or permission denied']);
    }

    public static function updateStatus(Request $r): void
    {
        $admin = Auth::requireSuper();
        $id = (int)$r->input('id', 0);
        $status = (int)$r->input('status', 0) === 1 ? 1 : 0;
        $affected = Db::exec('UPDATE admin SET status = ? WHERE id = ? AND parent_id = ? AND is_super = 0', [$status, $id, $admin['id']]);
        Audit::log('admin_status', ['id' => $id, 'status' => $status]);
        Response::json(['code' => $affected ? 1 : 0, 'msg' => $affected ? 'updated' : 'not found or permission denied']);
    }

    public static function changeOwnPassword(Request $r): void
    {
        $admin = self::admin();
        $old = (string)$r->input('old_password', '');
        $new = (string)$r->input('new_password', '');
        $confirm = (string)$r->input('confirm_password', '');
        $row = Db::one('SELECT password FROM admin WHERE id = ?', [$admin['id']]);
        if (!$row || !password_verify($old, $row['password']) || $new !== $confirm || strlen($new) < 8) {
            Response::json(['code' => 0, 'msg' => 'password check failed']);
            return;
        }
        Db::exec('UPDATE admin SET password = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        Audit::log('admin_change_own_password');
        Auth::logout();
        Response::json(['code' => 1, 'msg' => 'password changed, please login again']);
    }

    public static function changeAdminPassword(Request $r): void
    {
        $admin = Auth::requireSuper();
        $id = (int)$r->input('admin_id', 0);
        $new = (string)$r->input('new_password', '');
        $confirm = (string)$r->input('confirm_password', '');
        if ($new !== $confirm || strlen($new) < 8) {
            Response::json(['code' => 0, 'msg' => 'password must match and be at least 8 chars']);
            return;
        }
        $affected = Db::exec('UPDATE admin SET password = ? WHERE id = ? AND parent_id = ? AND is_super = 0', [password_hash($new, PASSWORD_DEFAULT), $id, $admin['id']]);
        Audit::log('admin_change_password', ['id' => $id]);
        Response::json(['code' => $affected ? 1 : 0, 'msg' => $affected ? 'updated' : 'not found or permission denied']);
    }

    public static function switchToAdmin(Request $r): void
    {
        $admin = Auth::requireSuper();
        $targetId = (int)$r->input('admin_id', 0);
        $target = Db::one('SELECT id, username FROM admin WHERE id = ? AND parent_id = ? AND is_super = 0 AND status = 1', [$targetId, $admin['id']]);
        if (!$target) {
            Response::json(['code' => 0, 'msg' => 'target admin not found']);
            return;
        }
        $_SESSION['original_admin_id'] = (int)$admin['id'];
        $_SESSION['original_username'] = $admin['username'];
        $_SESSION['original_is_super'] = (int)$admin['is_super'];
        $_SESSION['admin_id'] = (int)$target['id'];
        $_SESSION['username'] = $target['username'];
        $_SESSION['is_super'] = 0;
        $_SESSION['switched_by_super'] = true;
        Audit::log('admin_switch_to', ['target_id' => $targetId]);
        Response::json(['code' => 1, 'msg' => 'switched']);
    }

    public static function returnToSuper(Request $r): void
    {
        if (empty($_SESSION['switched_by_super']) || empty($_SESSION['original_admin_id'])) {
            Response::json(['code' => 0, 'msg' => 'not in switched session']);
            return;
        }
        $_SESSION['admin_id'] = (int)$_SESSION['original_admin_id'];
        $_SESSION['username'] = $_SESSION['original_username'];
        $_SESSION['is_super'] = (int)$_SESSION['original_is_super'];
        unset($_SESSION['original_admin_id'], $_SESSION['original_username'], $_SESSION['original_is_super'], $_SESSION['switched_by_super']);
        Audit::log('admin_return_to_super');
        Response::json(['code' => 1, 'msg' => 'returned']);
    }
}

final class GroupController extends Controller
{
    public static function index(Request $r): void
    {
        self::admin();
        self::view('admin');
    }

    public static function list(Request $r): void
    {
        $admin = self::admin();
        $rows = Db::all('SELECT * FROM groups WHERE admin_id = ? ORDER BY id DESC', [$admin['id']]);
        Response::json(['code' => 1, 'data' => $rows, 'is_super' => (int)$admin['is_super'] === 1]);
    }

    public static function create(Request $r): void
    {
        $admin = self::admin();
        $name = trim((string)$r->input('name', ''));
        if ($name === '') {
            Response::json(['code' => 0, 'msg' => 'group name required']);
            return;
        }
        $id = Db::insert('INSERT INTO groups (admin_id, name, remark) VALUES (?, ?, ?)', [$admin['id'], $name, (string)$r->input('remark', '')]);
        Audit::log('group_create', ['id' => $id]);
        Response::json(['code' => 1, 'msg' => 'created', 'id' => $id]);
    }

    public static function update(Request $r): void
    {
        $admin = self::admin();
        $id = (int)$r->input('id', 0);
        Guard::ownedGroup($id, (int)$admin['id']);
        Db::exec('UPDATE groups SET name = ?, remark = ? WHERE id = ? AND admin_id = ?', [
            trim((string)$r->input('name', '')),
            (string)$r->input('remark', ''),
            $id,
            $admin['id'],
        ]);
        Audit::log('group_update', ['id' => $id]);
        Response::json(['code' => 1, 'msg' => 'updated']);
    }

    public static function delete(Request $r, int $id): void
    {
        $admin = self::admin();
        Guard::ownedGroup($id, (int)$admin['id']);
        Db::exec('DELETE FROM groups WHERE id = ? AND admin_id = ?', [$id, $admin['id']]);
        Audit::log('group_delete', ['id' => $id]);
        Response::json(['code' => 1, 'msg' => 'deleted']);
    }

    public static function addPhone(Request $r): void
    {
        $admin = self::admin();
        $groupId = (int)$r->input('group_id', 0);
        Guard::ownedGroup($groupId, (int)$admin['id']);
        $phone = trim((string)$r->input('phone', ''));
        $apiUrl = trim((string)$r->input('api_url', ''));
        $maxUses = max(1, (int)$r->input('max_uses', 1));
        if ($phone === '' || !filter_var($apiUrl, FILTER_VALIDATE_URL)) {
            Response::json(['code' => 0, 'msg' => 'valid phone and api_url required']);
            return;
        }
        Db::insert('INSERT INTO phone_pool (group_id, admin_id, phone, api_url, max_uses, status) VALUES (?, ?, ?, ?, ?, 1)', [$groupId, $admin['id'], $phone, $apiUrl, $maxUses]);
        Audit::log('phone_add', ['group_id' => $groupId]);
        Response::json(['code' => 1, 'msg' => 'added']);
    }

    public static function batchAddPhone(Request $r): void
    {
        $admin = self::admin();
        $groupId = (int)$r->input('group_id', 0);
        Guard::ownedGroup($groupId, (int)$admin['id']);
        $maxUses = max(1, (int)$r->input('max_uses', 1));
        $lines = preg_split('/\r\n|\r|\n/', trim((string)$r->input('phones', ''))) ?: [];
        $count = 0;
        Db::tx(function () use ($lines, $groupId, $admin, $maxUses, &$count) {
            foreach ($lines as $line) {
                $parts = array_map('trim', explode('----', $line, 2));
                if (count($parts) !== 2 || $parts[0] === '' || !filter_var($parts[1], FILTER_VALIDATE_URL)) {
                    continue;
                }
                Db::exec('INSERT INTO phone_pool (group_id, admin_id, phone, api_url, max_uses, status) VALUES (?, ?, ?, ?, ?, 1)', [$groupId, $admin['id'], $parts[0], $parts[1], $maxUses]);
                $count++;
            }
        });
        Audit::log('phone_batch_add', ['group_id' => $groupId, 'count' => $count]);
        Response::json(['code' => 1, 'msg' => 'batch added', 'count' => $count]);
    }

    public static function phones(Request $r): void
    {
        $admin = self::admin();
        [$page, $size, $offset] = self::page($r);
        $groupId = $r->input('id', 0);
        $keyword = trim((string)$r->input('keyword', ''));
        $params = [$admin['id']];
        $where = 'admin_id = ?';
        if ($groupId !== 'all' && (int)$groupId > 0) {
            Guard::ownedGroup((int)$groupId, (int)$admin['id']);
            $where .= ' AND group_id = ?';
            $params[] = (int)$groupId;
        }
        if ($keyword !== '') {
            $where .= ' AND phone LIKE ?';
            $params[] = '%' . $keyword . '%';
        }
        $rows = Db::all("SELECT id, group_id, phone, api_url, max_uses, used_count, status, disable_time, created_at FROM phone_pool WHERE {$where} ORDER BY id DESC LIMIT {$size} OFFSET {$offset}", $params);
        Response::json(['code' => 1, 'data' => ['data' => $rows, 'current_page' => $page, 'per_page' => $size]]);
    }

    public static function deletePhone(Request $r): void
    {
        $admin = self::admin();
        $id = (int)$r->input('id', 0);
        $affected = Db::exec('DELETE FROM phone_pool WHERE id = ? AND admin_id = ?', [$id, $admin['id']]);
        Audit::log('phone_delete', ['id' => $id, 'affected' => $affected]);
        Response::json(['code' => $affected ? 1 : 0, 'msg' => $affected ? 'deleted' : 'not found']);
    }

    public static function batchDeletePhone(Request $r): void
    {
        self::batchPhoneUpdate($r, 'DELETE FROM phone_pool WHERE admin_id = ? AND id IN (%s)', [], 'deleted');
    }

    public static function batchSetMaxUses(Request $r): void
    {
        $maxUses = max(1, (int)$r->input('max_uses', 1));
        self::batchPhoneUpdate($r, 'UPDATE phone_pool SET max_uses = ? WHERE admin_id = ? AND id IN (%s)', [$maxUses], 'updated');
    }

    public static function togglePhoneStatus(Request $r, int $id): void
    {
        $admin = self::admin();
        $phone = Db::one('SELECT id, status FROM phone_pool WHERE id = ? AND admin_id = ?', [$id, $admin['id']]);
        if (!$phone) {
            Response::json(['code' => 0, 'msg' => 'not found']);
            return;
        }
        $status = (int)$phone['status'] === 1 ? 0 : 1;
        Db::exec('UPDATE phone_pool SET status = ?, disable_time = ? WHERE id = ? AND admin_id = ?', [$status, $status ? null : Util::now(), $id, $admin['id']]);
        Audit::log('phone_toggle_status', ['id' => $id, 'status' => $status]);
        Response::json(['code' => 1, 'msg' => 'updated']);
    }

    public static function batchTogglePhoneStatus(Request $r): void
    {
        $admin = self::admin();
        $ids = self::ints($r->arrayInput('ids'));
        if (!$ids) {
            Response::json(['code' => 0, 'msg' => 'ids required']);
            return;
        }
        $status = (int)$r->input('status', 0) === 1 ? 1 : 0;
        $params = array_merge([$status, $status ? null : Util::now(), $admin['id']], $ids);
        $affected = Db::exec('UPDATE phone_pool SET status = ?, disable_time = ? WHERE admin_id = ? AND id IN (' . self::idListSql($ids) . ')', $params);
        Audit::log('phone_batch_toggle', ['count' => $affected]);
        Response::json(['code' => 1, 'msg' => 'updated', 'affected' => $affected]);
    }

    public static function resetPhoneUsage(Request $r, int $id): void
    {
        $admin = self::admin();
        $affected = Db::exec('UPDATE phone_pool SET used_count = 0 WHERE id = ? AND admin_id = ?', [$id, $admin['id']]);
        Response::json(['code' => $affected ? 1 : 0, 'msg' => $affected ? 'reset' : 'not found']);
    }

    public static function batchResetPhoneUsage(Request $r): void
    {
        self::batchPhoneUpdate($r, 'UPDATE phone_pool SET used_count = 0 WHERE admin_id = ? AND id IN (%s)', [], 'reset');
    }

    private static function batchPhoneUpdate(Request $r, string $sql, array $prefixParams, string $msg): void
    {
        $admin = self::admin();
        $ids = self::ints($r->arrayInput('ids'));
        if (!$ids) {
            Response::json(['code' => 0, 'msg' => 'ids required']);
            return;
        }
        $params = array_merge($prefixParams, [$admin['id']], $ids);
        $affected = Db::exec(sprintf($sql, self::idListSql($ids)), $params);
        Audit::log('phone_batch_' . $msg, ['affected' => $affected]);
        Response::json(['code' => 1, 'msg' => $msg, 'affected' => $affected]);
    }

    public static function generateLinks(Request $r): void
    {
        $admin = self::admin();
        $groupId = (int)$r->input('group_id', 0);
        Guard::ownedGroup($groupId, (int)$admin['id']);
        $count = min(1000, max(1, (int)$r->input('count', 1)));
        $expire = self::expireMinutes($r->input('expire_minutes', 15));
        $type = self::validInterface((string)$r->input('interface_type', 'A'));
        $codes = self::createLinks($groupId, array_fill(0, $count, null), $expire, $type);
        Audit::log('links_generate', ['group_id' => $groupId, 'count' => count($codes)]);
        Response::json(['code' => 1, 'msg' => 'generated', 'count' => count($codes), 'data' => $codes]);
    }

    public static function generateLinksByPhones(Request $r): void
    {
        $admin = self::admin();
        $groupId = (int)$r->input('group_id', 0);
        Guard::ownedGroup($groupId, (int)$admin['id']);
        $ids = self::ints($r->arrayInput('phone_ids'));
        if (!$ids) {
            Response::json(['code' => 0, 'msg' => 'phone_ids required']);
            return;
        }
        $countPerPhone = min(1000, max(1, (int)$r->input('count_per_phone', 1)));
        $expire = self::expireMinutes($r->input('expire_minutes', 15));
        $type = self::validInterface((string)$r->input('interface_type', 'A'));
        $phones = Db::all('SELECT id FROM phone_pool WHERE admin_id = ? AND group_id = ? AND status = 1 AND id IN (' . self::idListSql($ids) . ')', array_merge([$admin['id'], $groupId], $ids));
        $phoneIds = [];
        foreach ($phones as $phone) {
            for ($i = 0; $i < $countPerPhone; $i++) {
                $phoneIds[] = (int)$phone['id'];
            }
        }
        $codes = self::createLinks($groupId, $phoneIds, $expire, $type);
        $content = implode("\n", array_map(fn ($code) => Util::baseUrl() . '/link/' . $code, $codes)) . "\n";
        if ((int)$r->input('export_txt', 0) === 1) {
            Response::text($content, 'new_links_' . date('YmdHis') . '.txt');
            return;
        }
        Audit::log('links_generate_by_phone', ['group_id' => $groupId, 'count' => count($codes)]);
        Response::json(['code' => 1, 'msg' => 'generated', 'count' => count($codes), 'data' => $codes]);
    }

    private static function createLinks(int $groupId, array $phoneIds, int $expire, string $type): array
    {
        $codes = [];
        foreach ($phoneIds as $phoneId) {
            do {
                $code = Util::code(9);
            } while (Db::one('SELECT id FROM link_pool WHERE link_code = ?', [$code]));
            Db::exec('INSERT INTO link_pool (group_id, phone_id, link_code, expire_minutes, interface_type, status) VALUES (?, ?, ?, ?, ?, 1)', [$groupId, $phoneId, $code, $expire, $type]);
            $codes[] = $code;
        }
        return $codes;
    }

    public static function links(Request $r): void
    {
        $admin = self::admin();
        [$page, $size, $offset] = self::page($r);
        $groupId = (int)$r->input('id', 0);
        Guard::ownedGroup($groupId, (int)$admin['id']);
        $searchPhone = trim((string)$r->input('searchPhone', ''));
        $where = 'l.group_id = ?';
        $params = [$groupId];
        if ($searchPhone !== '') {
            $where .= ' AND (p.phone LIKE ? OR l.phone_id IS NULL)';
            $params[] = '%' . $searchPhone . '%';
        }
        $rows = Db::all(
            "SELECT l.*, p.phone FROM link_pool l LEFT JOIN phone_pool p ON p.id = l.phone_id WHERE {$where} ORDER BY l.id DESC LIMIT {$size} OFFSET {$offset}",
            $params
        );
        Response::json(['code' => 1, 'data' => ['data' => $rows, 'current_page' => $page, 'per_page' => $size]]);
    }

    public static function exportLinks(Request $r): void
    {
        self::exportLinkSet($r, false);
    }

    public static function exportValidLinks(Request $r): void
    {
        self::exportLinkSet($r, true);
    }

    private static function exportLinkSet(Request $r, bool $onlyValid): void
    {
        $admin = self::admin();
        $groupId = (int)$r->input($onlyValid ? 'id' : 'id', 0);
        Guard::ownedGroup($groupId, (int)$admin['id']);
        $where = 'group_id = ?';
        if ($onlyValid) {
            $where .= ' AND status = 1 AND first_access_time IS NULL';
        }
        $rows = Db::all("SELECT link_code FROM link_pool WHERE {$where} ORDER BY id ASC", [$groupId]);
        $content = implode("\n", array_map(fn ($row) => Util::baseUrl() . '/link/' . $row['link_code'], $rows)) . "\n";
        Audit::log($onlyValid ? 'links_export_valid' : 'links_export', ['group_id' => $groupId, 'count' => count($rows)]);
        Response::text($content, ($onlyValid ? 'valid_links_' : 'links_') . date('YmdHis') . '.txt');
    }

    public static function exportPhones(Request $r): void
    {
        $admin = self::admin();
        $groupId = (int)$r->input('group_id', 0);
        Guard::ownedGroup($groupId, (int)$admin['id']);
        $rows = Db::all('SELECT phone, api_url FROM phone_pool WHERE admin_id = ? AND group_id = ? ORDER BY id ASC', [$admin['id'], $groupId]);
        $content = implode("\n", array_map(fn ($row) => $row['phone'] . '----' . $row['api_url'], $rows)) . "\n";
        Audit::log('phones_export', ['group_id' => $groupId, 'count' => count($rows)]);
        Response::text($content, 'phones_' . date('YmdHis') . '.txt');
    }

    public static function deleteLink(Request $r): void
    {
        self::deleteLinksByIds($r, [(int)$r->input('id', 0)]);
    }

    public static function batchDeleteLink(Request $r): void
    {
        self::deleteLinksByIds($r, self::ints($r->arrayInput('ids')));
    }

    private static function deleteLinksByIds(Request $r, array $ids): void
    {
        $admin = self::admin();
        if (!$ids) {
            Response::json(['code' => 0, 'msg' => 'ids required']);
            return;
        }
        $sql = 'DELETE l FROM link_pool l JOIN groups g ON g.id = l.group_id WHERE g.admin_id = ? AND l.id IN (' . self::idListSql($ids) . ')';
        $affected = Db::exec($sql, array_merge([$admin['id']], $ids));
        Audit::log('links_delete', ['affected' => $affected]);
        Response::json(['code' => 1, 'msg' => 'deleted', 'affected' => $affected]);
    }

    public static function deleteLinkByCode(Request $r): void
    {
        $admin = self::admin();
        $code = trim((string)$r->input('code', ''));
        $affected = Db::exec('DELETE l FROM link_pool l JOIN groups g ON g.id = l.group_id WHERE g.admin_id = ? AND l.link_code = ?', [$admin['id'], $code]);
        Audit::log('link_delete_by_code', ['code' => $code, 'affected' => $affected]);
        Response::json(['code' => $affected ? 1 : 0, 'msg' => $affected ? 'deleted' : 'not found']);
    }

    public static function batchResetLinks(Request $r): void
    {
        $admin = self::admin();
        $ids = self::ints($r->arrayInput('ids'));
        if (!$ids) {
            Response::json(['code' => 0, 'msg' => 'ids required']);
            return;
        }
        $links = Db::all('SELECT l.* FROM link_pool l JOIN groups g ON g.id = l.group_id WHERE g.admin_id = ? AND l.id IN (' . self::idListSql($ids) . ')', array_merge([$admin['id']], $ids));
        $newCodes = [];
        Db::tx(function () use ($ids, $links, &$newCodes) {
            Db::exec('DELETE FROM link_pool WHERE id IN (' . self::idListSql($ids) . ')', $ids);
            foreach ($links as $link) {
                $newCodes = array_merge($newCodes, self::createLinks((int)$link['group_id'], [$link['phone_id'] ?: null], (int)$link['expire_minutes'], $link['interface_type']));
            }
        });
        if ((int)$r->input('export_txt', 0) === 1) {
            Response::text(implode("\n", array_map(fn ($code) => Util::baseUrl() . '/link/' . $code, $newCodes)) . "\n", 'reset_links_' . date('YmdHis') . '.txt');
            return;
        }
        Response::json(['code' => 1, 'msg' => 'reset', 'data' => $newCodes]);
    }

    public static function recycleLinks(Request $r): void
    {
        $codes = array_values(array_filter(array_map('strval', $r->arrayInput('link_codes'))));
        $admin = self::admin();
        if (!$codes) {
            Response::json(['code' => 0, 'msg' => 'link_codes required']);
            return;
        }
        $links = Db::all('SELECT l.* FROM link_pool l JOIN groups g ON g.id = l.group_id WHERE g.admin_id = ? AND l.link_code IN (' . self::idListSql($codes) . ')', array_merge([$admin['id']], $codes));
        $ids = array_column($links, 'id');
        $newCodes = [];
        Db::tx(function () use ($links, $ids, &$newCodes, $r) {
            if ($ids) {
                Db::exec('DELETE FROM link_pool WHERE id IN (' . self::idListSql($ids) . ')', $ids);
            }
            foreach ($links as $link) {
                $newCodes = array_merge($newCodes, self::createLinks((int)$link['group_id'], [$link['phone_id'] ?: null], self::expireMinutes($r->input('expire_minutes', $link['expire_minutes'])), $link['interface_type']));
            }
        });
        Response::json(['code' => 1, 'msg' => 'recycled', 'data' => ['new_links' => array_map(fn ($code) => Util::baseUrl() . '/link/' . $code, $newCodes)]]);
    }

    public static function batchDisablePhonesByLinks(Request $r): void
    {
        $admin = self::admin();
        $codes = array_values(array_filter(array_map('strval', $r->arrayInput('link_codes'))));
        if (!$codes) {
            Response::json(['code' => 0, 'msg' => 'link_codes required']);
            return;
        }
        $phones = Db::all('SELECT DISTINCT p.id FROM link_pool l JOIN groups g ON g.id = l.group_id JOIN phone_pool p ON p.id = l.phone_id WHERE g.admin_id = ? AND l.link_code IN (' . self::idListSql($codes) . ')', array_merge([$admin['id']], $codes));
        $ids = array_column($phones, 'id');
        $affected = $ids ? Db::exec('UPDATE phone_pool SET status = 0, disable_time = ? WHERE admin_id = ? AND id IN (' . self::idListSql($ids) . ')', array_merge([Util::now(), $admin['id']], $ids)) : 0;
        Response::json(['code' => 1, 'msg' => 'disabled', 'affected' => $affected]);
    }

    public static function updateInstructions(Request $r): void
    {
        $admin = self::admin();
        $groupId = (int)$r->input('group_id', 0);
        Guard::ownedGroup($groupId, (int)$admin['id']);
        Db::exec(
            'INSERT INTO instructions (group_id, content, media_type, media_url) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE content = VALUES(content), media_type = VALUES(media_type), media_url = VALUES(media_url)',
            [$groupId, (string)$r->input('content', ''), $r->input('media_type'), $r->input('media_url')]
        );
        Audit::log('instructions_update', ['group_id' => $groupId]);
        Response::json(['code' => 1, 'msg' => 'updated']);
    }

    public static function getInstructions(Request $r): void
    {
        $admin = self::admin();
        $groupId = (int)$r->input('id', 0);
        Guard::ownedGroup($groupId, (int)$admin['id']);
        $row = Db::one('SELECT * FROM instructions WHERE group_id = ?', [$groupId]) ?? [];
        Response::json(['code' => 1, 'data' => $row]);
    }

    public static function updateScheduleSettings(Request $r): void
    {
        $admin = self::admin();
        $groupId = (int)$r->input('group_id', 0);
        Guard::ownedGroup($groupId, (int)$admin['id']);
        Db::exec(
            'UPDATE groups SET reset_phone_usage_enabled = ?, reset_phone_usage_time = ?, delete_expire_phones_enabled = ?, delete_expire_phones_hours = ? WHERE id = ? AND admin_id = ?',
            [
                (int)$r->input('reset_phone_usage_enabled', 0) === 1 ? 1 : 0,
                substr((string)$r->input('reset_phone_usage_time', '00:00'), 0, 5),
                (int)$r->input('delete_expire_phones_enabled', 0) === 1 ? 1 : 0,
                max(1, (int)$r->input('delete_expire_phones_hours', 24)),
                $groupId,
                $admin['id'],
            ]
        );
        Response::json(['code' => 1, 'msg' => 'updated']);
    }

    public static function getScheduleSettings(Request $r, int $id): void
    {
        $admin = self::admin();
        $row = Guard::ownedGroup($id, (int)$admin['id']);
        Response::json(['code' => 1, 'data' => [
            'reset_phone_usage_enabled' => $row['reset_phone_usage_enabled'],
            'reset_phone_usage_time' => $row['reset_phone_usage_time'],
            'delete_expire_phones_enabled' => $row['delete_expire_phones_enabled'],
            'delete_expire_phones_hours' => $row['delete_expire_phones_hours'],
        ]]);
    }

    public static function manualResetPhoneUsage(Request $r): void
    {
        $admin = self::admin();
        $groupId = (int)$r->input('group_id', 0);
        Guard::ownedGroup($groupId, (int)$admin['id']);
        $affected = Db::exec('UPDATE phone_pool SET used_count = 0 WHERE admin_id = ? AND group_id = ?', [$admin['id'], $groupId]);
        Response::json(['code' => 1, 'msg' => 'reset', 'affected' => $affected]);
    }

    public static function manualDeleteExpirePhones(Request $r): void
    {
        $admin = self::admin();
        $groupId = (int)$r->input('group_id', 0);
        $group = Guard::ownedGroup($groupId, (int)$admin['id']);
        $hours = max(1, (int)$group['delete_expire_phones_hours']);
        $affected = Db::exec('DELETE FROM phone_pool WHERE admin_id = ? AND group_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)', [$admin['id'], $groupId, $hours]);
        Response::json(['code' => 1, 'msg' => 'deleted', 'affected' => $affected]);
    }

    public static function runSchedule(): array
    {
        $groups = Db::all('SELECT * FROM groups WHERE reset_phone_usage_enabled = 1 OR delete_expire_phones_enabled = 1');
        $result = ['reset' => 0, 'deleted' => 0];
        foreach ($groups as $group) {
            if ((int)$group['reset_phone_usage_enabled'] === 1 && $group['reset_phone_usage_time'] === date('H:i') && $group['last_reset_date'] !== date('Y-m-d')) {
                $result['reset'] += Db::exec('UPDATE phone_pool SET used_count = 0 WHERE group_id = ?', [$group['id']]);
                Db::exec('UPDATE groups SET last_reset_date = ? WHERE id = ?', [date('Y-m-d'), $group['id']]);
            }
            if ((int)$group['delete_expire_phones_enabled'] === 1) {
                $hours = max(1, (int)$group['delete_expire_phones_hours']);
                $result['deleted'] += Db::exec('DELETE FROM phone_pool WHERE group_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)', [$group['id'], $hours]);
            }
        }
        return $result;
    }
}

final class LinkController extends Controller
{
    public static function access(Request $r, string $code): void
    {
        $link = self::findLink($code);
        if (!$link) {
            Response::html('Link not found', 404);
            return;
        }
        if (!self::isValid($link)) {
            Response::html(self::renderLink($link, $link['verify_code'] ? 'Link used' : 'Link expired'));
            return;
        }
        if (!$link['first_access_time']) {
            Db::exec('UPDATE link_pool SET first_access_time = ? WHERE id = ?', [Util::now(), $link['id']]);
            $link['first_access_time'] = Util::now();
        }
        if (!$link['phone_id']) {
            $phone = self::assignPhone($link);
            if (!$phone) {
                Response::html('No available phone');
                return;
            }
            $link = self::findLink($code);
        }
        Response::html(self::renderLink($link, ''));
    }

    public static function getCode(Request $r, string $code): void
    {
        $link = self::findLink($code);
        if (!$link || !self::isValid($link)) {
            Response::json(['code' => 0, 'msg' => 'link invalid']);
            return;
        }
        if (!$link['phone_id']) {
            $phone = self::assignPhone($link);
            if (!$phone) {
                Response::json(['code' => 0, 'msg' => 'no available phone']);
                return;
            }
            $link = self::findLink($code);
        }
        $phone = Db::one('SELECT * FROM phone_pool WHERE id = ?', [$link['phone_id']]);
        $verification = PhoneApi::fetchCode($phone, strtotime($link['first_access_time'] ?? Util::now()));
        if (!$verification) {
            Response::json(['code' => 0, 'msg' => 'code not ready']);
            return;
        }
        Db::tx(function () use ($link, $phone, $verification) {
            Db::exec('UPDATE link_pool SET access_count = access_count + 1, verify_code = ?, status = 0 WHERE id = ?', [$verification, $link['id']]);
            Db::exec('UPDATE phone_pool SET used_count = used_count + 1 WHERE id = ?', [$phone['id']]);
        });
        Response::json(['code' => 1, 'data' => $verification]);
    }

    public static function checkCodeStatus(Request $r, string $code): void
    {
        $link = self::findLink($code);
        if (!$link) {
            Response::json(['code' => 0, 'msg' => 'link not found', 'has_code' => false]);
            return;
        }
        if (!empty($link['verify_code'])) {
            Response::json(['code' => 1, 'has_code' => true, 'verify_code' => $link['verify_code']]);
            return;
        }
        if (!self::isValid($link)) {
            Response::json(['code' => 0, 'msg' => 'link invalid', 'has_code' => false]);
            return;
        }
        if ($link['phone_id']) {
            $phone = Db::one('SELECT * FROM phone_pool WHERE id = ?', [$link['phone_id']]);
            $verification = $phone ? PhoneApi::fetchCode($phone, strtotime($link['first_access_time'] ?? Util::now())) : null;
            if ($verification) {
                Db::exec('UPDATE link_pool SET verify_code = ?, status = 0 WHERE id = ?', [$verification, $link['id']]);
                Db::exec('UPDATE phone_pool SET used_count = used_count + 1 WHERE id = ?', [$phone['id']]);
                Response::json(['code' => 1, 'has_code' => true, 'verify_code' => $verification]);
                return;
            }
        }
        Response::json(['code' => 1, 'has_code' => false, 'verify_code' => '']);
    }

    public static function getInfo(Request $r, string $code): void
    {
        $link = self::findLink($code);
        if (!$link || !self::isValid($link)) {
            Response::json(['code' => 0, 'msg' => 'link invalid']);
            return;
        }
        Response::json(['code' => 1, 'msg' => 'ok', 'data' => [
            'phone' => $link['phone'] ?? '',
            'verify_code' => $link['verify_code'] ?? '',
            'interface_type' => $link['interface_type'],
            'instruction' => $link['instruction'] ?? '',
            'media_type' => $link['media_type'] ?? '',
            'media_url' => $link['media_url'] ?? '',
        ]]);
    }

    private static function findLink(string $code): ?array
    {
        return Db::one('SELECT l.*, p.phone, i.content AS instruction, i.media_type, i.media_url FROM link_pool l LEFT JOIN phone_pool p ON p.id = l.phone_id LEFT JOIN instructions i ON i.group_id = l.group_id WHERE l.link_code = ?', [$code]);
    }

    private static function isValid(array $link): bool
    {
        if ((int)$link['status'] !== 1 || !empty($link['verify_code'])) {
            return false;
        }
        if (empty($link['first_access_time'])) {
            return true;
        }
        return time() < strtotime($link['first_access_time']) + ((int)$link['expire_minutes'] * 60);
    }

    private static function assignPhone(array $link): ?array
    {
        return Db::tx(function () use ($link) {
            $phone = Db::one(
                'SELECT * FROM phone_pool WHERE group_id = ? AND status = 1 AND used_count < max_uses ORDER BY used_count ASC, use_rand ASC, updated_at ASC, id ASC LIMIT 1 FOR UPDATE',
                [$link['group_id']]
            );
            if (!$phone) {
                return null;
            }
            Db::exec('UPDATE phone_pool SET use_rand = 0 WHERE group_id = ?', [$link['group_id']]);
            Db::exec('UPDATE phone_pool SET use_rand = 1 WHERE id = ?', [$phone['id']]);
            Db::exec('UPDATE link_pool SET phone_id = ? WHERE id = ? AND phone_id IS NULL', [$phone['id'], $link['id']]);
            return $phone;
        });
    }

    private static function renderLink(array $link, string $err): string
    {
        $phone = htmlspecialchars((string)($link['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
        $instruction = nl2br(htmlspecialchars((string)($link['instruction'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $code = htmlspecialchars((string)$link['link_code'], ENT_QUOTES, 'UTF-8');
        $verifyCode = htmlspecialchars((string)($link['verify_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $errHtml = $err ? '<p class="err">' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</p>' : '';
        $type = in_array($link['interface_type'] ?? 'A', ['A', 'B', 'C'], true) ? $link['interface_type'] : 'A';
        $media = '';
        if (!empty($link['media_url'])) {
            $url = htmlspecialchars((string)$link['media_url'], ENT_QUOTES, 'UTF-8');
            $media = ($link['media_type'] ?? '') === 'video'
                ? '<video controls src="' . $url . '"></video>'
                : '<img src="' . $url . '" alt="">';
        }
        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>验证码</title><link rel="stylesheet" href="/assets/link.css"></head>' .
            '<body class="link link-' . strtolower($type) . '"><main><h1>' . ($type === 'A' ? '验证码获取' : ($type === 'B' ? '安全验证' : '短信验证')) . '</h1>' . $errHtml .
            '<section class="phone"><span>手机号</span><strong>' . $phone . '</strong></section>' .
            '<section class="code"><span>验证码</span><strong id="vcode">' . $verifyCode . '</strong></section>' .
            '<button id="getBtn">获取验证码</button><section class="instruction">' . $media . '<div>' . $instruction . '</div></section></main>' .
            '<script>const c="' . $code . '";async function poll(){const r=await fetch("/link/checkCodeStatus/"+c);const j=await r.json();if(j.has_code){document.getElementById("vcode").textContent=j.verify_code||"";return true}return false}document.getElementById("getBtn").onclick=async()=>{const r=await fetch("/link/getCode/"+c);const j=await r.json();document.getElementById("vcode").textContent=j.data||j.msg||""};setInterval(poll,5000);</script></body></html>';
    }
}

final class UploadController extends Controller
{
    public static function image(Request $r): void
    {
        self::store('file', ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'], (int)Config::get('UPLOAD_MAX_IMAGE_MB', 10));
    }

    public static function video(Request $r): void
    {
        self::store('file', ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogg'], (int)Config::get('UPLOAD_MAX_VIDEO_MB', 100));
    }

    private static function store(string $field, array $allowed, int $maxMb): void
    {
        self::admin();
        if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::json(['errno' => 1, 'message' => 'upload failed']);
            return;
        }
        $file = $_FILES[$field];
        if ((int)$file['size'] > $maxMb * 1024 * 1024) {
            Response::json(['errno' => 1, 'message' => 'file too large']);
            return;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            Response::json(['errno' => 1, 'message' => 'file type not allowed']);
            return;
        }
        $dir = dirname(__DIR__) . '/public/storage/uploads/' . date('Ymd');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = Util::code(16) . '.' . $allowed[$mime];
        $target = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            Response::json(['errno' => 1, 'message' => 'save failed']);
            return;
        }
        $url = '/storage/uploads/' . date('Ymd') . '/' . $name;
        Audit::log('upload', ['url' => $url, 'mime' => $mime]);
        Response::json(['errno' => 0, 'data' => ['url' => $url, 'alt' => basename((string)$file['name']), 'href' => $url]]);
    }
}
