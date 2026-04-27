<?php
declare(strict_types=1);

namespace SecureV2;

use PDO;
use Throwable;

final class Config
{
    private static array $values = [];

    public static function load(): void
    {
        $file = dirname(__DIR__) . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                self::$values[$key] = trim($value, "\"'");
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? getenv($key) ?: $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? 'true' : 'false');
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }
}

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::get('DB_PORT', '3306');
        $db = Config::get('DB_DATABASE', 'admin_system_v2');
        $charset = Config::get('DB_CHARSET', 'utf8mb4');
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        self::$pdo = new PDO($dsn, (string)Config::get('DB_USERNAME'), (string)Config::get('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$pdo;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::exec($sql, $params);
        return (int)self::pdo()->lastInsertId();
    }

    public static function tx(callable $callback): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $body;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $this->path = '/' . trim($uri, '/');
        if ($this->path !== '/') {
            $this->path = rtrim($this->path, '/');
        }
        $this->query = $_GET;
        $this->body = $_POST;
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($type, 'application/json')) {
            $json = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (is_array($json)) {
                $this->body = $json;
            }
        }
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function arrayInput(string $key): array
    {
        $value = $this->input($key, []);
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $value === null || $value === '' ? [] : [$value];
    }
}

final class Response
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function html(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
    }

    public static function text(string $text, string $filename = ''): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        if ($filename !== '') {
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        }
        echo $text;
    }

    public static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
    }
}

final class Auth
{
    public static function startSession(): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_name((string)Config::get('SESSION_NAME', 'secure_admin_sid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function current(): ?array
    {
        $id = (int)($_SESSION['admin_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        return Db::one('SELECT id, username, is_super, parent_id, status FROM admin WHERE id = ? AND status = 1', [$id]);
    }

    public static function requireLogin(): array
    {
        $admin = self::current();
        if (!$admin) {
            Response::json(['code' => 401, 'msg' => 'login required'], 401);
            exit;
        }
        return $admin;
    }

    public static function requireSuper(): array
    {
        $admin = self::requireLogin();
        if ((int)$admin['is_super'] !== 1) {
            Response::json(['code' => 403, 'msg' => 'permission denied'], 403);
            exit;
        }
        return $admin;
    }

    public static function verifyCsrf(Request $request): void
    {
        if ($request->method !== 'POST') {
            return;
        }
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $request->input('_csrf', '');
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$token)) {
            Response::json(['code' => 419, 'msg' => 'invalid csrf token'], 419);
            exit;
        }
    }

    public static function login(array $admin): void
    {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['id'];
        $_SESSION['username'] = $admin['username'];
        $_SESSION['is_super'] = (int)$admin['is_super'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}

final class Guard
{
    public static function ownedGroup(int $groupId, int $adminId): array
    {
        $group = Db::one('SELECT * FROM groups WHERE id = ? AND admin_id = ?', [$groupId, $adminId]);
        if (!$group) {
            Response::json(['code' => 404, 'msg' => 'group not found or permission denied'], 404);
            exit;
        }
        return $group;
    }
}

final class Audit
{
    public static function log(string $action, array $payload = []): void
    {
        try {
            Db::exec(
                'INSERT INTO audit_log (admin_id, action, ip, payload) VALUES (?, ?, ?, ?)',
                [
                    $_SESSION['admin_id'] ?? null,
                    $action,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        } catch (Throwable) {
            // Audit failure must not break the user flow.
        }
    }
}

final class Util
{
    public static function code(int $bytes = 9): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function baseUrl(): string
    {
        $configured = trim((string)Config::get('APP_URL', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}

final class PhoneApi
{
    public static function fetchCode(array $phone, int $linkOpenTime): ?string
    {
        if ((int)$phone['status'] !== 1 || (int)$phone['used_count'] >= (int)$phone['max_uses']) {
            return null;
        }
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "User-Agent: SecureV2/1.0\r\n",
            ],
        ]);
        $raw = @file_get_contents((string)$phone['api_url'], false, $context);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return self::extractCode($raw);
        }
        if (($data['code'] ?? null) === 201 && ($data['msg'] ?? '') === 'No data') {
            Db::exec('DELETE FROM phone_verification_log WHERE phone_id = ?', [$phone['id']]);
            return null;
        }
        $candidates = [
            $data['data']['code'] ?? null,
            $data['data'] ?? null,
            $data['message'] ?? null,
            $data['msg'] ?? null,
            $raw,
        ];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $code = self::extractCode($candidate);
            if ($code) {
                self::rememberCode((int)$phone['id'], $code);
                return self::isNewEnough((int)$phone['id'], $code, $linkOpenTime) ? $code : null;
            }
        }
        return null;
    }

    private static function extractCode(string $text): ?string
    {
        if (preg_match('/(?<!\d)(\d{4,6})(?!\d)/', $text, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function rememberCode(int $phoneId, string $code): void
    {
        $exists = Db::one('SELECT id FROM phone_verification_log WHERE phone_id = ? AND code = ?', [$phoneId, $code]);
        if (!$exists) {
            Db::exec('INSERT INTO phone_verification_log (phone_id, code, created_at) VALUES (?, ?, ?)', [$phoneId, $code, Util::now()]);
        }
        $keep = Db::all('SELECT id FROM phone_verification_log WHERE phone_id = ? ORDER BY created_at DESC LIMIT 5', [$phoneId]);
        $ids = array_column($keep, 'id');
        if ($ids) {
            Db::exec('DELETE FROM phone_verification_log WHERE phone_id = ? AND id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$phoneId], $ids));
        }
    }

    private static function isNewEnough(int $phoneId, string $code, int $linkOpenTime): bool
    {
        $row = Db::one('SELECT created_at FROM phone_verification_log WHERE phone_id = ? AND code = ? ORDER BY created_at DESC LIMIT 1', [$phoneId, $code]);
        if (!$row) {
            return true;
        }
        return strtotime($row['created_at']) >= $linkOpenTime;
    }
}

final class App
{
    private const PUBLIC_POST = ['/admin/doLogin'];
    private const PUBLIC_GET_PREFIXES = ['/link/'];
    private const AUTH_PREFIXES = ['/admin', '/group', '/upload'];

    public static function run(): void
    {
        Config::load();
        Auth::startSession();
        $request = new Request();

        try {
            self::dispatch($request);
        } catch (Throwable $e) {
            if (Config::bool('APP_DEBUG')) {
                Response::json(['code' => 500, 'msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
            } else {
                Response::json(['code' => 500, 'msg' => 'server error'], 500);
            }
        }
    }

    private static function dispatch(Request $r): void
    {
        if ($r->path === '/') {
            Response::redirect('/admin/login');
            return;
        }

        $isPublic = self::isPublic($r);
        if (!$isPublic && self::needsAuth($r->path)) {
            Auth::requireLogin();
            Auth::verifyCsrf($r);
        }

        match (true) {
            $r->method === 'GET' && $r->path === '/admin/login' => Controllers\AuthController::loginPage($r),
            $r->method === 'POST' && $r->path === '/admin/doLogin' => Controllers\AuthController::login($r),
            $r->method === 'GET' && $r->path === '/admin/logout' => Controllers\AuthController::logout($r),
            $r->method === 'GET' && $r->path === '/admin/csrf' => Controllers\AuthController::csrf($r),
            $r->method === 'GET' && $r->path === '/admin/me' => Controllers\AuthController::me($r),
            $r->method === 'GET' && $r->path === '/admin/index' => Controllers\AuthController::adminPage($r),
            $r->method === 'GET' && $r->path === '/admin/changePassword' => Controllers\AuthController::changePasswordPage($r),
            $r->method === 'GET' && $r->path === '/admin/list' => Controllers\AdminController::list($r),
            $r->method === 'POST' && $r->path === '/admin/create' => Controllers\AdminController::create($r),
            $r->method === 'POST' && $r->path === '/admin/delete' => Controllers\AdminController::delete($r),
            $r->method === 'POST' && $r->path === '/admin/updateStatus' => Controllers\AdminController::updateStatus($r),
            $r->method === 'POST' && $r->path === '/admin/switchTo' => Controllers\AdminController::switchToAdmin($r),
            $r->method === 'POST' && $r->path === '/admin/returnToSuper' => Controllers\AdminController::returnToSuper($r),
            $r->method === 'POST' && $r->path === '/admin/changeAdminPassword' => Controllers\AdminController::changeAdminPassword($r),
            $r->method === 'POST' && $r->path === '/admin/doChangePassword' => Controllers\AdminController::changeOwnPassword($r),
            $r->method === 'GET' && $r->path === '/group' => Controllers\GroupController::index($r),
            $r->method === 'GET' && $r->path === '/group/list' => Controllers\GroupController::list($r),
            $r->method === 'POST' && $r->path === '/group/create' => Controllers\GroupController::create($r),
            $r->method === 'POST' && $r->path === '/group/update' => Controllers\GroupController::update($r),
            $r->method === 'POST' && preg_match('#^/group/delete/(\d+)$#', $r->path, $m) === 1 => Controllers\GroupController::delete($r, (int)$m[1]),
            $r->method === 'GET' && $r->path === '/group/phones' => Controllers\GroupController::phones($r),
            $r->method === 'POST' && $r->path === '/group/addPhone' => Controllers\GroupController::addPhone($r),
            $r->method === 'POST' && $r->path === '/group/batchAddPhone' => Controllers\GroupController::batchAddPhone($r),
            $r->method === 'POST' && $r->path === '/group/deletePhone' => Controllers\GroupController::deletePhone($r),
            $r->method === 'POST' && $r->path === '/group/batchDeletePhone' => Controllers\GroupController::batchDeletePhone($r),
            $r->method === 'POST' && $r->path === '/group/batchSetMaxUses' => Controllers\GroupController::batchSetMaxUses($r),
            $r->method === 'POST' && preg_match('#^/group/togglePhoneStatus/(\d+)$#', $r->path, $m) === 1 => Controllers\GroupController::togglePhoneStatus($r, (int)$m[1]),
            $r->method === 'POST' && $r->path === '/group/batchTogglePhoneStatus' => Controllers\GroupController::batchTogglePhoneStatus($r),
            $r->method === 'POST' && preg_match('#^/group/resetPhoneUsage/(\d+)$#', $r->path, $m) === 1 => Controllers\GroupController::resetPhoneUsage($r, (int)$m[1]),
            $r->method === 'POST' && $r->path === '/group/batchResetPhoneUsage' => Controllers\GroupController::batchResetPhoneUsage($r),
            $r->method === 'POST' && $r->path === '/group/generateLinks' => Controllers\GroupController::generateLinks($r),
            $r->method === 'POST' && $r->path === '/group/generateLinksByPhones' => Controllers\GroupController::generateLinksByPhones($r),
            $r->method === 'GET' && $r->path === '/group/links' => Controllers\GroupController::links($r),
            $r->method === 'GET' && $r->path === '/group/exportLinks' => Controllers\GroupController::exportLinks($r),
            $r->method === 'GET' && $r->path === '/group/exportValidLinks' => Controllers\GroupController::exportValidLinks($r),
            $r->method === 'GET' && $r->path === '/group/exportPhones' => Controllers\GroupController::exportPhones($r),
            $r->method === 'POST' && $r->path === '/group/deleteLink' => Controllers\GroupController::deleteLink($r),
            $r->method === 'POST' && $r->path === '/group/batchDeleteLink' => Controllers\GroupController::batchDeleteLink($r),
            $r->method === 'POST' && $r->path === '/group/deleteLinkByCode' => Controllers\GroupController::deleteLinkByCode($r),
            $r->method === 'POST' && $r->path === '/group/recycleLinks' => Controllers\GroupController::recycleLinks($r),
            $r->method === 'POST' && $r->path === '/group/batchDisablePhonesByLinks' => Controllers\GroupController::batchDisablePhonesByLinks($r),
            $r->method === 'POST' && $r->path === '/group/batchResetLinks' => Controllers\GroupController::batchResetLinks($r),
            $r->method === 'POST' && $r->path === '/group/updateInstructions' => Controllers\GroupController::updateInstructions($r),
            $r->method === 'GET' && $r->path === '/group/getInstructions' => Controllers\GroupController::getInstructions($r),
            $r->method === 'POST' && $r->path === '/group/updateScheduleSettings' => Controllers\GroupController::updateScheduleSettings($r),
            $r->method === 'GET' && preg_match('#^/group/getScheduleSettings/(\d+)$#', $r->path, $m) === 1 => Controllers\GroupController::getScheduleSettings($r, (int)$m[1]),
            $r->method === 'POST' && $r->path === '/group/manualResetPhoneUsage' => Controllers\GroupController::manualResetPhoneUsage($r),
            $r->method === 'POST' && $r->path === '/group/manualDeleteExpirePhones' => Controllers\GroupController::manualDeleteExpirePhones($r),
            $r->method === 'POST' && $r->path === '/upload/image' => Controllers\UploadController::image($r),
            $r->method === 'POST' && $r->path === '/upload/video' => Controllers\UploadController::video($r),
            preg_match('#^/link/getCode/([A-Za-z0-9_-]+)$#', $r->path, $m) === 1 => Controllers\LinkController::getCode($r, $m[1]),
            preg_match('#^/link/checkCodeStatus/([A-Za-z0-9_-]+)$#', $r->path, $m) === 1 => Controllers\LinkController::checkCodeStatus($r, $m[1]),
            preg_match('#^/link/getInfo/([A-Za-z0-9_-]+)$#', $r->path, $m) === 1 => Controllers\LinkController::getInfo($r, $m[1]),
            preg_match('#^/link/([A-Za-z0-9_-]+)$#', $r->path, $m) === 1 => Controllers\LinkController::access($r, $m[1]),
            default => Response::json(['code' => 404, 'msg' => 'not found'], 404),
        };
    }

    private static function isPublic(Request $r): bool
    {
        if ($r->method === 'POST' && in_array($r->path, self::PUBLIC_POST, true)) {
            return true;
        }
        foreach (self::PUBLIC_GET_PREFIXES as $prefix) {
            if (str_starts_with($r->path, $prefix)) {
                return true;
            }
        }
        return $r->method === 'GET' && $r->path === '/admin/login';
    }

    private static function needsAuth(string $path): bool
    {
        foreach (self::AUTH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }
}

require __DIR__ . '/controllers.php';
