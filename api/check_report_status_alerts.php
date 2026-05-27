<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/auth.php';

$currentUser = requireLogin($pdo);

function mpEnsureAlertTables($pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_status_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            volunteer_id INT NULL,
            alert_type VARCHAR(100) NOT NULL,
            current_status VARCHAR(50) NOT NULL,
            status_since DATETIME NULL,
            message TEXT NOT NULL,
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            volunteer_notification_created TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_report_status_alert (report_id, alert_type, status_since),
            INDEX (report_id),
            INDEX (volunteer_id),
            INDEX (alert_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

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

function mpReportStatusSince($report, $currentStatus)
{
    $history = $report["status_history"] ?? [];

    if (is_array($history) && count($history) > 0) {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $item = $history[$i];
            $newStatus = mpNormalizeStatus($item["new_status"] ?? "");

            if ($newStatus === $currentStatus && !empty($item["created_at"])) {
                return $item["created_at"];
            }
        }
    }

    return $report["created_at"] ?? null;
}

function mpAlertAlreadyExists($pdo, $reportId, $alertType, $statusSince)
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM report_status_alerts
        WHERE report_id = :report_id
          AND alert_type = :alert_type
          AND status_since = :status_since
        LIMIT 1
    ");

    $stmt->execute([
        ":report_id" => $reportId,
        ":alert_type" => $alertType,
        ":status_since" => $statusSince
    ]);

    return (bool)$stmt->fetch();
}

try {
    mpEnsureAlertTables($pdo);

    $allReports = mpFetchNormalizedReports($pdo);
    $reports = mpAuthFilterReports($pdo, $allReports, $currentUser);

    $createdAlerts = [];

    foreach ($reports as $report) {
        $reportId = (int)($report["id"] ?? 0);
        $volunteerId = (int)($report["volunteer_id"] ?? 0);
        $currentStatus = mpNormalizeStatus($report["normalized_status"] ?? ($report["status"] ?? ""));

        if (!$reportId || !$volunteerId) {
            continue;
        }

        if (!in_array($currentStatus, ["הוגש", "בטיפול"], true)) {
            continue;
        }

        $statusSince = mpReportStatusSince($report, $currentStatus);

        if (!$statusSince) {
            continue;
        }

        $daysInStatus = mpDaysBetween($statusSince);

        if ($daysInStatus < 7) {
            continue;
        }

        $alertType = $currentStatus === "הוגש"
            ? "submitted_7_days"
            : "in_progress_7_days";

        if (mpAlertAlreadyExists($pdo, $reportId, $alertType, $statusSince)) {
            continue;
        }

        if ($currentStatus === "הוגש") {
            $title = "דיווח ממתין לטיפול";
            $message = "דיווח #" . $reportId . " נמצא בסטטוס הוגש כבר מעל שבוע ועדיין לא הועבר לטיפול.";
        } else {
            $title = "דיווח בטיפול מעל שבוע";
            $message = "דיווח #" . $reportId . " נמצא בסטטוס בטיפול כבר מעל שבוע. כדאי לבדוק האם יש התקדמות.";
        }

        $emailSent = false;

        try {
            if (function_exists("sendReportFollowUpReminderEmail")) {
                $emailSent = sendReportFollowUpReminderEmail([
                    "report_id" => (string)$reportId,
                    "volunteer_id" => (string)$volunteerId,
                    "elderly_id" => (string)($report["elderly_id"] ?? ""),
                    "urgency" => $report["urgency"] ?? "",
                    "description" => $report["content"] ?? ($report["description"] ?? ""),
                    "created_at" => $report["created_at"] ?? "",
                    "current_status" => $currentStatus,
                    "status_since" => $statusSince,
                    "days_in_status" => (string)$daysInStatus,
                    "alert_message" => $message
                ]);
            }
        } catch (Throwable $emailError) {
            error_log("STATUS ALERT EMAIL ERROR: " . $emailError->getMessage());
        }

        $pdo->beginTransaction();

        $alertStmt = $pdo->prepare("
            INSERT INTO report_status_alerts
            (report_id, volunteer_id, alert_type, current_status, status_since, message, email_sent, volunteer_notification_created, created_at)
            VALUES
            (:report_id, :volunteer_id, :alert_type, :current_status, :status_since, :message, :email_sent, 1, NOW())
        ");

        $alertStmt->execute([
            ":report_id" => $reportId,
            ":volunteer_id" => $volunteerId,
            ":alert_type" => $alertType,
            ":current_status" => $currentStatus,
            ":status_since" => $statusSince,
            ":message" => $message,
            ":email_sent" => $emailSent ? 1 : 0
        ]);

        $notificationStmt = $pdo->prepare("
            INSERT INTO volunteer_notifications
            (volunteer_id, report_id, title, message, is_read, created_at)
            VALUES
            (:volunteer_id, :report_id, :title, :message, 0, NOW())
        ");

        $notificationStmt->execute([
            ":volunteer_id" => $volunteerId,
            ":report_id" => $reportId,
            ":title" => $title,
            ":message" => $message
        ]);

        $pdo->commit();

        $createdAlerts[] = [
            "report_id" => $reportId,
            "volunteer_id" => $volunteerId,
            "current_status" => $currentStatus,
            "days_in_status" => $daysInStatus,
            "email_sent" => $emailSent,
            "message" => $message
        ];
    }

    echo json_encode([
        "success" => true,
        "alerts_created" => count($createdAlerts),
        "alerts" => $createdAlerts
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("CHECK REPORT STATUS ALERTS ERROR: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to check report status alerts",
        "error" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}