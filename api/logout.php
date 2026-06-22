<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
kofc_cors();
$_SESSION = [];
session_destroy();
echo json_encode(['ok' => true]);
