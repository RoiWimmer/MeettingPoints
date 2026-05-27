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

function aiTextLower($value) {
    $text = trim((string)$value);

    return function_exists("mb_strtolower") ? mb_strtolower($text, "UTF-8") : strtolower($text);
}

function aiNormalizeTopicText($value) {
    $text = aiTextLower($value);
    $text = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $text);
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);

    return trim($text);
}

function aiExtractTopicTokens($topic) {
    $normalized = aiNormalizeTopicText($topic);

    if ($normalized === "") {
        return [];
    }

    $stopwords = [
        "את", "כל", "של", "על", "אל", "עם", "או", "ו", "ה", "ל", "ב", "מ", "ש",
        "דוח", "דוחות", "דיווח", "דיווחים", "סכם", "סיכום", "תן", "לי", "צור",
        "קשור", "קשורה", "קשורים", "קשורות", "שקשור", "שקשורים", "שקשורות",
        "בנושא", "נושא", "לנושא", "לפי", "בעיות", "בעיה", "מקרים", "מקרה",
        "קשישים", "קשיש", "קשישה", "מרגישים", "מרגיש", "מרגישה", "מצב", "אזור",
        "האזור", "בתקופה", "התקופה", "האחרון", "האחרונה", "חודש", "חודשי"
    ];

    $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
    $tokens = array_values(array_filter($tokens, function ($token) use ($stopwords) {
        return function_exists("mb_strlen")
            ? mb_strlen($token, "UTF-8") >= 2 && !in_array($token, $stopwords, true)
            : strlen($token) >= 2 && !in_array($token, $stopwords, true);
    }));

    return array_values(array_unique($tokens));
}

function aiCanonicalTopicFromText($text) {
    $normalized = aiNormalizeTopicText($text);
    $knownTopics = [
        "בדידות" => '/בדיד|בודד|בודדה|בודדים|לבד|בודדות|חברה|חברתי|קשר חברתי|תמיכה רגשית|שיחה|ביקור/u',
        "בעיה תחזוקתית בבית" => '/תחזוק|תיקון|נזילה|חלון|דוד|מנעול|מקרר|חשמל|מים|בית|צנרת|רטיבות/u',
        "בעיה רפואית" => '/רפוא|בריאות|תרופה|תרופות|מרשם|רופא|בדיקה|כאב|חולה|מחלה|דמנציה|זיכרון|זכרון|בלבול/u',
        "מזון" => '/מזון|אוכל|ארוחה|קניות|מצרכים|סל מזון/u',
        "בטיחות" => '/בטיחות|סכנה|חירום|גז|שריפה|עשן|נפילה|הצפה|חשמל חשוף/u',
        "ליווי רפואי" => '/ליווי|הסעה|תור|מרפאה|קופת חולים/u',
        "סיוע מול רשויות" => '/רשויות|ביטוח לאומי|טופס|זכויות|חשבון|בירוקרט/u'
    ];

    foreach ($knownTopics as $topic => $pattern) {
        if (preg_match($pattern, $normalized)) {
            return $topic;
        }
    }

    return null;
}

function aiDetectTopicFromPrompt($userPrompt) {
    $prompt = aiNormalizeTopicText($userPrompt);

    if ($prompt === "") {
        return null;
    }

    $canonicalTopic = aiCanonicalTopicFromText($prompt);

    if ($canonicalTopic) {
        return $canonicalTopic;
    }

    $generalPatterns = [
        '/תקציר מנהלים/u',
        '/מצב הדיווחים/u',
        '/סטטוסים פתוחים/u',
        '/צרכים נפוצים/u',
        '/חוסרי משאבים/u',
        '/חריגים ודחופים/u',
        '/תמונת מצב/u',
        '/סיכום כללי/u',
        '/דוח חודשי/u',
        '/דוח שבועי/u'
    ];

    foreach ($generalPatterns as $pattern) {
        if (preg_match($pattern, $prompt)) {
            return null;
        }
    }

    $topicPatterns = [
        '/(?:שקשור(?:ים|ות)?|קשור(?:ים|ות)?|הקשור(?:ים|ות)?)\s+ל(.+)$/u',
        '/(?:בנושא|לנושא|על אודות|אודות|על)\s+(.+)$/u',
        '/(?:בעיות|מקרים)\s+(.+)$/u'
    ];

    foreach ($topicPatterns as $pattern) {
        if (preg_match($pattern, $prompt, $matches)) {
            $tokens = aiExtractTopicTokens($matches[1] ?? "");

            if ($tokens) {
                return implode(" ", array_slice($tokens, 0, 4));
            }
        }
    }

    return null;
}

function aiTopicTerms($topic) {
    $terms = aiExtractTopicTokens($topic);
    $canonicalTerms = [
        "בדידות" => ["בדידות", "בדיד", "בודד", "בודדה", "בודדים", "לבד", "בודדות", "חוסר קשר", "קשר חברתי", "תמיכה רגשית", "שיחה", "ביקור", "חברה", "חברתי"],
        "בעיה תחזוקתית בבית" => ["תחזוקה", "תחזוק", "תחזוקתית", "תיקון", "נזילה", "חלון", "דוד", "מנעול", "מקרר", "חשמל", "מים", "בית", "צנרת", "רטיבות"],
        "בעיה רפואית" => ["רפואי", "רפואית", "רפוא", "בריאות", "תרופה", "תרופות", "מרשם", "רופא", "בדיקה", "כאב", "חולה", "מחלה", "דמנציה", "זיכרון", "זכרון", "בלבול"],
        "מזון" => ["מזון", "אוכל", "ארוחה", "קניות", "מצרכים", "סל מזון"],
        "בטיחות" => ["בטיחות", "סכנה", "חירום", "גז", "שריפה", "עשן", "נפילה", "הצפה", "חשמל חשוף"],
        "ליווי רפואי" => ["ליווי", "הסעה", "תור", "מרפאה", "קופת חולים"],
        "סיוע מול רשויות" => ["רשויות", "ביטוח לאומי", "טופס", "זכויות", "חשבון", "בירוקרטיה", "בירוקרט"]
    ];

    if (isset($canonicalTerms[$topic])) {
        $terms = array_merge($terms, $canonicalTerms[$topic]);
    }

    return array_values(array_unique(array_filter(array_map("aiNormalizeTopicText", $terms))));
}

function aiReportTopicText($report) {
    $fields = [
        "need_type",
        "category",
        "parsed_category",
        "description",
        "parsed_description",
        "additional_details",
        "parsed_additional_details",
        "image_analysis",
        "content"
    ];
    $parts = [];

    foreach ($fields as $field) {
        if (!empty($report[$field])) {
            $parts[] = $report[$field];
        }
    }

    return aiNormalizeTopicText(implode(" ", $parts));
}

function aiReportMatchesTopic($report, $topic, $terms) {
    $text = aiReportTopicText($report);

    if ($text === "") {
        return false;
    }

    $score = 0;
    $topicText = aiNormalizeTopicText($topic);

    if ($topicText !== "" && preg_match('/(^|\s)' . preg_quote($topicText, '/') . '(\s|$)/u', $text)) {
        $score += 3;
    }

    $categoryText = aiNormalizeTopicText(implode(" ", [
        $report["need_type"] ?? "",
        $report["category"] ?? "",
        $report["parsed_category"] ?? ""
    ]));

    foreach ($terms as $term) {
        if ($term === "") {
            continue;
        }

        $termLength = function_exists("mb_strlen") ? mb_strlen($term, "UTF-8") : strlen($term);

        if ($termLength < 2) {
            continue;
        }

        if ($categoryText !== "" && strpos($categoryText, $term) !== false) {
            $score += 3;
            continue;
        }

        if (strpos($text, $term) !== false) {
            $score += 1;
        }
    }

    return $score >= 1;
}

function aiShortReportSummary($report) {
    $summary = trim((string)($report["description"] ?? $report["parsed_description"] ?? $report["content"] ?? ""));
    $summary = preg_replace('/\s+/u', ' ', $summary);

    if (function_exists("mb_substr")) {
        return mb_substr($summary, 0, 220, "UTF-8");
    }

    return substr($summary, 0, 220);
}

function aiBuildTopicFilterPrompt($userPrompt, $filters, $reports) {
    $items = [];

    foreach (array_slice(array_values($reports), 0, 140) as $report) {
        $items[] = [
            "id" => (int)($report["id"] ?? 0),
            "need_type" => $report["need_type"] ?? ($report["category"] ?? ""),
            "urgency" => $report["urgency"] ?? "",
            "status" => $report["normalized_status"] ?? ($report["status"] ?? ""),
            "created_at" => $report["created_at"] ?? "",
            "area" => $report["area"] ?? "",
            "summary" => aiShortReportSummary($report)
        ];
    }

    $payload = [
        "userPrompt" => $userPrompt,
        "filters" => $filters,
        "reports" => $items
    ];

    return "אתה מסנן דיווחים לפי בקשת נושא חופשית של מנהל עמותה.
קבע האם בקשת המשתמש דורשת סינון נושאי. אם כן, החזר רק מזהי דיווחים שרלוונטיים לנושא המבוקש.
אל תבחר דיווחים רק בגלל שהם באותו טווח זמן או אזור. השתמש רק בשדות המצומצמים שסופקו.
ענה JSON תקין בלבד, בלי Markdown ובלי טקסט חיצוני.

פורמט JSON חובה:
{
  \"applyFilter\": true,
  \"topic\": \"...\",
  \"matchingIds\": [1,2,3]
}

נתונים:
" . json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function aiApplyTopicFilter($reports, $userPrompt, $filters) {
    $activeTopic = aiDetectTopicFromPrompt($userPrompt);

    if (!$activeTopic) {
        return [
            "reports" => $reports,
            "activeTopic" => null,
            "topicFilterApplied" => false
        ];
    }

    $terms = aiTopicTerms($activeTopic);
    $filtered = array_values(array_filter($reports, function ($report) use ($activeTopic, $terms) {
        return aiReportMatchesTopic($report, $activeTopic, $terms);
    }));

    if ($filtered) {
        return [
            "reports" => $filtered,
            "activeTopic" => $activeTopic,
            "topicFilterApplied" => true
        ];
    }

    $gemini = aiCallGemini(aiBuildTopicFilterPrompt($userPrompt, $filters, $reports));

    if (!empty($gemini["ok"])) {
        $decision = aiDecodeJsonFromText($gemini["text"] ?? "");
        $ids = is_array($decision) ? array_map("intval", (array)($decision["matchingIds"] ?? [])) : [];
        $idLookup = array_fill_keys($ids, true);

        if (is_array($decision) && !empty($decision["topic"])) {
            $activeTopic = trim((string)$decision["topic"]);
        }

        $filtered = array_values(array_filter($reports, function ($report) use ($idLookup) {
            return isset($idLookup[(int)($report["id"] ?? 0)]);
        }));
    } else {
        error_log("AI REPORT TOPIC FILTER GEMINI FALLBACK: " . ($gemini["error"] ?? "unknown"));
    }

    return [
        "reports" => $filtered,
        "activeTopic" => $activeTopic,
        "topicFilterApplied" => true
    ];
}

function aiBuildSourceData($reports) {
    return array_values(array_map(function ($report) {
        return [
            "id" => (int)($report["id"] ?? 0),
            "createdAt" => substr((string)($report["created_at"] ?? ""), 0, 10),
            "needType" => $report["need_type"] ?? ($report["category"] ?? "אחר"),
            "area" => $report["area"] ?? "לא ידוע",
            "urgency" => $report["urgency"] ?? "בינונית",
            "status" => mpNormalizeStatus($report["normalized_status"] ?? ($report["status"] ?? "")),
            "daysOpen" => (int)($report["days_open"] ?? 0),
            "assignedOrg" => $report["assigned_org"] ?? null,
            "hasResourceGap" => !empty($report["resource_gap"]) || !empty($report["has_resource_gap"])
        ];
    }, $reports));
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

function aiFallbackReport($prompt, $filters, $stats, $sourceCount, $topicMeta = [], $sourceData = []) {
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
    $activeTopic = $topicMeta["activeTopic"] ?? null;
    $topicFilterApplied = !empty($topicMeta["topicFilterApplied"]);

    if ($topicFilterApplied && $sourceCount === 0) {
        return [
            "success" => true,
            "title" => "דוח בנושא " . $activeTopic,
            "summary" => "לא נמצאו דיווחים התואמים לנושא " . $activeTopic . " בתקופה ובאזור שנבחרו.",
            "insights" => [],
            "recommendations" => [],
            "stats" => [
                "total" => 0,
                "open" => 0,
                "urgent" => 0,
                "gaps" => 0,
                "avgDays" => 0
            ],
            "chartType" => "bar",
            "chartData" => [],
            "sourceCount" => 0,
            "sourceData" => [],
            "activeTopic" => $activeTopic,
            "topicFilterApplied" => true,
            "fallback_used" => true
        ];
    }

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
        "sourceData" => $sourceData,
        "activeTopic" => $activeTopic,
        "topicFilterApplied" => $topicFilterApplied,
        "fallback_used" => true
    ];
}

function aiBuildPrompt($userPrompt, $filters, $stats, $sourceCount, $topicMeta = []) {
    $compactStats = [
        "sourceCount" => $sourceCount,
        "activeTopic" => $topicMeta["activeTopic"] ?? null,
        "topicFilterApplied" => !empty($topicMeta["topicFilterApplied"]),
        "filters" => $filters,
        "stats" => $stats
    ];
    $topicInstruction = !empty($topicMeta["topicFilterApplied"])
        ? "\nהנתונים כבר סוננו לפי בקשת המשתמש. אל תתייחס לדוחות שלא נכללו בנתונים."
        : "";

    return "אתה יוצר דוח ניהולי לעמותת נקודות חיבור על בסיס סטטיסטיקות מסוכמות בלבד.
אסור להמציא מספרים. השתמש רק במספרים שמופיעים בנתונים.
אם sourceCount קטן או אין מספיק נתונים, ציין שהמדגם מוגבל.
אין פרטים אישיים בנתונים ואין להוסיף כאלה.
ענה JSON תקין בלבד, בלי Markdown ובלי טקסט חיצוני.
" . $topicInstruction . "

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
    $authorizedReports = mpAuthFilterReports($pdo, mpFetchNormalizedReports($pdo), $currentUser);
    $rangeAreaReports = aiFilterReports($authorizedReports, $filters);
    $topicResult = aiApplyTopicFilter($rangeAreaReports, $prompt, $filters);
    $reports = $topicResult["reports"];
    $stats = mpBuildReportStats($reports);
    $sourceCount = count($reports);
    $sourceData = aiBuildSourceData($reports);
    $fallback = aiFallbackReport($prompt, $filters, $stats, $sourceCount, $topicResult, $sourceData);

    if (!empty($topicResult["topicFilterApplied"]) && $sourceCount === 0) {
        aiJsonResponse($fallback);
    }

    $gemini = aiCallGemini(aiBuildPrompt($prompt, $filters, $stats, $sourceCount, $topicResult));

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
        "sourceData" => $sourceData,
        "activeTopic" => $topicResult["activeTopic"] ?? null,
        "topicFilterApplied" => !empty($topicResult["topicFilterApplied"]),
        "fallback_used" => false
    ]);

} catch (Throwable $e) {
    error_log("AI REPORT API ERROR: " . $e->getMessage());
    aiJsonResponse([
        "success" => false,
        "message" => "לא הצלחנו ליצור דוח AI כרגע."
    ], 500);
}
