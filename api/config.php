<?php
/**
 * Config — local-first, AWS/RDS-ready.
 * Secrets are loaded from the first of these that exists:
 *   1. the file named by env var KOFC_CONFIG
 *   2. /etc/kofc/config.local.php          (recommended on EC2 — outside the webroot)
 *   3. <this dir>/config.local.php          (local XAMPP dev)
 * Each returns an associative array. Nothing secret lives in this committed file.
 */

$KOFC_LOCAL = [];
$__candidates = [];
if (($__p = getenv('KOFC_CONFIG')) && is_file($__p)) { $__candidates[] = $__p; }
$__candidates[] = '/etc/kofc/config.local.php';
$__candidates[] = __DIR__ . '/config.local.php';
foreach ($__candidates as $__c) {
    if (is_file($__c)) { $KOFC_LOCAL = require $__c; break; }
}

function kofc_cfg(string $key, string $env, $default = '')
{
    global $KOFC_LOCAL;
    if (array_key_exists($key, $KOFC_LOCAL) && $KOFC_LOCAL[$key] !== '') {
        return $KOFC_LOCAL[$key];
    }
    $v = getenv($env);
    return ($v !== false && $v !== '') ? $v : $default;
}

define('KOFC_DB_HOST', kofc_cfg('db_host', 'KOFC_DB_HOST', '127.0.0.1'));
define('KOFC_DB_NAME', kofc_cfg('db_name', 'KOFC_DB_NAME', 'kofc_advisor'));
define('KOFC_DB_USER', kofc_cfg('db_user', 'KOFC_DB_USER', 'root'));
define('KOFC_DB_PASS', kofc_cfg('db_pass', 'KOFC_DB_PASS', ''));

define('OPENAI_API_KEY', kofc_cfg('openai_api_key', 'OPENAI_API_KEY', ''));
define('AI_MODEL', kofc_cfg('ai_model', 'AI_MODEL', 'gpt-4o-mini'));
define('AI_MAX_TOKENS', 1500);
define('AI_MOCK', filter_var(kofc_cfg('ai_mock', 'AI_MOCK', false), FILTER_VALIDATE_BOOLEAN));
define('AUTH_DISABLED', filter_var(kofc_cfg('auth_disabled', 'AUTH_DISABLED', false), FILTER_VALIDATE_BOOLEAN));

function kofc_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . KOFC_DB_HOST . ';dbname=' . KOFC_DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, KOFC_DB_USER, KOFC_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function kofc_require_agent(): string
{
    if (!empty($_SESSION['agent_id'])) { return $_SESSION['agent_id']; }
    if (AUTH_DISABLED) { return 'agent_demo'; }
    http_response_code(401);
    echo json_encode(['error' => 'not authenticated']);
    exit;
}
