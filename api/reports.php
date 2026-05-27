<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';

try {
    $sql = "
        SELECT
            r.id,
            r.volunteer_id,
            r.elderly_id,
            r.content,
            r.urgency,
            r.status,
            r.classification_source,
            r.created_at
        FROM reports r
        ORDER BY r.created_at DESC, r.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    echo json_encode([
        "success" => true,
        "reports" => $stmt->fetchAll()
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "error" => "Failed to load reports",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}