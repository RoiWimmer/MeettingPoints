<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/auth.php';

function mpPeopleResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function mpPeopleReadJson() {
    $decoded = json_decode(file_get_contents("php://input"), true);

    return is_array($decoded) ? $decoded : [];
}

function mpPeopleFullName($row) {
    $fullName = trim((string)($row["full_name"] ?? ""));

    if ($fullName !== "") {
        return $fullName;
    }

    $name = trim((string)($row["name"] ?? ""));

    if ($name !== "") {
        return $name;
    }

    return trim((string)($row["first_name"] ?? "") . " " . (string)($row["last_name"] ?? ""));
}

function mpPeopleActiveStatusSql($alias = "") {
    $prefix = $alias !== "" ? $alias . "." : "";

    return "COALESCE(" . $prefix . "status, 'active') IN ('active', 'פעיל', 'פעילה', 'בתוקף')";
}

function mpPeopleAssertTables($pdo) {
    foreach (["users", "volunteers", "elderly", "volunteer_elderly_assignments", "reports"] as $tableName) {
        if (!mpDbTableExists($pdo, $tableName)) {
            mpPeopleResponse([
                "success" => false,
                "message" => "חסרה טבלת נתונים נדרשת: " . $tableName
            ], 500);
        }
    }
}

function mpPeopleEnsureManager($pdo) {
    $user = requireLogin($pdo);
    requireRole("ngo_manager", $user);

    $organizationId = (int)($user["organization_id"] ?? 0);

    if (!$organizationId) {
        mpPeopleResponse([
            "success" => false,
            "message" => "המשתמש המחובר אינו משויך לעמותה."
        ], 403);
    }

    return $user;
}

function mpPeopleFetchVolunteers($pdo, $organizationId) {
    $stmt = $pdo->prepare("
        SELECT
            v.id,
            v.user_id,
            v.bio,
            v.city,
            v.visit_type,
            v.total_visits,
            v.rating,
            v.start_date,
            v.active,
            v.created_at,
            u.email,
            u.first_name,
            u.last_name,
            u.phone,
            u.active AS user_active,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS full_name,
            vea.id AS active_assignment_id,
            e.id AS assigned_elderly_id,
            CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, '')) AS assigned_elderly_name
        FROM volunteers v
        INNER JOIN users u ON u.id = v.user_id
        LEFT JOIN volunteer_elderly_assignments vea
            ON vea.volunteer_id = v.id
           AND " . mpPeopleActiveStatusSql("vea") . "
        LEFT JOIN elderly e ON e.id = vea.elderly_id
        WHERE u.organization_id = :organization_id
        ORDER BY u.first_name ASC, u.last_name ASC, v.id ASC
    ");

    $stmt->execute([":organization_id" => (int)$organizationId]);
    $rows = $stmt->fetchAll();

    return array_map(function ($row) {
        $row["full_name"] = mpPeopleFullName($row);
        $row["assigned_elderly_name"] = trim((string)($row["assigned_elderly_name"] ?? ""));

        return $row;
    }, $rows);
}

function mpPeopleFetchElderly($pdo, $organizationId) {
    $sql = "
        SELECT
            e.id,
            e.first_name,
            e.last_name,
            e.id_number,
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
            e.dementia,
            e.created_at,
            active_assignment.id AS active_assignment_id,
            active_assignment.volunteer_id AS assigned_volunteer_id,
            CONCAT(COALESCE(active_assignment.first_name, ''), ' ', COALESCE(active_assignment.last_name, '')) AS assigned_volunteer_name
        FROM elderly e
        LEFT JOIN (
            SELECT
                vea.id,
                vea.elderly_id,
                v.id AS volunteer_id,
                u.first_name,
                u.last_name
            FROM volunteer_elderly_assignments vea
            INNER JOIN volunteers v ON v.id = vea.volunteer_id
            INNER JOIN users u ON u.id = v.user_id
            WHERE u.organization_id = :active_assignment_organization_id
              AND " . mpPeopleActiveStatusSql("vea") . "
        ) active_assignment ON active_assignment.elderly_id = e.id
        WHERE e.id IN (
            SELECT DISTINCT vea.elderly_id
            FROM volunteer_elderly_assignments vea
            INNER JOIN volunteers v ON v.id = vea.volunteer_id
            INNER JOIN users u ON u.id = v.user_id
            WHERE u.organization_id = :assignment_organization_id
              AND vea.elderly_id IS NOT NULL
            UNION
            SELECT DISTINCT r.elderly_id
            FROM reports r
            INNER JOIN volunteers rv ON rv.id = r.volunteer_id
            INNER JOIN users ru ON ru.id = rv.user_id
            WHERE ru.organization_id = :report_organization_id
              AND r.elderly_id IS NOT NULL
        )
        ORDER BY e.first_name ASC, e.last_name ASC, e.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":assignment_organization_id" => (int)$organizationId,
        ":report_organization_id" => (int)$organizationId,
        ":active_assignment_organization_id" => (int)$organizationId
    ]);
    $rows = $stmt->fetchAll();

    return array_map(function ($row) {
        $row["full_name"] = mpPeopleFullName($row);
        $row["assigned_volunteer_name"] = trim((string)($row["assigned_volunteer_name"] ?? ""));

        return $row;
    }, $rows);
}

function mpPeopleFetchAssignments($pdo, $organizationId) {
    $stmt = $pdo->prepare("
        SELECT
            vea.id,
            vea.volunteer_id,
            vea.elderly_id,
            vea.start_date,
            vea.assignment_type,
            vea.status,
            vea.created_at,
            CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS volunteer_name,
            u.email AS volunteer_email,
            CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, '')) AS elderly_name,
            e.city AS elderly_city
        FROM volunteer_elderly_assignments vea
        INNER JOIN volunteers v ON v.id = vea.volunteer_id
        INNER JOIN users u ON u.id = v.user_id
        INNER JOIN elderly e ON e.id = vea.elderly_id
        WHERE u.organization_id = :organization_id
          AND " . mpPeopleActiveStatusSql("vea") . "
        ORDER BY vea.created_at DESC, vea.id DESC
    ");

    $stmt->execute([":organization_id" => (int)$organizationId]);

    return $stmt->fetchAll();
}

function mpPeopleBuildStats($volunteers, $elderly, $assignments) {
    $activeVolunteers = 0;
    $unassignedVolunteers = 0;
    $unassignedElderly = 0;

    foreach ($volunteers as $volunteer) {
        $isVolunteerActive = !isset($volunteer["active"]) || $volunteer["active"] === null || (string)$volunteer["active"] === "1";
        $isUserActive = !isset($volunteer["user_active"]) || $volunteer["user_active"] === null || (string)$volunteer["user_active"] === "1";

        if ($isVolunteerActive && $isUserActive) {
            $activeVolunteers++;
        }

        if (empty($volunteer["active_assignment_id"])) {
            $unassignedVolunteers++;
        }
    }

    foreach ($elderly as $person) {
        if (empty($person["active_assignment_id"])) {
            $unassignedElderly++;
        }
    }

    return [
        "activeVolunteers" => $activeVolunteers,
        "elderlyInFollowup" => count($elderly),
        "activeAssignments" => count($assignments),
        "unassignedVolunteers" => $unassignedVolunteers,
        "unassignedElderly" => $unassignedElderly
    ];
}

function mpPeopleFetchData($pdo, $user) {
    $organizationId = (int)($user["organization_id"] ?? 0);
    $volunteers = mpPeopleFetchVolunteers($pdo, $organizationId);
    $elderly = mpPeopleFetchElderly($pdo, $organizationId);
    $assignments = mpPeopleFetchAssignments($pdo, $organizationId);

    return [
        "success" => true,
        "current_user" => mpAuthPublicUser($user),
        "volunteers" => $volunteers,
        "elderly" => $elderly,
        "assignments" => $assignments,
        "stats" => mpPeopleBuildStats($volunteers, $elderly, $assignments)
    ];
}

function mpPeopleVolunteerBelongsToOrg($pdo, $volunteerId, $organizationId) {
    $stmt = $pdo->prepare("
        SELECT v.id
        FROM volunteers v
        INNER JOIN users u ON u.id = v.user_id
        WHERE v.id = :volunteer_id
          AND u.organization_id = :organization_id
        LIMIT 1
    ");
    $stmt->execute([
        ":volunteer_id" => (int)$volunteerId,
        ":organization_id" => (int)$organizationId
    ]);

    return (bool)$stmt->fetchColumn();
}

function mpPeopleVolunteerExists($pdo, $volunteerId) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM volunteers
        WHERE id = :volunteer_id
        LIMIT 1
    ");
    $stmt->execute([":volunteer_id" => (int)$volunteerId]);

    return (bool)$stmt->fetchColumn();
}

function mpPeopleElderlyExists($pdo, $elderlyId) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM elderly
        WHERE id = :elderly_id
        LIMIT 1
    ");
    $stmt->execute([":elderly_id" => (int)$elderlyId]);

    return (bool)$stmt->fetchColumn();
}

function mpPeopleElderlyBelongsToOrg($pdo, $elderlyId, $organizationId) {
    $stmt = $pdo->prepare("
        SELECT source.elderly_id
        FROM (
            SELECT vea.elderly_id
            FROM volunteer_elderly_assignments vea
            INNER JOIN volunteers v ON v.id = vea.volunteer_id
            INNER JOIN users u ON u.id = v.user_id
            WHERE vea.elderly_id = :assignment_elderly_id
              AND u.organization_id = :assignment_organization_id
            UNION
            SELECT r.elderly_id
            FROM reports r
            INNER JOIN volunteers rv ON rv.id = r.volunteer_id
            INNER JOIN users ru ON ru.id = rv.user_id
            WHERE r.elderly_id = :report_elderly_id
              AND ru.organization_id = :report_organization_id
        ) source
        LIMIT 1
    ");
    $stmt->execute([
        ":assignment_elderly_id" => (int)$elderlyId,
        ":assignment_organization_id" => (int)$organizationId,
        ":report_elderly_id" => (int)$elderlyId,
        ":report_organization_id" => (int)$organizationId
    ]);

    return (bool)$stmt->fetchColumn();
}

function mpPeopleActiveAssignmentByColumn($pdo, $column, $id) {
    if (!in_array($column, ["volunteer_id", "elderly_id"], true)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM volunteer_elderly_assignments
        WHERE " . mpQuoteIdentifier($column) . " = :id
          AND " . mpPeopleActiveStatusSql() . "
        LIMIT 1
    ");
    $stmt->execute([":id" => (int)$id]);
    $row = $stmt->fetch();

    return $row ? (int)$row["id"] : null;
}

function mpPeopleAssign($pdo, $user) {
    $input = mpPeopleReadJson();
    $volunteerId = (int)($input["volunteer_id"] ?? 0);
    $elderlyId = (int)($input["elderly_id"] ?? 0);
    $organizationId = (int)($user["organization_id"] ?? 0);
    $payloadLog = json_encode($input, JSON_UNESCAPED_UNICODE);

    error_log("MANAGER PEOPLE assign payload: " . $payloadLog);
    error_log("MANAGER PEOPLE volunteer_id=" . $volunteerId . ", elderly_id=" . $elderlyId);

    if (!$volunteerId || !$elderlyId) {
        mpPeopleResponse([
            "success" => false,
            "message" => "חסרים מזהי מתנדב וקשיש לשיוך."
        ], 400);
    }

    if (!mpPeopleVolunteerExists($pdo, $volunteerId)) {
        mpPeopleResponse([
            "success" => false,
            "message" => "המתנדב שנבחר לא נמצא."
        ], 404);
    }

    if (!mpPeopleElderlyExists($pdo, $elderlyId)) {
        mpPeopleResponse([
            "success" => false,
            "message" => "הקשיש שנבחר לא נמצא."
        ], 404);
    }

    if (!mpPeopleVolunteerBelongsToOrg($pdo, $volunteerId, $organizationId)) {
        mpPeopleResponse([
            "success" => false,
            "message" => "המתנדב לא שייך לעמותה שלך."
        ], 403);
    }

    if (!mpPeopleElderlyBelongsToOrg($pdo, $elderlyId, $organizationId)) {
        mpPeopleResponse([
            "success" => false,
            "message" => "לא ניתן לשייך קשיש שאינו מזוהה עם העמותה שלך."
        ], 403);
    }

    $pdo->beginTransaction();

    $existingVolunteerAssignment = mpPeopleActiveAssignmentByColumn($pdo, "volunteer_id", $volunteerId);
    $existingElderlyAssignment = mpPeopleActiveAssignmentByColumn($pdo, "elderly_id", $elderlyId);

    if ($existingVolunteerAssignment || $existingElderlyAssignment) {
        $pdo->rollBack();
        $message = $existingVolunteerAssignment
            ? "המתנדב כבר משויך בשיוך פעיל."
            : "הקשיש כבר משויך בשיוך פעיל.";

        mpPeopleResponse([
            "success" => false,
            "message" => $message
        ], 409);
    }

    $columns = mpDbTableColumns($pdo, "volunteer_elderly_assignments");
    $insertColumns = ["volunteer_id", "elderly_id"];
    $placeholders = [":volunteer_id", ":elderly_id"];
    $params = [
        ":volunteer_id" => $volunteerId,
        ":elderly_id" => $elderlyId
    ];

    if (mpColumnExists($columns, "status")) {
        $insertColumns[] = "status";
        $placeholders[] = ":status";
        $params[":status"] = "active";
    }

    if (mpColumnExists($columns, "start_date")) {
        $insertColumns[] = "start_date";
        $placeholders[] = "CURDATE()";
    }

    if (mpColumnExists($columns, "assignment_type")) {
        $insertColumns[] = "assignment_type";
        $placeholders[] = ":assignment_type";
        $params[":assignment_type"] = "עיקרי";
    }

    if (mpColumnExists($columns, "created_at")) {
        $insertColumns[] = "created_at";
        $placeholders[] = "NOW()";
    }

    $stmt = $pdo->prepare("
        INSERT INTO volunteer_elderly_assignments
        (" . implode(", ", array_map("mpQuoteIdentifier", $insertColumns)) . ")
        VALUES
        (" . implode(", ", $placeholders) . ")
    ");

    try {
        $stmt->execute($params);
        $assignmentId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("MANAGER PEOPLE ASSIGN INSERT ERROR: " . $e->getMessage());

        mpPeopleResponse([
            "success" => false,
            "message" => "שמירת השיוך נכשלה: " . $e->getMessage()
        ], 500);
    }

    $pdo->commit();

    mpPeopleResponse([
        "success" => true,
        "message" => "השיוך נשמר בהצלחה.",
        "assignment_id" => $assignmentId
    ]);
}

function mpPeopleUnassign($pdo, $user) {
    $input = mpPeopleReadJson();
    $assignmentId = (int)($input["assignment_id"] ?? 0);
    $organizationId = (int)($user["organization_id"] ?? 0);

    if (!$assignmentId) {
        mpPeopleResponse([
            "success" => false,
            "message" => "חסר מזהה שיוך לביטול."
        ], 400);
    }

    $columns = mpDbTableColumns($pdo, "volunteer_elderly_assignments");

    if (!mpColumnExists($columns, "status")) {
        mpPeopleResponse([
            "success" => false,
            "message" => "לא ניתן לבטל שיוך ללא עמודת סטטוס בטבלת השיוכים."
        ], 500);
    }

    $stmt = $pdo->prepare("
        SELECT vea.id
        FROM volunteer_elderly_assignments vea
        INNER JOIN volunteers v ON v.id = vea.volunteer_id
        INNER JOIN users u ON u.id = v.user_id
        WHERE vea.id = :assignment_id
          AND u.organization_id = :organization_id
          AND " . mpPeopleActiveStatusSql("vea") . "
        LIMIT 1
    ");
    $stmt->execute([
        ":assignment_id" => $assignmentId,
        ":organization_id" => $organizationId
    ]);

    if (!$stmt->fetchColumn()) {
        mpPeopleResponse([
            "success" => false,
            "message" => "לא נמצא שיוך פעיל בעמותה שלך לביטול."
        ], 404);
    }

    $update = $pdo->prepare("
        UPDATE volunteer_elderly_assignments
        SET status = :status
        WHERE id = :assignment_id
        LIMIT 1
    ");
    $update->execute([
        ":status" => "inactive",
        ":assignment_id" => $assignmentId
    ]);

    mpPeopleResponse([
        "success" => true,
        "message" => "השיוך בוטל בהצלחה."
    ]);
}

try {
    $user = mpPeopleEnsureManager($pdo);
    mpPeopleAssertTables($pdo);

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        mpPeopleResponse(mpPeopleFetchData($pdo, $user));
    }

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        mpPeopleResponse([
            "success" => false,
            "message" => "Method not allowed"
        ], 405);
    }

    $action = (string)($_GET["action"] ?? "");

    if ($action === "assign") {
        mpPeopleAssign($pdo, $user);
    }

    if ($action === "unassign") {
        mpPeopleUnassign($pdo, $user);
    }

    mpPeopleResponse([
        "success" => false,
        "message" => "פעולה לא מוכרת."
    ], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("MANAGER PEOPLE API ERROR: " . $e->getMessage());

    mpPeopleResponse([
        "success" => false,
        "message" => "לא הצלחנו לבצע את הפעולה כרגע: " . $e->getMessage()
    ], 500);
}
