<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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

    $historyStmt = $pdo->prepare("
        INSERT INTO report_status_history
        (report_id, old_status, new_status, changed_by, notes, created_at)
        VALUES
        (:report_id, :old_status, :new_status, :changed_by, :notes, NOW())
    ");

    $historyStmt->execute([
        ":report_id" => $reportId,
        ":old_status" => $oldStatus,
        ":new_status" => $newStatus,
        ":changed_by" => 1,
        ":notes" => "Status updated from reports dashboard"
    ]);

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
    } catch (Exception $emailError) {
        error_log("STATUS EMAIL ERROR: " . $emailError->getMessage());
    }

    echo json_encode([
        "success" => true,
        "message" => "Status updated successfully",
        "old_status" => $oldStatus,
        "new_status" => $newStatus,
        "history_saved" => true,
        "email_sent" => $emailSent
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
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