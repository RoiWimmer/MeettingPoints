<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/auth.php';

try {
    $currentUser = requireLogin($pdo);
    $reports = mpAuthFilterReports($pdo, mpFetchNormalizedReports($pdo), $currentUser);

    echo json_encode([
        "success" => true,
        "reports" => $reports,
        "stats" => mpBuildReportStats($reports),
        "current_user" => mpAuthPublicUser($currentUser),
        "permissions" => mpAuthUserPermissions($currentUser["role"] ?? "volunteer")
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("REPORTS API ERROR: " . $e->getMessage());
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "error" => "Failed to load reports",
        "message" => "לא הצלחנו לטעון את הדיווחים כרגע."
    ], JSON_UNESCAPED_UNICODE);
}
