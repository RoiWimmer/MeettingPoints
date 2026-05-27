<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/auth.php';

function mpLoginResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function mpLoginInput() {
    $decoded = json_decode(file_get_contents("php://input"), true);

    return is_array($decoded) ? $decoded : [];
}

function mpLoginUserLookup($pdo, $login) {
    if (!mpDbTableExists($pdo, "users")) {
        return null;
    }

    $columns = mpDbTableColumns($pdo, "users");
    $where = [];
    $params = [];

    if (mpColumnExists($columns, "email")) {
        $where[] = "email = :email_login";
        $params[":email_login"] = $login;
    }

    if (mpColumnExists($columns, "username")) {
        $where[] = "username = :username_login";
        $params[":username_login"] = $login;
    }

    if (!$where) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE " . implode(" OR ", $where) . "
        LIMIT 1
    ");
    $stmt->execute($params);
    $user = $stmt->fetch();

    return $user ?: null;
}

function mpLoginPasswordHash($columns, $userRow) {
    foreach (["password_hash", "password"] as $column) {
        if (mpColumnExists($columns, $column) && !empty($userRow[$column])) {
            return (string)$userRow[$column];
        }
    }

    return "";
}

function mpLoginVolunteerForUser($pdo, $userId) {
    if (!$userId || !mpDbTableExists($pdo, "volunteers")) {
        return null;
    }

    $columns = mpDbTableColumns($pdo, "volunteers");
    $where = [];
    $params = [];

    foreach (["user_id", "account_id"] as $column) {
        if (mpColumnExists($columns, $column)) {
            $placeholder = ":" . $column;
            $where[] = mpQuoteIdentifier($column) . " = " . $placeholder;
            $params[$placeholder] = (int)$userId;
        }
    }

    if (!$where) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM volunteers
        WHERE " . implode(" OR ", $where) . "
        LIMIT 1
    ");
    $stmt->execute($params);
    $volunteer = $stmt->fetch();

    return $volunteer ?: null;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    mpLoginResponse([
        "success" => false,
        "message" => "Method not allowed"
    ], 405);
}

try {
    $input = mpLoginInput();
    $login = trim((string)($input["login"] ?? $input["email"] ?? $input["username"] ?? ""));
    $password = (string)($input["password"] ?? "");

    if ($login === "" || $password === "") {
        mpLoginResponse([
            "success" => false,
            "message" => "יש להזין אימייל/שם משתמש וסיסמה."
        ], 400);
    }

    if (!mpDbTableExists($pdo, "users")) {
        mpLoginResponse([
            "success" => false,
            "message" => "טבלת המשתמשים לא קיימת. יש להריץ את migration הדמו וההרשאות."
        ], 500);
    }

    $userColumns = mpDbTableColumns($pdo, "users");
    $userRow = mpLoginUserLookup($pdo, $login);
    $passwordHash = $userRow ? mpLoginPasswordHash($userColumns, $userRow) : "";

    if (!$userRow || $passwordHash === "" || !password_verify($password, $passwordHash)) {
        mpLoginResponse([
            "success" => false,
            "message" => "פרטי ההתחברות אינם נכונים."
        ], 401);
    }

    $role = mpAuthNormalizeRole(mpAuthFirstValue($userRow, ["role", "account_type", "user_type"], "volunteer"));
    $volunteer = $role === "volunteer" ? mpLoginVolunteerForUser($pdo, (int)$userRow["id"]) : null;
    $volunteerId = $volunteer ? (int)$volunteer["id"] : null;
    $organizationId = mpAuthOrganizationIdFromRow($userRow);

    if (!$organizationId) {
        mpLoginResponse([
            "success" => false,
            "message" => "המשתמש אינו משויך לעמותה. יש לבדוק את נתוני החשבון."
        ], 403);
    }

    if ($role === "volunteer" && !$volunteerId) {
        mpLoginResponse([
            "success" => false,
            "message" => "משתמש המתנדב אינו מחובר לרשומת מתנדב. יש לבדוק את נתוני הדמו."
        ], 403);
    }

    session_regenerate_id(true);

    $_SESSION["user_id"] = (int)$userRow["id"];
    $_SESSION["role"] = $role;
    $_SESSION["organization_id"] = $organizationId;
    $_SESSION["volunteer_id"] = $volunteerId;
    $_SESSION["full_name"] = mpAuthNameFromRow($userRow);
    $_SESSION["email"] = (string)mpAuthFirstValue($userRow, ["email"], "");
    $_SESSION["phone"] = (string)mpAuthFirstValue($userRow, ["phone", "phone_number", "mobile"], "");
    $_SESSION["area"] = (string)mpAuthFirstValue($userRow, ["area", "service_area", "city"], "");

    $currentUser = requireLogin($pdo);
    $publicUser = mpAuthPublicUser($currentUser);

    mpLoginResponse([
        "success" => true,
        "message" => "התחברת בהצלחה.",
        "user" => $publicUser,
        "redirect" => "index.html"
    ]);

} catch (Throwable $e) {
    error_log("LOGIN API ERROR: " . $e->getMessage());
    mpLoginResponse([
        "success" => false,
        "message" => "לא הצלחנו להתחבר כרגע."
    ], 500);
}
