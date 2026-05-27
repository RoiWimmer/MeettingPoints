<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/report_helpers.php';

function mpAuthEnvValue($key, $default = null) {
    if (function_exists('dbEnvValue')) {
        return dbEnvValue($key, $default);
    }

    $envPath = __DIR__ . '/../.env';

    if (!file_exists($envPath)) {
        return $default;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $pos = strpos($line, '=');

        if ($pos === false) {
            continue;
        }

        $currentKey = trim(substr($line, 0, $pos));
        $currentValue = trim(substr($line, $pos + 1));
        $currentValue = trim($currentValue, "\"'");

        if ($currentKey === $key) {
            return $currentValue;
        }
    }

    return $default;
}

function mpAuthJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function mpAuthFirstValue($row, $keys, $default = null) {
    if (!is_array($row)) {
        return $default;
    }

    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = $row[$key];

        if ($value !== null && trim((string)$value) !== '') {
            return $value;
        }
    }

    return $default;
}

function mpAuthFirstInt($row, $keys, $default = null) {
    $value = mpAuthFirstValue($row, $keys, null);

    if ($value === null || trim((string)$value) === '') {
        return $default;
    }

    return (int)$value;
}

function mpAuthLower($value) {
    if (function_exists('mb_strtolower')) {
        return mb_strtolower((string)$value, 'UTF-8');
    }

    return strtolower((string)$value);
}

function mpAuthNormalizeRole($role, $fallback = 'volunteer') {
    $role = trim((string)$role);
    $roleLower = mpAuthLower($role);

    $managerRoles = [
        'ngo_manager',
        'ngo manager',
        'manager',
        'org_manager',
        'org manager',
        'organization_manager',
        'organization manager',
        'association_manager',
        'association manager',
        'admin',
        'administrator',
        'רכז',
        'רכזת',
        'מנהל',
        'מנהלת',
        'מנהל עמותה',
        'מנהלת עמותה'
    ];

    $volunteerRoles = [
        'volunteer',
        'מתנדב',
        'מתנדבת',
        'field_volunteer'
    ];

    if (in_array($roleLower, $managerRoles, true)) {
        return 'ngo_manager';
    }

    if (in_array($roleLower, $volunteerRoles, true)) {
        return 'volunteer';
    }

    return $fallback;
}

function mpAuthRoleDisplay($role) {
    return mpAuthNormalizeRole($role) === 'ngo_manager' ? 'מנהל עמותה' : 'מתנדב';
}

function mpAuthUserPermissions($role) {
    $role = mpAuthNormalizeRole($role);
    $isManager = $role === 'ngo_manager';

    return [
        "can_create_report" => true,
        "can_view_reports" => true,
        "can_manage_status" => $isManager,
        "can_view_ai" => $isManager,
        "can_view_dashboard" => $isManager,
        "can_manage_assignments" => $isManager,
        "can_view_org_people" => $isManager,
        "can_edit_profile" => true
    ];
}

function mpAuthPublicUser($user) {
    if (!$user) {
        return null;
    }

    $normalizedRole = mpAuthNormalizeRole($user["role"] ?? 'volunteer');
    $publicRole = $normalizedRole === 'ngo_manager' ? 'manager' : 'volunteer';

    return [
        "id" => $user["id"] ?? null,
        "user_id" => $user["user_id"] ?? ($user["id"] ?? null),
        "volunteer_id" => $user["volunteer_id"] ?? null,
        "organization_id" => $user["organization_id"] ?? null,
        "role" => $publicRole,
        "role_key" => $normalizedRole,
        "role_display" => mpAuthRoleDisplay($normalizedRole),
        "full_name" => $user["full_name"] ?? '',
        "email" => $user["email"] ?? '',
        "phone" => $user["phone"] ?? '',
        "area" => $user["area"] ?? '',
        "is_demo" => !empty($user["is_demo"]),
        "permissions" => mpAuthUserPermissions($normalizedRole)
    ];
}

function mpAuthFetchUserById($pdo, $userId) {
    if (!$userId || !mpDbTableExists($pdo, "users")) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => (int)$userId]);
        $user = $stmt->fetch();

        return $user ?: null;
    } catch (Throwable $e) {
        error_log("AUTH USER LOOKUP ERROR: " . $e->getMessage());
        return null;
    }
}

function mpAuthFetchUserByEmail($pdo, $email) {
    if (!$email || !mpDbTableExists($pdo, "users")) {
        return null;
    }

    $columns = mpDbTableColumns($pdo, "users");

    if (!mpColumnExists($columns, "email")) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([":email" => $email]);
        $user = $stmt->fetch();

        return $user ?: null;
    } catch (Throwable $e) {
        error_log("AUTH EMAIL LOOKUP ERROR: " . $e->getMessage());
        return null;
    }
}

function mpAuthFetchVolunteerForUser($pdo, $userId) {
    if (!$userId || !mpDbTableExists($pdo, "volunteers")) {
        return null;
    }

    $columns = mpDbTableColumns($pdo, "volunteers");
    $whereParts = [];
    $params = [];

    foreach (["user_id", "account_id"] as $column) {
        if (mpColumnExists($columns, $column)) {
            $placeholder = ":" . $column;
            $whereParts[] = mpQuoteIdentifier($column) . " = " . $placeholder;
            $params[$placeholder] = (int)$userId;
        }
    }

    if (!$whereParts && mpColumnExists($columns, "id")) {
        $whereParts[] = "id = :volunteer_id";
        $params[":volunteer_id"] = (int)$userId;
    }

    if (!$whereParts) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM volunteers WHERE " . implode(" OR ", $whereParts) . " LIMIT 1");
        $stmt->execute($params);
        $volunteer = $stmt->fetch();

        return $volunteer ?: null;
    } catch (Throwable $e) {
        error_log("AUTH VOLUNTEER LOOKUP ERROR: " . $e->getMessage());
        return null;
    }
}

function mpAuthFetchVolunteerById($pdo, $volunteerId) {
    if (!$volunteerId || !mpDbTableExists($pdo, "volunteers")) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM volunteers WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => (int)$volunteerId]);
        $volunteer = $stmt->fetch();

        return $volunteer ?: null;
    } catch (Throwable $e) {
        error_log("AUTH VOLUNTEER BY ID ERROR: " . $e->getMessage());
        return null;
    }
}

function mpAuthOrganizationIdFromRow($row) {
    return mpAuthFirstInt($row, [
        "organization_id",
        "org_id",
        "ngo_id",
        "association_id",
        "assigned_org_id",
        "assigned_organization_id",
        "routed_to_org_id"
    ]);
}

function mpAuthNameFromRow($row) {
    $fullName = mpAuthFirstValue($row, ["full_name", "name", "display_name"], "");

    if ($fullName !== "") {
        return (string)$fullName;
    }

    return trim((string)($row["first_name"] ?? "") . " " . (string)($row["last_name"] ?? ""));
}

function mpAuthCurrentUserFromSession($pdo) {
    $sessionUser = isset($_SESSION["user"]) && is_array($_SESSION["user"]) ? $_SESSION["user"] : [];
    $sessionFlat = [
        "id" => $_SESSION["user_id"] ?? null,
        "user_id" => $_SESSION["user_id"] ?? null,
        "volunteer_id" => $_SESSION["volunteer_id"] ?? null,
        "organization_id" => $_SESSION["organization_id"] ?? ($_SESSION["org_id"] ?? null),
        "role" => $_SESSION["role"] ?? null,
        "email" => $_SESSION["email"] ?? null,
        "full_name" => $_SESSION["full_name"] ?? null,
        "phone" => $_SESSION["phone"] ?? null,
        "area" => $_SESSION["area"] ?? null
    ];

    $sessionData = array_merge($sessionFlat, $sessionUser);
    $userId = mpAuthFirstInt($sessionData, ["id", "user_id"]);
    $dbUser = $userId ? mpAuthFetchUserById($pdo, $userId) : null;

    if (!$dbUser && !empty($sessionData["email"])) {
        $dbUser = mpAuthFetchUserByEmail($pdo, trim((string)$sessionData["email"]));
    }

    $merged = array_merge($dbUser ?: [], array_filter($sessionData, function ($value) {
        return $value !== null && $value !== '';
    }));

    if (!$merged && empty($sessionData["volunteer_id"])) {
        return null;
    }

    $roleFallback = !empty($merged["volunteer_id"]) ? 'volunteer' : 'ngo_manager';
    $role = mpAuthNormalizeRole(mpAuthFirstValue($merged, ["role", "account_type", "user_type"], $roleFallback), $roleFallback);
    $volunteerId = mpAuthFirstInt($merged, ["volunteer_id"]);
    $volunteer = null;

    if (!$volunteerId && $userId) {
        $volunteer = mpAuthFetchVolunteerForUser($pdo, $userId);
        $volunteerId = mpAuthFirstInt($volunteer, ["id"]);
    } elseif ($volunteerId) {
        $volunteer = mpAuthFetchVolunteerById($pdo, $volunteerId);
    }

    $organizationId = mpAuthOrganizationIdFromRow($merged);

    if (!$organizationId && $volunteer) {
        $organizationId = mpAuthOrganizationIdFromRow($volunteer);
    }

    return [
        "id" => $userId ?: mpAuthFirstInt($merged, ["id", "user_id"]),
        "user_id" => $userId ?: mpAuthFirstInt($merged, ["id", "user_id"]),
        "volunteer_id" => $volunteerId,
        "organization_id" => $organizationId,
        "role" => $role,
        "full_name" => mpAuthNameFromRow($merged) ?: mpAuthNameFromRow($volunteer) ?: ($role === 'ngo_manager' ? 'מנהל עמותה' : 'מתנדב'),
        "email" => (string)mpAuthFirstValue($merged, ["email"], ""),
        "phone" => (string)mpAuthFirstValue($merged, ["phone", "phone_number", "mobile"], mpAuthFirstValue($volunteer, ["phone", "phone_number", "mobile"], "")),
        "area" => (string)mpAuthFirstValue($merged, ["area", "service_area", "city"], mpAuthFirstValue($volunteer, ["area", "service_area", "city"], "")),
        "raw_user" => $dbUser ?: $merged,
        "raw_volunteer" => $volunteer,
        "is_demo" => false
    ];
}

function mpAuthDemoUser($pdo) {
    $demoEnabled = mpAuthEnvValue("MP_DEMO_AUTH_ENABLED", "0");

    if (in_array(mpAuthLower((string)$demoEnabled), ["0", "false", "no"], true)) {
        return null;
    }

    $role = mpAuthNormalizeRole(mpAuthEnvValue("MP_DEMO_USER_ROLE", "ngo_manager"), "ngo_manager");
    $organizationId = (int)mpAuthEnvValue("MP_DEMO_ORGANIZATION_ID", 1);
    $volunteerId = (int)mpAuthEnvValue("MP_DEMO_VOLUNTEER_ID", 1);

    return [
        "id" => 1,
        "user_id" => 1,
        "volunteer_id" => $volunteerId,
        "organization_id" => $organizationId ?: 1,
        "role" => $role,
        "full_name" => $role === 'ngo_manager' ? 'מנהל עמותה לדוגמה' : 'יוסי מתנדב',
        "email" => $role === 'ngo_manager' ? 'manager@hiburim.org' : 'volunteer@hiburim.org',
        "phone" => '050-123-4567',
        "area" => 'חולון ובת ים',
        "raw_user" => [],
        "raw_volunteer" => [],
        "is_demo" => true
    ];
}

function getCurrentUser($pdo = null) {
    if ($pdo === null && isset($GLOBALS["pdo"])) {
        $pdo = $GLOBALS["pdo"];
    }

    if (!$pdo) {
        return null;
    }

    $user = mpAuthCurrentUserFromSession($pdo);

    if ($user) {
        return $user;
    }

    return mpAuthDemoUser($pdo);
}

function requireLogin($pdo = null) {
    $user = getCurrentUser($pdo);

    if (!$user) {
        mpAuthJsonResponse([
            "success" => false,
            "error" => "unauthorized",
            "message" => "נדרשת התחברות למערכת."
        ], 401);
    }

    return $user;
}

function requireRole($allowedRoles, $user = null) {
    $allowed = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
    $allowed = array_map("mpAuthNormalizeRole", $allowed);
    $user = $user ?: getCurrentUser();
    $role = mpAuthNormalizeRole($user["role"] ?? "");

    if (!in_array($role, $allowed, true)) {
        mpAuthJsonResponse([
            "success" => false,
            "message" => "אין לך הרשאה לבצע פעולה זו."
        ], 403);
    }

    return $user;
}

function mpAuthIsManager($user) {
    return mpAuthNormalizeRole($user["role"] ?? "") === "ngo_manager";
}

function mpAuthIsVolunteer($user) {
    return mpAuthNormalizeRole($user["role"] ?? "") === "volunteer";
}

function mpAuthResourceOrganizationId($pdo, $tableName, $id) {
    if (!$id || !mpDbTableExists($pdo, $tableName)) {
        return null;
    }

    $columns = mpDbTableColumns($pdo, $tableName);
    $selectColumns = ["id"];

    foreach (["organization_id", "org_id", "ngo_id", "association_id", "assigned_org_id", "assigned_organization_id"] as $column) {
        if (mpColumnExists($columns, $column)) {
            $selectColumns[] = mpQuoteIdentifier($column);
        }
    }

    try {
        $stmt = $pdo->prepare("SELECT " . implode(", ", $selectColumns) . " FROM " . mpQuoteIdentifier($tableName) . " WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => (int)$id]);
        $row = $stmt->fetch();

        return $row ? mpAuthOrganizationIdFromRow($row) : null;
    } catch (Throwable $e) {
        error_log("AUTH ORG LOOKUP ERROR: " . $e->getMessage());
        return null;
    }
}

function mpAuthVolunteerUserOrganizationId($pdo, $volunteerId) {
    if (!$volunteerId || !mpDbTableExists($pdo, "volunteers") || !mpDbTableExists($pdo, "users")) {
        return null;
    }

    $volunteerColumns = mpDbTableColumns($pdo, "volunteers");
    $userColumns = mpDbTableColumns($pdo, "users");

    if (!mpColumnExists($volunteerColumns, "user_id") || !mpColumnExists($userColumns, "id")) {
        return null;
    }

    $selectColumns = [];

    foreach (["organization_id", "org_id", "ngo_id", "association_id", "assigned_org_id", "assigned_organization_id"] as $column) {
        if (mpColumnExists($userColumns, $column)) {
            $selectColumns[] = "u." . mpQuoteIdentifier($column) . " AS " . mpQuoteIdentifier($column);
        }
    }

    if (!$selectColumns) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT " . implode(", ", $selectColumns) . "
            FROM volunteers v
            INNER JOIN users u ON u.id = v.user_id
            WHERE v.id = :volunteer_id
            LIMIT 1
        ");
        $stmt->execute([":volunteer_id" => (int)$volunteerId]);
        $row = $stmt->fetch();

        return $row ? mpAuthOrganizationIdFromRow($row) : null;
    } catch (Throwable $e) {
        error_log("AUTH VOLUNTEER USER ORG LOOKUP ERROR: " . $e->getMessage());
        return null;
    }
}

function mpAuthReportVolunteerId($pdo, $report) {
    $volunteerId = mpAuthFirstInt($report, ["volunteer_id"]);

    if ($volunteerId) {
        return $volunteerId;
    }

    $reportId = mpAuthFirstInt($report, ["id", "report_id"]);

    if (!$reportId || !mpDbTableExists($pdo, "reports")) {
        return null;
    }

    $reportColumns = mpDbTableColumns($pdo, "reports");

    if (!mpColumnExists($reportColumns, "id") || !mpColumnExists($reportColumns, "volunteer_id")) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT volunteer_id FROM reports WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => (int)$reportId]);
        $row = $stmt->fetch();

        return $row ? mpAuthFirstInt($row, ["volunteer_id"]) : null;
    } catch (Throwable $e) {
        error_log("AUTH REPORT VOLUNTEER LOOKUP ERROR: " . $e->getMessage());
        return null;
    }
}

function requireOrganizationAccess($pdo, $resourceOrganizationId, $user = null) {
    $user = $user ?: requireLogin($pdo);
    $resourceOrganizationId = (int)$resourceOrganizationId;
    $userOrganizationId = (int)($user["organization_id"] ?? 0);

    if ($resourceOrganizationId && $userOrganizationId && $resourceOrganizationId !== $userOrganizationId) {
        mpAuthJsonResponse([
            "success" => false,
            "message" => "אין לך הרשאה לגשת לנתונים של עמותה אחרת."
        ], 403);
    }

    return true;
}

function mpAuthVolunteerAssignedToElderly($pdo, $volunteerId, $elderlyId) {
    $volunteerId = (int)$volunteerId;
    $elderlyId = (int)$elderlyId;

    if (!$volunteerId || !$elderlyId) {
        return false;
    }

    if (mpDbTableExists($pdo, "volunteer_elderly_assignments")) {
        $columns = mpDbTableColumns($pdo, "volunteer_elderly_assignments");

        if (mpColumnExists($columns, "volunteer_id") && mpColumnExists($columns, "elderly_id")) {
            $statusCondition = mpColumnExists($columns, "status")
                ? " AND COALESCE(status, 'active') IN ('active', 'פעיל', 'פעילה', 'בתוקף')"
                : "";

            $stmt = $pdo->prepare("
                SELECT 1
                FROM volunteer_elderly_assignments
                WHERE volunteer_id = :volunteer_id
                  AND elderly_id = :elderly_id
                  " . $statusCondition . "
                LIMIT 1
            ");
            $stmt->execute([
                ":volunteer_id" => $volunteerId,
                ":elderly_id" => $elderlyId
            ]);

            return (bool)$stmt->fetchColumn();
        }
    }

    if (!mpDbTableExists($pdo, "elderly")) {
        return false;
    }

    $elderlyColumns = mpDbTableColumns($pdo, "elderly");
    $assignmentColumns = array_values(array_filter([
        mpColumnExists($elderlyColumns, "volunteer_id") ? "volunteer_id" : null,
        mpColumnExists($elderlyColumns, "assigned_volunteer_id") ? "assigned_volunteer_id" : null,
        mpColumnExists($elderlyColumns, "primary_volunteer_id") ? "primary_volunteer_id" : null
    ]));

    if (!$assignmentColumns) {
        return false;
    }

    $where = implode(" OR ", array_map(function ($column) {
        return mpQuoteIdentifier($column) . " = :volunteer_id";
    }, $assignmentColumns));

    $stmt = $pdo->prepare("
        SELECT 1
        FROM elderly
        WHERE id = :elderly_id
          AND (" . $where . ")
        LIMIT 1
    ");
    $stmt->execute([
        ":elderly_id" => $elderlyId,
        ":volunteer_id" => $volunteerId
    ]);

    return (bool)$stmt->fetchColumn();
}

function canAccessElderly($pdo, $elderlyId, $user = null) {
    $user = $user ?: getCurrentUser($pdo);

    if (!$user || !$elderlyId) {
        return false;
    }

    if (mpAuthIsManager($user)) {
        $orgId = mpAuthResourceOrganizationId($pdo, "elderly", $elderlyId);

        return $orgId && (int)$orgId === (int)($user["organization_id"] ?? 0);
    }

    return mpAuthVolunteerAssignedToElderly($pdo, (int)($user["volunteer_id"] ?? 0), (int)$elderlyId);
}

function mpAuthReportOrganizationId($pdo, $report) {
    $reportOrgId = mpAuthOrganizationIdFromRow($report);

    if ($reportOrgId) {
        return $reportOrgId;
    }

    $volunteerId = mpAuthReportVolunteerId($pdo, $report);

    if ($volunteerId) {
        $volunteerUserOrgId = mpAuthVolunteerUserOrganizationId($pdo, $volunteerId);

        if ($volunteerUserOrgId) {
            return $volunteerUserOrgId;
        }

        $volunteerOrgId = mpAuthResourceOrganizationId($pdo, "volunteers", $volunteerId);

        if ($volunteerOrgId) {
            return $volunteerOrgId;
        }
    }

    if (!empty($report["elderly_organization_id"])) {
        return (int)$report["elderly_organization_id"];
    }

    if (!empty($report["elderly_id"])) {
        $elderlyOrgId = mpAuthResourceOrganizationId($pdo, "elderly", (int)$report["elderly_id"]);

        if ($elderlyOrgId) {
            return $elderlyOrgId;
        }
    }

    return null;
}

function canAccessReportData($pdo, $report, $user = null) {
    $user = $user ?: getCurrentUser($pdo);

    if (!$user || !$report) {
        return false;
    }

    if (mpAuthIsVolunteer($user)) {
        $volunteerId = (int)($user["volunteer_id"] ?? 0);
        $reportVolunteerId = mpAuthReportVolunteerId($pdo, $report);

        return $volunteerId && $reportVolunteerId && (int)$reportVolunteerId === $volunteerId;
    }

    if (mpAuthIsManager($user)) {
        $reportOrgId = mpAuthReportOrganizationId($pdo, $report);

        return $reportOrgId && (int)$reportOrgId === (int)($user["organization_id"] ?? 0);
    }

    return false;
}

function canAccessReport($pdo, $reportId, $user = null) {
    if (!$reportId || !mpDbTableExists($pdo, "reports")) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => (int)$reportId]);
        $report = $stmt->fetch();

        return $report ? canAccessReportData($pdo, $report, $user) : false;
    } catch (Throwable $e) {
        error_log("AUTH REPORT ACCESS ERROR: " . $e->getMessage());
        return false;
    }
}

function mpAuthFilterReports($pdo, $reports, $user = null) {
    $user = $user ?: getCurrentUser($pdo);
    $filtered = [];

    foreach ($reports as $report) {
        if (canAccessReportData($pdo, $report, $user)) {
            $filtered[] = $report;
        }
    }

    return $filtered;
}
