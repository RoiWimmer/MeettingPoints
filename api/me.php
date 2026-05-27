<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/auth.php';

$user = getCurrentUser($pdo);

if (!$user) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "error" => "unauthorized",
        "message" => "נדרשת התחברות למערכת."
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    "success" => true,
    "user" => mpAuthPublicUser($user)
], JSON_UNESCAPED_UNICODE);
