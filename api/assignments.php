<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/auth.php';

function mpAssignmentsResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function mpAssignmentsTableRows($pdo, $tableName, $user) {
    if (!mpDbTableExists($pdo, $tableName)) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT * FROM " . mpQuoteIdentifier($tableName) . " ORDER BY id ASC");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (mpAuthIsManager($user)) {
        return array_values(array_filter($rows, function ($row) use ($user) {
            $rowOrgId = mpAuthOrganizationIdFromRow($row);
            return $rowOrgId && (int)$rowOrgId === (int)($user["organization_id"] ?? 0);
        }));
    }

    return $rows;
}

function mpAssignmentsForManager($pdo, $user) {
    $volunteers = mpAssignmentsTableRows($pdo, "volunteers", $user);
    $elderly = mpAssignmentsTableRows($pdo, "elderly", $user);
    $assignments = mpAssignmentsTableRows($pdo, "volunteer_elderly_assignments", $user);

    return [
        "success" => true,
        "current_user" => mpAuthPublicUser($user),
        "volunteers" => $volunteers,
        "elderly" => $elderly,
        "assignments" => $assignments
    ];
}

function mpAssignmentsForVolunteer($pdo, $user) {
    $volunteerId = (int)($user["volunteer_id"] ?? 0);

    if (!$volunteerId || !mpDbTableExists($pdo, "elderly")) {
        return [
            "success" => true,
            "current_user" => mpAuthPublicUser($user),
            "elderly" => [],
            "assignments" => []
        ];
    }

    $elderly = [];
    $assignments = [];

    if (mpDbTableExists($pdo, "volunteer_elderly_assignments")) {
        $assignmentColumns = mpDbTableColumns($pdo, "volunteer_elderly_assignments");
        $assignmentOrgCondition = "";
        $plainAssignmentOrgCondition = "";
        $assignmentParams = [":volunteer_id" => $volunteerId];

        if (!empty($user["organization_id"]) && mpColumnExists($assignmentColumns, "organization_id")) {
            $assignmentOrgCondition = " AND (vea.organization_id IS NULL OR vea.organization_id = :organization_id)";
            $plainAssignmentOrgCondition = " AND (organization_id IS NULL OR organization_id = :organization_id)";
            $assignmentParams[":organization_id"] = (int)$user["organization_id"];
        }

        $stmt = $pdo->prepare("
            SELECT e.*, vea.id AS assignment_id, vea.created_at AS assignment_created_at
            FROM volunteer_elderly_assignments vea
            JOIN elderly e ON e.id = vea.elderly_id
            WHERE vea.volunteer_id = :volunteer_id
            " . $assignmentOrgCondition . "
            ORDER BY e.id ASC
            LIMIT 1
        ");
        $stmt->execute($assignmentParams);
        $elderly = $stmt->fetchAll();

        $assignStmt = $pdo->prepare("
            SELECT *
            FROM volunteer_elderly_assignments
            WHERE volunteer_id = :volunteer_id
            " . $plainAssignmentOrgCondition . "
            ORDER BY id ASC
            LIMIT 1
        ");
        $assignStmt->execute($assignmentParams);
        $assignments = $assignStmt->fetchAll();
    } else {
        $columns = mpDbTableColumns($pdo, "elderly");
        $assignmentColumns = array_values(array_filter([
            mpColumnExists($columns, "volunteer_id") ? "volunteer_id" : null,
            mpColumnExists($columns, "assigned_volunteer_id") ? "assigned_volunteer_id" : null,
            mpColumnExists($columns, "primary_volunteer_id") ? "primary_volunteer_id" : null
        ]));

        if ($assignmentColumns) {
            $where = implode(" OR ", array_map(function ($column) {
                return mpQuoteIdentifier($column) . " = :volunteer_id";
            }, $assignmentColumns));
            $stmt = $pdo->prepare("SELECT * FROM elderly WHERE " . $where . " ORDER BY id ASC");
            $stmt->execute([":volunteer_id" => $volunteerId]);
            $elderly = $stmt->fetchAll();
        }
    }

    return [
        "success" => true,
        "current_user" => mpAuthPublicUser($user),
        "elderly" => $elderly,
        "assignments" => $assignments
    ];
}

function mpAssignmentsReadJson() {
    $decoded = json_decode(file_get_contents("php://input"), true);

    return is_array($decoded) ? $decoded : [];
}

try {
    $user = requireLogin($pdo);

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        if (mpAuthIsManager($user)) {
            mpAssignmentsResponse(mpAssignmentsForManager($pdo, $user));
        }

        mpAssignmentsResponse(mpAssignmentsForVolunteer($pdo, $user));
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        mpAssignmentsResponse([
            "success" => false,
            "message" => "Method not allowed"
        ], 405);
    }

    requireRole("ngo_manager", $user);

    if (!mpDbTableExists($pdo, "volunteer_elderly_assignments")) {
        mpAssignmentsResponse([
            "success" => false,
            "message" => "טבלת שיוכי מתנדבים אינה קיימת. יש להריץ את migration ההרשאות."
        ], 500);
    }

    $input = mpAssignmentsReadJson();
    $volunteerId = (int)($input["volunteer_id"] ?? 0);
    $elderlyId = (int)($input["elderly_id"] ?? 0);

    if (!$volunteerId || !$elderlyId) {
        mpAssignmentsResponse([
            "success" => false,
            "message" => "חסרים מזהי מתנדב וקשיש לשיוך."
        ], 400);
    }

    $volunteerOrgId = mpAuthResourceOrganizationId($pdo, "volunteers", $volunteerId);
    $elderlyOrgId = mpAuthResourceOrganizationId($pdo, "elderly", $elderlyId);
    $userOrgId = (int)($user["organization_id"] ?? 0);

    if (!$userOrgId || !$volunteerOrgId || !$elderlyOrgId || $volunteerOrgId !== $userOrgId || $elderlyOrgId !== $userOrgId) {
        mpAssignmentsResponse([
            "success" => false,
            "message" => "לא ניתן לשייך מתנדב או קשיש מחוץ לעמותה שלך."
        ], 403);
    }

    $columns = mpDbTableColumns($pdo, "volunteer_elderly_assignments");
    $insertColumns = ["volunteer_id", "elderly_id"];
    $placeholders = [":volunteer_id", ":elderly_id"];
    $params = [
        ":volunteer_id" => $volunteerId,
        ":elderly_id" => $elderlyId
    ];

    if (mpColumnExists($columns, "organization_id")) {
        $insertColumns[] = "organization_id";
        $placeholders[] = ":organization_id";
        $params[":organization_id"] = $userOrgId ?: null;
    }

    if (mpColumnExists($columns, "created_at")) {
        $insertColumns[] = "created_at";
        $placeholders[] = "NOW()";
    }

    $pdo->beginTransaction();

    $deleteWhere = "volunteer_id = :volunteer_id OR elderly_id = :elderly_id";
    $deleteParams = [
        ":volunteer_id" => $volunteerId,
        ":elderly_id" => $elderlyId
    ];

    if (mpColumnExists($columns, "organization_id")) {
        $deleteWhere = "(" . $deleteWhere . ") AND (organization_id IS NULL OR organization_id = :organization_id)";
        $deleteParams[":organization_id"] = $userOrgId;
    }

    $deleteStmt = $pdo->prepare("
        DELETE FROM volunteer_elderly_assignments
        WHERE " . $deleteWhere
    );
    $deleteStmt->execute($deleteParams);

    $sql = "
        INSERT INTO volunteer_elderly_assignments
        (" . implode(", ", array_map("mpQuoteIdentifier", $insertColumns)) . ")
        VALUES
        (" . implode(", ", $placeholders) . ")
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pdo->commit();

    mpAssignmentsResponse([
        "success" => true,
        "message" => "השיבוץ נשמר בהצלחה. לכל מתנדב משויך קשיש אחד בלבד."
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("ASSIGNMENTS API ERROR: " . $e->getMessage());
    mpAssignmentsResponse([
        "success" => false,
        "message" => "לא הצלחנו לבצע את פעולת השיוך כרגע."
    ], 500);
}
