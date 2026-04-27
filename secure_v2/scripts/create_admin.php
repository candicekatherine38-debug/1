<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

\SecureV2\Config::load();

$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? '';

if (strlen($password) < 8) {
    fwrite(STDERR, "Usage: php scripts/create_admin.php <username> <password-at-least-8-chars>\n");
    exit(1);
}

$exists = \SecureV2\Db::one('SELECT id FROM admin WHERE username = ?', [$username]);
if ($exists) {
    \SecureV2\Db::exec('UPDATE admin SET password = ?, is_super = 1, status = 1 WHERE username = ?', [password_hash($password, PASSWORD_DEFAULT), $username]);
    echo "Updated super admin: {$username}\n";
    exit(0);
}

\SecureV2\Db::exec('INSERT INTO admin (username, password, is_super, status) VALUES (?, ?, 1, 1)', [$username, password_hash($password, PASSWORD_DEFAULT)]);
echo "Created super admin: {$username}\n";
