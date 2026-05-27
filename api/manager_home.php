<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/auth.php';

function managerHomeResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function managerHomeLower($value) {
    if (function_exists("mb_strtolower")) {
        return mb_strtolower(trim((string)$value), "UTF-8");
    }

    return strtolower(trim((string)$value));
}

function managerHomeStatusBucket($status) {
    $status = managerHomeLower($status);

    $newStatuses = ["new", "open", "submitted", "הוגש", "חדש", "פתוח"];
    $inProgressStatuses = ["in_progress", "processing", "בטיפול"];
    $waitingStatuses = ["waiting_external", "pending_external", "ממתין", "ממתין לגורם חיצוני"];
    $doneStatuses = ["done", "closed", "resolved", "טופל", "סגור", "נסגר"];

    if (in_array($status, $newStatuses, true)) {
        return "new";
    }

    if (in_array($status, $inProgressStatuses, true)) {
        return "inProgress";
    }

    if (in_array($status, $waitingStatuses, true)) {
        return "waitingExternal";
    }

    if (in_array($status, $doneStatuses, true)) {
        return "done";
    }

    return "unknown";
}

function managerHomeIsDoneStatus($status) {
    return managerHomeStatusBucket($status) === "done";
}

function managerHomeIsNewStatus($status) {
    return managerHomeStatusBucket($status) === "new";
}

function managerHomeIsUrgent($urgency) {
    $urgency = managerHomeLower($urgency);

    return in_array($urgency, ["urgent", "high", "critical", "דחוף", "גבוהה", "קריטית"], true);
}

function managerHomeDaysOpen($createdAt) {
    if (!$createdAt) {
        return 0;
    }

    try {
        $created = new DateTime((string)$createdAt);
        $today = new DateTime("today");

        return max(0, (int)$created->diff($today)->format("%a"));
    } catch (Throwable $e) {
        return 0;
    }
}

function managerHomeUrgencyLabel($urgency) {
    $urgencyLower = managerHomeLower($urgency);

    if (in_array($urgencyLower, ["critical", "קריטית"], true)) {
        return "קריטית";
    }

    if (in_array($urgencyLower, ["urgent", "high", "דחוף", "גבוהה"], true)) {
        return "דחוף";
    }

    if (in_array($urgencyLower, ["medium", "בינונית"], true)) {
        return "בינוני";
    }

    if (in_array($urgencyLower, ["low", "נמוכה"], true)) {
        return "רגיל";
    }

    return trim((string)$urgency) !== "" ? (string)$urgency : "לא ידוע";
}

function managerHomeResponsibleName($row) {
    $name = trim((string)($row["volunteer_first_name"] ?? "") . " " . (string)($row["volunteer_last_name"] ?? ""));

    if ($name !== "") {
        return $name;
    }

    $fullName = trim((string)($row["volunteer_full_name"] ?? ""));

    return $fullName !== "" ? $fullName : "ללא אחראי";
}

function managerHomeFirstExistingColumn($columns, $tableAlias, $candidates, $alias, $fallback = "''") {
    foreach ($candidates as $column) {
        if (mpColumnExists($columns, $column)) {
            return $tableAlias . "." . mpQuoteIdentifier($column) . " AS " . mpQuoteIdentifier($alias);
        }
    }

    return $fallback . " AS " . mpQuoteIdentifier($alias);
}

function managerHomeFetchReports($pdo, $organizationId) {
    $volunteerColumns = mpDbTableColumns($pdo, "volunteers");
    $userColumns = mpDbTableColumns($pdo, "users");
    $joinUsers = mpColumnExists($volunteerColumns, "user_id") && mpColumnExists($userColumns, "id");
    $selectUserColumns = $joinUsers ? $userColumns : [];
    $whereParts = [];

    if ($joinUsers && mpColumnExists($userColumns, "organization_id")) {
        $whereParts[] = "u.organization_id = :organization_id";
    }

    if (mpColumnExists($volunteerColumns, "organization_id")) {
        $whereParts[] = "v.organization_id = :organization_id";
    }

    if (!$whereParts) {
        return [];
    }

    $select = [
        "r.id",
        "r.volunteer_id",
        "r.elderly_id",
        "r.content",
        "r.urgency",
        "r.status",
        "r.created_at",
        "DATEDIFF(CURDATE(), r.created_at) AS days_open",
        managerHomeFirstExistingColumn($volunteerColumns, "v", ["city", "area", "service_area", "address"], "volunteer_city"),
        managerHomeFirstExistingColumn($selectUserColumns, "u", ["first_name"], "volunteer_first_name"),
        managerHomeFirstExistingColumn($selectUserColumns, "u", ["last_name"], "volunteer_last_name"),
        managerHomeFirstExistingColumn($selectUserColumns, "u", ["full_name", "name", "display_name"], "volunteer_full_name")
    ];

    $stmt = $pdo->prepare("
        SELECT " . implode(", ", $select) . "
        FROM reports r
        INNER JOIN volunteers v ON v.id = r.volunteer_id
        " . ($joinUsers ? "LEFT JOIN users u ON u.id = v.user_id" : "") . "
        WHERE (" . implode(" OR ", $whereParts) . ")
        ORDER BY r.created_at ASC, r.id ASC
    ");

    $stmt->execute([
        ":organization_id" => (int)$organizationId
    ]);

    return $stmt->fetchAll();
}

function managerHomeActiveVolunteers($pdo, $organizationId) {
    $volunteerColumns = mpDbTableColumns($pdo, "volunteers");
    $userColumns = mpDbTableColumns($pdo, "users");
    $joinUsers = mpColumnExists($volunteerColumns, "user_id") && mpColumnExists($userColumns, "id");
    $whereParts = [];
    $activeParts = [];

    if ($joinUsers && mpColumnExists($userColumns, "organization_id")) {
        $whereParts[] = "u.organization_id = :organization_id";
    }

    if (mpColumnExists($volunteerColumns, "organization_id")) {
        $whereParts[] = "v.organization_id = :organization_id";
    }

    if (!$whereParts) {
        return 0;
    }

    if (mpColumnExists($volunteerColumns, "active")) {
        $activeParts[] = "(v.active = 1 OR v.active IS NULL)";
    }

    if ($joinUsers && mpColumnExists($userColumns, "active")) {
        $activeParts[] = "(u.active = 1 OR u.active IS NULL)";
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM volunteers v
        " . ($joinUsers ? "LEFT JOIN users u ON u.id = v.user_id" : "") . "
        WHERE (" . implode(" OR ", $whereParts) . ")
        " . ($activeParts ? "AND " . implode(" AND ", $activeParts) : "") . "
    ");

    $stmt->execute([
        ":organization_id" => (int)$organizationId
    ]);

    return (int)$stmt->fetchColumn();
}

function managerHomeElderlyInFollowup($pdo, $organizationId, $reports) {
    if (mpDbTableExists($pdo, "volunteer_elderly_assignments")) {
        $assignmentColumns = mpDbTableColumns($pdo, "volunteer_elderly_assignments");
        $volunteerColumns = mpDbTableColumns($pdo, "volunteers");
        $userColumns = mpDbTableColumns($pdo, "users");
        $joinUsers = mpColumnExists($volunteerColumns, "user_id") && mpColumnExists($userColumns, "id");
        $whereParts = [];

        if ($joinUsers && mpColumnExists($userColumns, "organization_id")) {
            $whereParts[] = "u.organization_id = :organization_id";
        }

        if (mpColumnExists($assignmentColumns, "organization_id")) {
            $whereParts[] = "vea.organization_id = :organization_id";
        }

        if (mpColumnExists($volunteerColumns, "organization_id")) {
            $whereParts[] = "v.organization_id = :organization_id";
        }

        if ($whereParts) {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT vea.elderly_id) AS total
                FROM volunteer_elderly_assignments vea
                INNER JOIN volunteers v ON v.id = vea.volunteer_id
                " . ($joinUsers ? "LEFT JOIN users u ON u.id = v.user_id" : "") . "
                WHERE (" . implode(" OR ", $whereParts) . ")
                  AND vea.elderly_id IS NOT NULL
            ");

            $stmt->execute([
                ":organization_id" => (int)$organizationId
            ]);

            $assignmentCount = (int)$stmt->fetchColumn();

            if ($assignmentCount > 0) {
                return $assignmentCount;
            }
        }
    }

    $elderlyIds = [];

    foreach ($reports as $report) {
        if (!empty($report["elderly_id"])) {
            $elderlyIds[(int)$report["elderly_id"]] = true;
        }
    }

    return count($elderlyIds);
}

try {
    $currentUser = requireLogin($pdo);
    requireRole("ngo_manager", $currentUser);

    $organizationId = (int)($currentUser["organization_id"] ?? 0);

    if (!$organizationId) {
        managerHomeResponse([
            "success" => false,
            "message" => "המשתמש המחובר אינו משויך לעמותה."
        ], 403);
    }

    foreach (["reports", "volunteers", "users"] as $tableName) {
        if (!mpDbTableExists($pdo, $tableName)) {
            managerHomeResponse([
                "success" => false,
                "message" => "חסרה טבלת נתונים נדרשת: " . $tableName
            ], 500);
        }
    }

    $reports = managerHomeFetchReports($pdo, $organizationId);
    $statusBreakdown = [
        "new" => 0,
        "inProgress" => 0,
        "waitingExternal" => 0,
        "done" => 0,
        "unknown" => 0
    ];
    $openReports = 0;
    $urgentReports = 0;
    $overdueOpenReports = 0;
    $attentionReports = [];

    foreach ($reports as $report) {
        $bucket = managerHomeStatusBucket($report["status"] ?? "");
        $statusBreakdown[$bucket] = ($statusBreakdown[$bucket] ?? 0) + 1;

        $isOpen = !managerHomeIsDoneStatus($report["status"] ?? "");
        $isUrgent = managerHomeIsUrgent($report["urgency"] ?? "");
        $daysOpen = isset($report["days_open"]) ? max(0, (int)$report["days_open"]) : managerHomeDaysOpen($report["created_at"] ?? null);

        if ($isOpen) {
            $openReports++;

            if ($daysOpen > 3) {
                $overdueOpenReports++;
            }
        }

        if ($isOpen && $isUrgent) {
            $urgentReports++;
        }

        if ($isOpen && ($isUrgent || managerHomeIsNewStatus($report["status"] ?? "") || $daysOpen > 3)) {
            $attentionReports[] = [
                "id" => (int)$report["id"],
                "content" => (string)($report["content"] ?? ""),
                "urgency" => (string)($report["urgency"] ?? ""),
                "urgencyLabel" => managerHomeUrgencyLabel($report["urgency"] ?? ""),
                "status" => (string)($report["status"] ?? ""),
                "city" => trim((string)($report["volunteer_city"] ?? "")) !== "" ? (string)$report["volunteer_city"] : "לא צוין",
                "daysOpen" => $daysOpen,
                "created_at" => (string)($report["created_at"] ?? ""),
                "responsible" => managerHomeResponsibleName($report),
                "isUrgent" => $isUrgent,
                "isNew" => managerHomeIsNewStatus($report["status"] ?? "")
            ];
        }
    }

    usort($attentionReports, function ($a, $b) {
        if ((int)$a["isUrgent"] !== (int)$b["isUrgent"]) {
            return (int)$b["isUrgent"] <=> (int)$a["isUrgent"];
        }

        if ((int)$a["daysOpen"] !== (int)$b["daysOpen"]) {
            return (int)$b["daysOpen"] <=> (int)$a["daysOpen"];
        }

        return strcmp((string)$a["created_at"], (string)$b["created_at"]);
    });

    $attentionReports = array_slice(array_map(function ($report) {
        unset($report["isUrgent"], $report["isNew"]);

        return $report;
    }, $attentionReports), 0, 10);

    managerHomeResponse([
        "success" => true,
        "totalReports" => count($reports),
        "openReports" => $openReports,
        "urgentReports" => $urgentReports,
        "elderlyInFollowup" => managerHomeElderlyInFollowup($pdo, $organizationId, $reports),
        "activeVolunteers" => managerHomeActiveVolunteers($pdo, $organizationId),
        "overdueOpenReports" => $overdueOpenReports,
        "statusBreakdown" => $statusBreakdown,
        "attentionReports" => $attentionReports
    ]);
} catch (Throwable $e) {
    error_log("MANAGER HOME API ERROR: " . $e->getMessage());

    managerHomeResponse([
        "success" => false,
        "message" => "לא הצלחנו לטעון את נתוני מסך הבית."
    ], 500);
}
