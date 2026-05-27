<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/auth.php';

function mpMyElderlyResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function mpMyElderlyBool($value) {
    if (is_bool($value)) {
        return $value;
    }

    $normalized = trim((string)$value);
    $lower = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);

    if (in_array($lower, ['1', 'true', 'yes', 'y', 'כן', 'פעיל'], true)) {
        return true;
    }

    return false;
}

function mpMyElderlyEmptyPayload() {
    return [
        "hasAssignedElderly" => false,
        "elderly" => null,
        "assignment" => null,
        "recentReports" => []
    ];
}

function mpMyElderlyVolunteerIdForUser($pdo, $userId) {
    if (!$userId || !mpDbTableExists($pdo, "volunteers")) {
        return null;
    }

    $columns = mpDbTableColumns($pdo, "volunteers");

    if (!mpColumnExists($columns, "user_id")) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id FROM volunteers WHERE user_id = :user_id LIMIT 1");
    $stmt->execute([
        ":user_id" => (int)$userId
    ]);

    $row = $stmt->fetch();

    return $row ? (int)$row["id"] : null;
}

function mpMyElderlyAssignmentStatusSql() {
    return "COALESCE(vea.status, 'active') IN ('active', 'פעיל', 'פעילה', 'בתוקף')";
}

function mpMyElderlyFetchAssignment($pdo, $volunteerId) {
    foreach (["volunteer_elderly_assignments", "elderly"] as $tableName) {
        if (!mpDbTableExists($pdo, $tableName)) {
            return null;
        }
    }

    $stmt = $pdo->prepare("
        SELECT
            vea.id AS assignment_id,
            vea.start_date AS assignment_start_date,
            vea.assignment_type,
            vea.status AS assignment_status,
            e.id,
            e.first_name,
            e.last_name,
            e.birth_year,
            e.marital_status,
            e.city,
            e.address,
            e.apartment,
            e.phone,
            e.family_contact,
            e.health_conditions,
            e.physical_limitations,
            e.wheelchair,
            e.dementia
        FROM volunteer_elderly_assignments vea
        INNER JOIN elderly e ON e.id = vea.elderly_id
        WHERE vea.volunteer_id = :volunteer_id
          AND " . mpMyElderlyAssignmentStatusSql() . "
        ORDER BY vea.start_date DESC, vea.created_at DESC, vea.id DESC
        LIMIT 1
    ");

    $stmt->execute([
        ":volunteer_id" => (int)$volunteerId
    ]);

    $row = $stmt->fetch();

    return $row ?: null;
}

function mpMyElderlyFetchRecentReports($pdo, $volunteerId, $elderlyId) {
    if (!mpDbTableExists($pdo, "reports")) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT id, volunteer_id, elderly_id, content, urgency, status, classification_source, created_at
        FROM reports
        WHERE volunteer_id = :volunteer_id
          AND elderly_id = :elderly_id
        ORDER BY created_at DESC, id DESC
        LIMIT 5
    ");

    $stmt->execute([
        ":volunteer_id" => (int)$volunteerId,
        ":elderly_id" => (int)$elderlyId
    ]);

    return array_map(function ($report) {
        return [
            "id" => (int)($report["id"] ?? 0),
            "volunteerId" => isset($report["volunteer_id"]) ? (int)$report["volunteer_id"] : null,
            "elderlyId" => isset($report["elderly_id"]) ? (int)$report["elderly_id"] : null,
            "content" => (string)($report["content"] ?? ""),
            "urgency" => (string)($report["urgency"] ?? ""),
            "status" => (string)($report["status"] ?? ""),
            "classificationSource" => $report["classification_source"] ?? null,
            "createdAt" => $report["created_at"] ?? null
        ];
    }, $stmt->fetchAll());
}

try {
    $currentUser = requireLogin($pdo);
    $includeReports = !isset($_GET["include_reports"]) || (string)$_GET["include_reports"] !== "0";

    if (!empty($currentUser["is_demo"])) {
        mpMyElderlyResponse([
            "success" => false,
            "error" => "unauthorized",
            "message" => "נדרשת התחברות למשתמש אמיתי במערכת."
        ], 401);
    }

    requireRole("volunteer", $currentUser);

    $userId = (int)($currentUser["user_id"] ?? ($currentUser["id"] ?? 0));
    $volunteerId = mpMyElderlyVolunteerIdForUser($pdo, $userId);

    if (!$volunteerId) {
        mpMyElderlyResponse(mpMyElderlyEmptyPayload());
    }

    $row = mpMyElderlyFetchAssignment($pdo, $volunteerId);

    if (!$row) {
        mpMyElderlyResponse(mpMyElderlyEmptyPayload());
    }

    $elderlyId = (int)$row["id"];

    mpMyElderlyResponse([
        "hasAssignedElderly" => true,
        "elderly" => [
            "id" => $elderlyId,
            "firstName" => (string)($row["first_name"] ?? ""),
            "lastName" => (string)($row["last_name"] ?? ""),
            "birthYear" => isset($row["birth_year"]) ? (int)$row["birth_year"] : null,
            "maritalStatus" => (string)($row["marital_status"] ?? ""),
            "city" => (string)($row["city"] ?? ""),
            "address" => (string)($row["address"] ?? ""),
            "apartment" => (string)($row["apartment"] ?? ""),
            "phone" => (string)($row["phone"] ?? ""),
            "familyContact" => (string)($row["family_contact"] ?? ""),
            "healthConditions" => (string)($row["health_conditions"] ?? ""),
            "physicalLimitations" => (string)($row["physical_limitations"] ?? ""),
            "wheelchair" => mpMyElderlyBool($row["wheelchair"] ?? false),
            "dementia" => mpMyElderlyBool($row["dementia"] ?? false)
        ],
        "assignment" => [
            "id" => (int)($row["assignment_id"] ?? 0),
            "startDate" => $row["assignment_start_date"] ?? null,
            "assignmentType" => (string)($row["assignment_type"] ?? ""),
            "status" => (string)($row["assignment_status"] ?? "active")
        ],
        "recentReports" => $includeReports ? mpMyElderlyFetchRecentReports($pdo, $volunteerId, $elderlyId) : []
    ]);
} catch (Throwable $e) {
    error_log("MY ELDERLY API ERROR: " . $e->getMessage());

    mpMyElderlyResponse([
        "success" => false,
        "error" => "server_error",
        "message" => "לא הצלחנו לטעון את פרטי הקשיש כרגע."
    ], 500);
}
