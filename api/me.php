<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require __DIR__ . '/cors.php';
require __DIR__ . '/config.php';
kofc_cors();
if (empty($_SESSION['agent_id'])) { http_response_code(401); echo json_encode(['error' => 'not authenticated']); exit; }
echo json_encode([
    'username'    => $_SESSION['agent_id'],
    'is_admin'    => !empty($_SESSION['is_admin']),
    'must_change' => !empty($_SESSION['must_change']),
]);
