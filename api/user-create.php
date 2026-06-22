<?php
/** CLI. Create/update a login user. Usage: php user-create.php <username> <password> [agent|admin] */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/config.php';

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';
$role     = ($argv[3] ?? 'agent') === 'admin' ? 'admin' : 'agent';
if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php user-create.php <username> <password> [agent|admin]\n"); exit(1);
}
$hash = password_hash($password, PASSWORD_DEFAULT);
kofc_db()->prepare(
    'INSERT INTO users (username, password_hash, role, must_change_password, created_at)
     VALUES (:u, :h, :r, 0, NOW())
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role), must_change_password = 0'
)->execute([':u' => $username, ':h' => $hash, ':r' => $role]);
echo "User '{$username}' ({$role}) saved.\n";
