<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/auth.php';

$currentUser = requireLogin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

requireRole("ngo_manager", $currentUser);

$input = json_decode(file_get_contents("php://input"), true);

$reportId = isset($input["report_id"]) ? (int)$input["report_id"] : 0;
$newStatus = isset($input["status"]) ? trim($input["status"]) : "";

$allowedStatuses = ["הוגש", "בטיפול", "טופל"];

if (!$reportId || !in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid report id or status"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (!canAccessReport($pdo, $reportId, $currentUser)) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "אין לך הרשאה לעדכן את הדיווח הזה."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            volunteer_id,
            elderly_id,
            content,
            urgency,
            status,
            created_at
        FROM reports
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ":id" => $reportId
    ]);

    $report = $stmt->fetch();

    if (!$report) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Report not found"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $oldStatus = $report["status"];

    if ($oldStatus === $newStatus) {
        echo json_encode([
            "success" => true,
            "message" => "Status already has this value",
            "old_status" => $oldStatus,
            "new_status" => $newStatus,
            "history_saved" => false
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    $updateStmt = $pdo->prepare("
        UPDATE reports
        SET status = :status
        WHERE id = :id
    ");

    $updateStmt->execute([
        ":status" => $newStatus,
        ":id" => $reportId
    ]);

    $historySaved = false;

    if (mpDbTableExists($pdo, "report_status_history")) {
        $historyColumns = mpDbTableColumns($pdo, "report_status_history");

        if (
            mpColumnExists($historyColumns, "report_id") &&
            mpColumnExists($historyColumns, "old_status") &&
            mpColumnExists($historyColumns, "new_status")
        ) {
            $historyInsertColumns = ["report_id", "old_status", "new_status"];
            $historyPlaceholders = [":report_id", ":old_status", ":new_status"];
            $historyParams = [
                ":report_id" => $reportId,
                ":old_status" => $oldStatus,
                ":new_status" => $newStatus
            ];

            if (mpColumnExists($historyColumns, "changed_by")) {
                $historyInsertColumns[] = "changed_by";
                $historyPlaceholders[] = ":changed_by";
                $historyParams[":changed_by"] = (int)($currentUser["user_id"] ?? $currentUser["id"] ?? 1);
            }

            if (mpColumnExists($historyColumns, "notes")) {
                $historyInsertColumns[] = "notes";
                $historyPlaceholders[] = ":notes";
                $historyParams[":notes"] = "Status updated from reports dashboard";
            }

            if (mpColumnExists($historyColumns, "created_at")) {
                $historyInsertColumns[] = "created_at";
                $historyPlaceholders[] = "NOW()";
            }

            try {
                $historyStmt = $pdo->prepare("
                    INSERT INTO report_status_history
                    (" . implode(", ", array_map("mpQuoteIdentifier", $historyInsertColumns)) . ")
                    VALUES
                    (" . implode(", ", $historyPlaceholders) . ")
                ");

                $historyStmt->execute($historyParams);
                $historySaved = true;
            } catch (Throwable $historyError) {
                error_log("STATUS HISTORY INSERT ERROR: " . $historyError->getMessage());
            }
        }
    }

    $pdo->commit();

    $emailSent = false;

    try {
        if (function_exists("sendReportStatusChangedEmail")) {
            $emailSent = sendReportStatusChangedEmail([
                "report_id" => (string)$reportId,
                "old_status" => $oldStatus,
                "new_status" => $newStatus,
                "volunteer_id" => (string)$report["volunteer_id"],
                "elderly_id" => (string)$report["elderly_id"],
                "urgency" => $report["urgency"],
                "description" => $report["content"],
                "created_at" => $report["created_at"],
                "updated_at" => date("Y-m-d H:i:s")
            ]);
        }
    } catch (Throwable $emailError) {
        error_log("STATUS EMAIL ERROR: " . $emailError->getMessage());
    }

    echo json_encode([
        "success" => true,
        "message" => "Status updated successfully",
        "old_status" => $oldStatus,
        "new_status" => $newStatus,
        "history_saved" => $historySaved,
        "email_sent" => $emailSent
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to update status",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
