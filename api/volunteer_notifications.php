<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/auth.php';

$currentUser = requireLogin($pdo);

function mpEnsureVolunteerNotificationsTable($pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS volunteer_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            volunteer_id INT NOT NULL,
            report_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            INDEX (volunteer_id),
            INDEX (report_id),
            INDEX (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

try {
    mpEnsureVolunteerNotificationsTable($pdo);

    if (!mpAuthIsVolunteer($currentUser)) {
        echo json_encode([
            "success" => true,
            "notifications" => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $volunteerId = (int)($currentUser["volunteer_id"] ?? 0);

    if (!$volunteerId) {
        echo json_encode([
            "success" => true,
            "notifications" => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = json_decode(file_get_contents("php://input"), true);
        $action = $input["action"] ?? "mark_all_read";

        if ($action === "mark_all_read") {
            $stmt = $pdo->prepare("
                UPDATE volunteer_notifications
                SET is_read = 1, read_at = NOW()
                WHERE volunteer_id = :volunteer_id
                  AND is_read = 0
            ");

            $stmt->execute([
                ":volunteer_id" => $volunteerId
            ]);
        }

        echo json_encode([
            "success" => true
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            volunteer_id,
            report_id,
            title,
            message,
            is_read,
            created_at
        FROM volunteer_notifications
        WHERE volunteer_id = :volunteer_id
          AND is_read = 0
        ORDER BY created_at DESC, id DESC
        LIMIT 5
    ");

    $stmt->execute([
        ":volunteer_id" => $volunteerId
    ]);

    echo json_encode([
        "success" => true,
        "notifications" => $stmt->fetchAll()
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("VOLUNTEER NOTIFICATIONS ERROR: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to load notifications"
    ], JSON_UNESCAPED_UNICODE);
}