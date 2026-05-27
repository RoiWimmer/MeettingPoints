<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/auth.php';

function aiJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function aiEnvValue($key, $default = null) {
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

function aiParseHttpStatus($headers) {
    if (!is_array($headers)) {
        return null;
    }

    foreach ($headers as $header) {
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            return (int)$matches[1];
        }
    }

    return null;
}

function aiDecodeJsonFromText($text) {
    $clean = trim((string)$text);
    $clean = preg_replace('/^```json\s*/u', '', $clean);
    $clean = preg_replace('/^```\s*/u', '', $clean);
    $clean = preg_replace('/\s*```$/u', '', $clean);
    $clean = trim($clean);
    $decoded = json_decode($clean, true);

    return is_array($decoded) ? $decoded : null;
}

function aiCallGemini($prompt) {
    $apiKey = aiEnvValue("GEMINI_API_KEY");

    if (!$apiKey) {
        return [
            "ok" => false,
            "error" => "missing_api_key"
        ];
    }

    $model = aiEnvValue("GEMINI_MODEL_GEMINI_2_5_FLASH", aiEnvValue("GEMINI_MODEL_GEMINI_3_FLASH", "gemini-2.5-flash"));
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ],
        "generationConfig" => [
            "temperature" => 0.2,
            "responseMimeType" => "application/json"
        ]
    ];

    $context = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json\r\n",
            "content" => json_encode($payload, JSON_UNESCAPED_UNICODE),
            "ignore_errors" => true,
            "timeout" => 45
        ]
    ]);

    $result = @file_get_contents($url, false, $context);
    $status = aiParseHttpStatus(isset($http_response_header) ? $http_response_header : []);

    if ($result === false || trim($result) === "") {
        $lastError = error_get_last();
        return [
            "ok" => false,
            "error" => $lastError["message"] ?? "gemini_request_failed",
            "http_status" => $status
        ];
    }

    $response = json_decode($result, true);

    if (!is_array($response) || isset($response["error"])) {
        return [
            "ok" => false,
            "error" => $response["error"]["message"] ?? "invalid_gemini_response",
            "http_status" => $status
        ];
    }

    $text = $response["candidates"][0]["content"]["parts"][0]["text"] ?? "";

    if (trim($text) === "") {
        return [
            "ok" => false,
            "error" => "empty_gemini_text",
            "http_status" => $status
        ];
    }

    return [
        "ok" => true,
        "text" => $text
    ];
}

function aiFilterReports($reports, $filters) {
    $range = $filters["range"] ?? "month";
    $area = $filters["area"] ?? "all";
    $daysByRange = [
        "week" => 7,
        "month" => 31,
        "quarter" => 92,
        "year" => 365
    ];
    $days = $daysByRange[$range] ?? 31;
    $maxDate = null;

    foreach ($reports as $report) {
        try {
            $createdAt = new DateTime((string)($report["created_at"] ?? ""));
        } catch (Throwable $e) {
            continue;
        }

        if ($maxDate === null || $createdAt > $maxDate) {
            $maxDate = $createdAt;
        }
    }

    if ($maxDate) {
        $from = clone $maxDate;
    } else {
        $from = new DateTime();
    }

    $from->modify("-" . $days . " days");
    $filtered = [];

    foreach ($reports as $report) {
        try {
            $createdAt = new DateTime((string)($report["created_at"] ?? ""));
        } catch (Throwable $e) {
            continue;
        }

        if ($createdAt < $from) {
            continue;
        }

        if ($area !== "all" && ($report["area"] ?? "לא ידוע") !== $area) {
            continue;
        }

        $filtered[] = $report;
    }

    return $filtered;
}

function aiTopEntry($counts, $defaultLabel = "אין נתונים") {
    if (!$counts) {
        return [$defaultLabel, 0];
    }

    arsort($counts);

    foreach ($counts as $key => $value) {
        return [$key, (int)$value];
    }

    return [$defaultLabel, 0];
}

function aiChartForType($type, $stats) {
    if ($type === "resources") {
        return ["bar", $stats["resource_gaps_by_need_type"]];
    }

    if ($type === "open") {
        return ["donut", $stats["by_status"]];
    }

    if ($type === "urgent") {
        return ["bar", $stats["by_area"]];
    }

    if ($type === "needs") {
        return ["bar", $stats["by_need_type"]];
    }

    return ["line", $stats["reports_by_month"]];
}

function aiFallbackReport($prompt, $filters, $stats, $sourceCount) {
    $type = $filters["type"] ?? "executive";
    $rangeTitles = [
        "week" => "השבוע",
        "month" => "החודש",
        "quarter" => "3 חודשים",
        "year" => "השנה"
    ];
    $typeTitles = [
        "executive" => "סיכום הנהלה",
        "resources" => "דוח חוסרי משאבים",
        "open" => "דוח סטטוסים פתוחים",
        "needs" => "דוח צרכים נפוצים",
        "urgent" => "דוח חריגים ודחופים",
        "custom" => "דוח מותאם אישית"
    ];
    $topNeed = aiTopEntry($stats["by_need_type"] ?? []);
    $topGap = aiTopEntry($stats["resource_gaps_by_need_type"] ?? [], "אין חוסר מרכזי");
    $period = $rangeTitles[$filters["range"] ?? "month"] ?? "החודש";
    $areaText = ($filters["area"] ?? "all") !== "all" ? " באזור " . $filters["area"] : "";
    $limitedData = $sourceCount < 5 ? " מאחר שמדובר במדגם קטן, יש להתייחס לתובנות בזהירות." : "";
    list($chartType, $chartData) = aiChartForType($type, $stats);

    return [
        "success" => true,
        "title" => ($typeTitles[$type] ?? "דוח AI") . " - " . $period,
        "summary" => "נותחו " . $sourceCount . " דיווחים" . $areaText . ". הצורך הנפוץ ביותר הוא " . $topNeed[0] . " (" . $topNeed[1] . " דיווחים), ויש " . ($stats["urgent_open"] ?? 0) . " מקרים דחופים פתוחים ו-" . ($stats["resource_gaps"] ?? 0) . " חוסרי משאבים." . $limitedData,
        "insights" => [
            "הצורך המוביל הוא " . $topNeed[0] . " עם " . $topNeed[1] . " דיווחים בתקופה.",
            "קיימים " . ($stats["open"] ?? 0) . " מקרים פתוחים, מתוכם " . ($stats["urgent_open"] ?? 0) . " בדחיפות גבוהה.",
            $topGap[1] ? "פער המשאבים המרכזי הוא בתחום " . $topGap[0] . " עם " . $topGap[1] . " מקרים." : "לא זוהה חוסר משאבים מרכזי בנתונים שסוננו.",
            "ממוצע הימים הפתוחים הוא " . ($stats["average_days_open"] ?? 0) . " ימים."
        ],
        "recommendations" => [
            "לטפל קודם במקרים דחופים שאינם סגורים.",
            "לעקוב אחר מקרים שפתוחים מעל 5 ימים.",
            $topGap[1] ? "לאתר גורם מטפל או משאב נוסף בתחום " . $topGap[0] . "." : "לשמר את מנגנון שיוך הגורמים הקיים.",
            "לבחון אחת לשבוע את קטגוריות הצורך המובילות ולוודא שיש להן מענה."
        ],
        "stats" => [
            "total" => $stats["total"] ?? 0,
            "open" => $stats["open"] ?? 0,
            "urgent" => $stats["urgent_open"] ?? 0,
            "gaps" => $stats["resource_gaps"] ?? 0,
            "avgDays" => $stats["average_days_open"] ?? 0
        ],
        "chartType" => $chartType,
        "chartData" => $chartData,
        "sourceCount" => $sourceCount,
        "fallback_used" => true
    ];
}

function aiBuildPrompt($userPrompt, $filters, $stats, $sourceCount) {
    $compactStats = [
        "sourceCount" => $sourceCount,
        "filters" => $filters,
        "stats" => $stats
    ];

    return "אתה יוצר דוח ניהולי לעמותת נקודות חיבור על בסיס סטטיסטיקות מסוכמות בלבד.
אסור להמציא מספרים. השתמש רק במספרים שמופיעים בנתונים.
אם sourceCount קטן או אין מספיק נתונים, ציין שהמדגם מוגבל.
אין פרטים אישיים בנתונים ואין להוסיף כאלה.
ענה JSON תקין בלבד, בלי Markdown ובלי טקסט חיצוני.

פורמט JSON חובה:
{
  \"title\": \"...\",
  \"summary\": \"...\",
  \"insights\": [\"...\"],
  \"recommendations\": [\"...\"],
  \"chartType\": \"bar|donut|line\",
  \"chartData\": {}
}

בקשת המשתמש:
" . $userPrompt . "

נתונים מסוכמים:
" . json_encode($compactStats, JSON_UNESCAPED_UNICODE);
}

$currentUser = requireLogin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aiJsonResponse([
        "success" => false,
        "message" => "Method not allowed"
    ], 405);
}

requireRole("ngo_manager", $currentUser);

$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    aiJsonResponse([
        "success" => false,
        "message" => "Invalid JSON body"
    ], 400);
}

$prompt = trim((string)($input["prompt"] ?? ""));
$filters = is_array($input["filters"] ?? null) ? $input["filters"] : [];
$filters["range"] = in_array($filters["range"] ?? "month", ["week", "month", "quarter", "year"], true) ? $filters["range"] : "month";
$filters["area"] = trim((string)($filters["area"] ?? "all"));
$filters["area"] = $filters["area"] !== "" ? $filters["area"] : "all";
$filters["type"] = in_array($filters["type"] ?? "executive", ["executive", "resources", "open", "needs", "urgent", "custom"], true) ? $filters["type"] : "executive";

if ($prompt === "") {
    $prompt = "צור תקציר מנהלים על מצב הדיווחים בתקופה הנבחרת";
}

try {
    $reports = aiFilterReports(mpAuthFilterReports($pdo, mpFetchNormalizedReports($pdo), $currentUser), $filters);
    $stats = mpBuildReportStats($reports);
    $sourceCount = count($reports);
    $fallback = aiFallbackReport($prompt, $filters, $stats, $sourceCount);
    $gemini = aiCallGemini(aiBuildPrompt($prompt, $filters, $stats, $sourceCount));

    if (empty($gemini["ok"])) {
        error_log("AI REPORT GEMINI FALLBACK: " . ($gemini["error"] ?? "unknown"));
        aiJsonResponse($fallback);
    }

    $aiResult = aiDecodeJsonFromText($gemini["text"] ?? "");

    if (!$aiResult) {
        error_log("AI REPORT INVALID JSON: " . substr((string)($gemini["text"] ?? ""), 0, 500));
        aiJsonResponse($fallback);
    }

    list($fallbackChartType, $fallbackChartData) = aiChartForType($filters["type"], $stats);

    aiJsonResponse([
        "success" => true,
        "title" => trim((string)($aiResult["title"] ?? $fallback["title"])),
        "summary" => trim((string)($aiResult["summary"] ?? $fallback["summary"])),
        "insights" => array_values(array_filter((array)($aiResult["insights"] ?? $fallback["insights"]))),
        "recommendations" => array_values(array_filter((array)($aiResult["recommendations"] ?? $fallback["recommendations"]))),
        "stats" => $fallback["stats"],
        "chartType" => $fallbackChartType,
        "chartData" => $fallbackChartData,
        "sourceCount" => $sourceCount,
        "fallback_used" => false
    ]);

} catch (Throwable $e) {
    error_log("AI REPORT API ERROR: " . $e->getMessage());
    aiJsonResponse([
        "success" => false,
        "message" => "לא הצלחנו ליצור דוח AI כרגע."
    ], 500);
}
