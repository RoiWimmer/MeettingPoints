<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/auth.php';

function mpProfileResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function mpProfileInput() {
    $raw = file_get_contents("php://input");
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function mpProfileFirstExistingColumn($columns, $candidates) {
    foreach ($candidates as $column) {
        if (mpColumnExists($columns, $column)) {
            return $column;
        }
    }

    return null;
}

function mpProfileBool($value) {
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    return in_array(strtolower((string)$value), ["1", "true", "yes", "on"], true) ? 1 : 0;
}

function mpProfileValidatePhone($phone) {
    $phone = trim((string)$phone);

    if ($phone === "") {
        return true;
    }

    return preg_match('/^[0-9+\-\s()]{7,20}$/', $phone) === 1;
}

function mpProfilePasswordColumn($columns) {
    return mpProfileFirstExistingColumn($columns, ["password_hash", "password"]);
}

function mpProfilePasswordMatches($storedPassword, $oldPassword) {
    $storedPassword = (string)$storedPassword;
    $oldPassword = (string)$oldPassword;

    if ($storedPassword === "" || $oldPassword === "") {
        return false;
    }

    if (password_verify($oldPassword, $storedPassword)) {
        return true;
    }

    return hash_equals($storedPassword, $oldPassword);
}

function mpProfileLoadUserRow($pdo, $currentUser) {
    $userId = (int)($currentUser["user_id"] ?? $currentUser["id"] ?? 0);

    if (!$userId || !mpDbTableExists($pdo, "users")) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([":id" => $userId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function mpProfileBuildPayload($pdo, $currentUser) {
    $userRow = mpProfileLoadUserRow($pdo, $currentUser);
    $userColumns = mpDbTableExists($pdo, "users") ? mpDbTableColumns($pdo, "users") : [];
    $passwordColumn = $userColumns ? mpProfilePasswordColumn($userColumns) : null;

    $notifyUrgent = mpAuthFirstValue($userRow, ["notify_urgent_reports"], 1);
    $notifyWeekly = mpAuthFirstValue($userRow, ["notify_weekly_summary"], 1);

    return [
        "success" => true,
        "profile" => [
            "full_name" => $currentUser["full_name"] ?? "",
            "phone" => $currentUser["phone"] ?? "",
            "email" => $currentUser["email"] ?? "",
            "area" => $currentUser["area"] ?? "",
            "role" => $currentUser["role"] ?? "volunteer",
            "role_display" => mpAuthRoleDisplay($currentUser["role"] ?? "volunteer"),
            "organization_id" => $currentUser["organization_id"] ?? null,
            "volunteer_id" => $currentUser["volunteer_id"] ?? null,
            "notifications" => [
                "urgent_reports" => (bool)$notifyUrgent,
                "weekly_summary" => (bool)$notifyWeekly
            ],
            "supports_password_change" => (bool)$passwordColumn,
            "can_save" => (bool)$userRow
        ],
        "current_user" => mpAuthPublicUser($currentUser)
    ];
}

function mpProfileAddUpdate(&$sets, &$params, $columns, $candidates, $value, $paramName) {
    $column = mpProfileFirstExistingColumn($columns, $candidates);

    if (!$column) {
        return false;
    }

    $sets[] = mpQuoteIdentifier($column) . " = :" . $paramName;
    $params[":" . $paramName] = $value;

    return true;
}

try {
    $currentUser = requireLogin($pdo);
    $method = $_SERVER["REQUEST_METHOD"];

    if ($method === "GET") {
        mpProfileResponse(mpProfileBuildPayload($pdo, $currentUser));
    }

    if (!in_array($method, ["POST", "PUT"], true)) {
        mpProfileResponse([
            "success" => false,
            "message" => "Method not allowed"
        ], 405);
    }

    $userId = (int)($currentUser["user_id"] ?? $currentUser["id"] ?? 0);
    $userRow = mpProfileLoadUserRow($pdo, $currentUser);

    if (!$userId || !$userRow) {
        mpProfileResponse([
            "success" => false,
            "message" => "לא נמצאה טבלת משתמשים פעילה לשמירת הפרופיל. יש להריץ את migration ההרשאות."
        ], 500);
    }

    $input = mpProfileInput();

    if (isset($input["role"]) && mpAuthNormalizeRole($input["role"]) !== mpAuthNormalizeRole($currentUser["role"] ?? "")) {
        mpProfileResponse([
            "success" => false,
            "message" => "לא ניתן לשנות סוג חשבון דרך מסך הפרופיל."
        ], 403);
    }

    if (isset($input["organization_id"]) && (int)$input["organization_id"] !== (int)($currentUser["organization_id"] ?? 0)) {
        mpProfileResponse([
            "success" => false,
            "message" => "לא ניתן לשנות עמותה דרך מסך הפרופיל."
        ], 403);
    }

    $fullName = trim((string)($input["full_name"] ?? ""));
    $phone = trim((string)($input["phone"] ?? ""));
    $email = trim((string)($input["email"] ?? ""));
    $area = trim((string)($input["area"] ?? ""));

    $fullNameLength = function_exists("mb_strlen") ? mb_strlen($fullName, "UTF-8") : strlen($fullName);

    if ($fullName === "" || $fullNameLength < 2) {
        mpProfileResponse([
            "success" => false,
            "message" => "יש להזין שם מלא תקין."
        ], 400);
    }

    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mpProfileResponse([
            "success" => false,
            "message" => "כתובת האימייל אינה תקינה."
        ], 400);
    }

    if (!mpProfileValidatePhone($phone)) {
        mpProfileResponse([
            "success" => false,
            "message" => "מספר הטלפון אינו תקין."
        ], 400);
    }

    $userColumns = mpDbTableColumns($pdo, "users");
    $sets = [];
    $params = [":id" => $userId];

    mpProfileAddUpdate($sets, $params, $userColumns, ["full_name", "name", "display_name"], $fullName, "full_name");
    mpProfileAddUpdate($sets, $params, $userColumns, ["phone", "phone_number", "mobile"], $phone, "phone");
    mpProfileAddUpdate($sets, $params, $userColumns, ["email"], $email, "email");
    mpProfileAddUpdate($sets, $params, $userColumns, ["area", "service_area", "city"], $area, "area");

    if (isset($input["notifications"]) && is_array($input["notifications"])) {
        if (mpColumnExists($userColumns, "notify_urgent_reports")) {
            $sets[] = "notify_urgent_reports = :notify_urgent_reports";
            $params[":notify_urgent_reports"] = mpProfileBool($input["notifications"]["urgent_reports"] ?? 0);
        }

        if (mpColumnExists($userColumns, "notify_weekly_summary")) {
            $sets[] = "notify_weekly_summary = :notify_weekly_summary";
            $params[":notify_weekly_summary"] = mpProfileBool($input["notifications"]["weekly_summary"] ?? 0);
        }
    }

    $newPassword = (string)($input["new_password"] ?? "");
    $oldPassword = (string)($input["old_password"] ?? "");

    if ($newPassword !== "") {
        $passwordColumn = mpProfilePasswordColumn($userColumns);

        if (!$passwordColumn) {
            mpProfileResponse([
                "success" => false,
                "message" => "החלפת סיסמה אינה נתמכת בטבלת המשתמשים הנוכחית."
            ], 400);
        }

        if (strlen($newPassword) < 8) {
            mpProfileResponse([
                "success" => false,
                "message" => "סיסמה חדשה חייבת להכיל לפחות 8 תווים."
            ], 400);
        }

        if (!mpProfilePasswordMatches($userRow[$passwordColumn] ?? "", $oldPassword)) {
            mpProfileResponse([
                "success" => false,
                "message" => "הסיסמה הישנה אינה נכונה."
            ], 400);
        }

        $sets[] = mpQuoteIdentifier($passwordColumn) . " = :password_hash";
        $params[":password_hash"] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    if (mpColumnExists($userColumns, "updated_at")) {
        $sets[] = "updated_at = NOW()";
    }

    if (!$sets) {
        mpProfileResponse([
            "success" => false,
            "message" => "לא נמצאו שדות שניתן לעדכן."
        ], 400);
    }

    $stmt = $pdo->prepare("
        UPDATE users
        SET " . implode(", ", $sets) . "
        WHERE id = :id
    ");
    $stmt->execute($params);

    $volunteerId = (int)($currentUser["volunteer_id"] ?? 0);

    if ($volunteerId && mpDbTableExists($pdo, "volunteers")) {
        $volunteerColumns = mpDbTableColumns($pdo, "volunteers");
        $volunteerSets = [];
        $volunteerParams = [":id" => $volunteerId];

        mpProfileAddUpdate($volunteerSets, $volunteerParams, $volunteerColumns, ["full_name", "name", "display_name"], $fullName, "volunteer_full_name");
        mpProfileAddUpdate($volunteerSets, $volunteerParams, $volunteerColumns, ["phone", "phone_number", "mobile"], $phone, "volunteer_phone");
        mpProfileAddUpdate($volunteerSets, $volunteerParams, $volunteerColumns, ["email"], $email, "volunteer_email");
        mpProfileAddUpdate($volunteerSets, $volunteerParams, $volunteerColumns, ["area", "service_area", "city"], $area, "volunteer_area");

        if ($volunteerSets) {
            $volunteerStmt = $pdo->prepare("
                UPDATE volunteers
                SET " . implode(", ", $volunteerSets) . "
                WHERE id = :id
            ");
            $volunteerStmt->execute($volunteerParams);
        }
    }

    $_SESSION["user_id"] = $userId;
    $_SESSION["full_name"] = $fullName;
    $_SESSION["email"] = $email;
    $_SESSION["phone"] = $phone;
    $_SESSION["area"] = $area;

    if (isset($_SESSION["user"]) && is_array($_SESSION["user"])) {
        $_SESSION["user"]["full_name"] = $fullName;
        $_SESSION["user"]["email"] = $email;
        $_SESSION["user"]["phone"] = $phone;
        $_SESSION["user"]["area"] = $area;
    }

    $updatedUser = requireLogin($pdo);

    mpProfileResponse([
        "success" => true,
        "message" => "הפרופיל עודכן בהצלחה.",
        "profile" => mpProfileBuildPayload($pdo, $updatedUser)["profile"],
        "current_user" => mpAuthPublicUser($updatedUser)
    ]);

} catch (Throwable $e) {
    error_log("PROFILE API ERROR: " . $e->getMessage());
    mpProfileResponse([
        "success" => false,
        "message" => "לא הצלחנו לעדכן את הפרופיל כרגע."
    ], 500);
}
