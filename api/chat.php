<?php
session_start();

require_once __DIR__ . '/db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        http_response_code(500);
        header("Content-Type: application/json; charset=UTF-8");

        echo json_encode([
            "error" => "PHP Fatal Error",
            "message" => $error["message"],
            "file" => basename($error["file"]),
            "line" => $error["line"]
        ], JSON_UNESCAPED_UNICODE);
    }
});

function envValue($key, $default = null) {
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

function getModelFallbackChain() {
    return [
        [
            "name" => "Gemini 3 Flash",
            "model" => envValue("GEMINI_MODEL_GEMINI_3_FLASH", "gemini-3-flash-preview"),
            "supports_images" => true
        ],
        [
            "name" => "Gemini 3.1 Flash Lite",
            "model" => envValue("GEMINI_MODEL_GEMINI_3_1_FLASH_LITE", "gemini-3.1-flash-lite"),
            "supports_images" => true
        ],
        [
            "name" => "Gemini 2.5 Flash",
            "model" => envValue("GEMINI_MODEL_GEMINI_2_5_FLASH", "gemini-2.5-flash"),
            "supports_images" => true
        ],
        [
            "name" => "Gemini 2.5 Flash Lite",
            "model" => envValue("GEMINI_MODEL_GEMINI_2_5_FLASH_LITE", "gemini-2.5-flash-lite"),
            "supports_images" => true
        ],
        [
            "name" => "Gemma 4 31B",
            "model" => envValue("GEMINI_MODEL_GEMMA_4_31B", ""),
            "supports_images" => false
        ],
        [
            "name" => "Gemma 4 26B",
            "model" => envValue("GEMINI_MODEL_GEMMA_4_26B", ""),
            "supports_images" => false
        ]
    ];
}

function requestContainsImage($parts) {
    foreach ($parts as $part) {
        if (!is_array($part)) {
            continue;
        }

        if (isset($part["inline_data"]) || isset($part["file_data"])) {
            return true;
        }

        foreach ($part as $value) {
            if (is_array($value) && requestContainsImage([$value])) {
                return true;
            }
        }
    }

    return false;
}

function parseHttpStatus($headers) {
    $status = null;

    if (!is_array($headers)) {
        return null;
    }

    foreach ($headers as $header) {
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $status = (int)$matches[1];
        }
    }

    return $status;
}

function normalizeGeminiApiError($response, $statusCode, $raw = "") {
    $error = $response["error"] ?? [];
    $message = $error["message"] ?? "Gemini API error";
    $code = $error["code"] ?? $statusCode;
    $status = $error["status"] ?? "";

    return [
        "reason" => "api_error",
        "http_status" => $statusCode ?: (is_numeric($code) ? (int)$code : null),
        "api_status" => $status,
        "message" => $message,
        "raw" => $raw
    ];
}

function shouldStopFallback($error) {
    $message = strtolower($error["message"] ?? "");
    $status = (int)($error["http_status"] ?? 0);
    $apiStatus = strtoupper($error["api_status"] ?? "");
    $reason = $error["reason"] ?? "";

    if ($reason === "safety_block") {
        return true;
    }

    if (in_array($status, [400, 401, 403], true) && !preg_match('/quota|rate limit|overloaded|unavailable|temporar/i', $message)) {
        return true;
    }

    if (in_array($apiStatus, ["UNAUTHENTICATED", "PERMISSION_DENIED", "FAILED_PRECONDITION"], true)) {
        return true;
    }

    return preg_match('/api key|permission|forbidden|unauthorized|blocked|safety|policy|invalid key/i', $message) === 1;
}

function shouldFallback($error) {
    if (shouldStopFallback($error)) {
        return false;
    }

    $status = (int)($error["http_status"] ?? 0);
    $reason = $error["reason"] ?? "";
    $message = $error["message"] ?? "";

    if (in_array($status, [404, 408, 429, 500, 502, 503, 504], true)) {
        return true;
    }

    if (in_array($reason, ["transport_error", "timeout", "empty_response", "invalid_api_json", "empty_model_response"], true)) {
        return true;
    }

    return preg_match('/quota exceeded|quota|rate limit|overloaded|unavailable|timeout|temporar|try again|model.*not found|not found/i', $message) === 1;
}

function invokeGeminiModel($apiKey, $parts, $systemInstruction, $timeout, $modelConfig) {
    $modelName = $modelConfig["model"];
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key=" . urlencode($apiKey);

    $payload = [
        "contents" => [
            [
                "parts" => $parts
            ]
        ]
    ];

    if ($systemInstruction !== null && trim($systemInstruction) !== '') {
        $payload["systemInstruction"] = [
            "parts" => [
                ["text" => $systemInstruction]
            ]
        ];
    }

    $options = [
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json\r\n",
            "content" => json_encode($payload, JSON_UNESCAPED_UNICODE),
            "ignore_errors" => true,
            "timeout" => $timeout
        ]
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    $headers = isset($http_response_header) ? $http_response_header : [];
    $httpStatus = parseHttpStatus($headers);

    if ($result === false) {
        $lastError = error_get_last();
        $message = $lastError["message"] ?? "Request to Gemini failed";

        return [
            "ok" => false,
            "error" => [
                "reason" => stripos($message, "timed out") !== false ? "timeout" : "transport_error",
                "http_status" => $httpStatus,
                "message" => $message
            ]
        ];
    }

    if (trim($result) === "") {
        return [
            "ok" => false,
            "error" => [
                "reason" => "empty_response",
                "http_status" => $httpStatus,
                "message" => "Gemini returned an empty response"
            ]
        ];
    }

    $response = json_decode($result, true);

    if (!is_array($response)) {
        return [
            "ok" => false,
            "error" => [
                "reason" => "invalid_api_json",
                "http_status" => $httpStatus,
                "message" => "Gemini did not return valid JSON",
                "raw" => substr($result, 0, 500)
            ]
        ];
    }

    if (isset($response["error"])) {
        return [
            "ok" => false,
            "error" => normalizeGeminiApiError($response, $httpStatus, substr($result, 0, 500))
        ];
    }

    $promptBlockReason = $response["promptFeedback"]["blockReason"] ?? null;
    $finishReason = $response["candidates"][0]["finishReason"] ?? null;

    if ($promptBlockReason || $finishReason === "SAFETY") {
        return [
            "ok" => false,
            "error" => [
                "reason" => "safety_block",
                "http_status" => $httpStatus,
                "message" => "Gemini response was blocked by safety policy"
            ]
        ];
    }

    $replyText = $response["candidates"][0]["content"]["parts"][0]["text"] ?? null;

    if (!$replyText || trim($replyText) === "") {
        return [
            "ok" => false,
            "error" => [
                "reason" => "empty_model_response",
                "http_status" => $httpStatus,
                "message" => "No reply returned from Gemini"
            ]
        ];
    }

    return [
        "ok" => true,
        "text" => trim($replyText)
    ];
}

function friendlyGeminiFailureResponse($errorCode = "all_models_failed") {
    $conversationId = $GLOBALS["currentConversationId"] ?? null;

    jsonResponse([
        "success" => false,
        "reply" => "לא הצלחנו לקבל תשובה כרגע. אפשר לנסות שוב בעוד רגע.",
        "assistant_message" => "לא הצלחנו לקבל תשובה כרגע. אפשר לנסות שוב בעוד רגע.",
        "error" => $errorCode,
        "conversation_id" => $conversationId,
        "used_model" => null,
        "fallback_used" => true,
        "conversation_status" => "open",
        "disable_input" => false,
        "report_created" => false
    ]);
}

function callGemini($apiKey, $parts, $systemInstruction = null, $timeout = 60) {
    $requiresImageSupport = requestContainsImage($parts);
    $allModels = getModelFallbackChain();
    $eligibleModels = [];

    foreach ($allModels as $modelConfig) {
        if (trim($modelConfig["model"] ?? "") === "") {
            error_log("GEMINI FALLBACK: skipping " . $modelConfig["name"] . "; model id is not configured");
            continue;
        }

        if ($requiresImageSupport && empty($modelConfig["supports_images"])) {
            error_log("GEMINI FALLBACK: skipping " . $modelConfig["name"] . "; image request is not supported by this model");
            continue;
        }

        $eligibleModels[] = $modelConfig;
    }

    if (empty($eligibleModels)) {
        error_log("GEMINI FALLBACK: no eligible models configured");
        friendlyGeminiFailureResponse("no_eligible_models");
    }

    $firstModel = $eligibleModels[0];
    $fallbackUsed = false;
    $attemptNumber = 0;

    foreach ($eligibleModels as $modelConfig) {
        $attemptNumber++;
        error_log("GEMINI FALLBACK: trying " . $modelConfig["name"] . " (" . $modelConfig["model"] . "), attempt " . $attemptNumber);

        $result = invokeGeminiModel($apiKey, $parts, $systemInstruction, $timeout, $modelConfig);

        if (!empty($result["ok"])) {
            $GLOBALS["lastGeminiModelMeta"] = [
                "used_model" => $modelConfig["name"],
                "fallback_used" => $fallbackUsed || $attemptNumber > 1
            ];
            error_log("GEMINI FALLBACK: success with " . $modelConfig["name"]);
            return $result["text"];
        }

        $error = $result["error"] ?? ["message" => "Unknown Gemini error"];
        $statusText = isset($error["http_status"]) ? " HTTP " . $error["http_status"] : "";
        error_log("GEMINI FALLBACK: " . $modelConfig["name"] . " failed" . $statusText . ": " . ($error["message"] ?? "Unknown error"));

        if (!shouldFallback($error)) {
            error_log("GEMINI FALLBACK: stopping without fallback due to non-transient error");
            friendlyGeminiFailureResponse("gemini_request_failed");
        }

        $fallbackUsed = true;
        error_log("GEMINI FALLBACK: falling back to next model");
    }

    error_log("GEMINI FALLBACK: all eligible models failed; final retry with " . $firstModel["name"]);
    $finalResult = invokeGeminiModel($apiKey, $parts, $systemInstruction, $timeout, $firstModel);

    if (!empty($finalResult["ok"])) {
        $GLOBALS["lastGeminiModelMeta"] = [
            "used_model" => $firstModel["name"],
            "fallback_used" => true
        ];
        error_log("GEMINI FALLBACK: final retry succeeded with " . $firstModel["name"]);
        return $finalResult["text"];
    }

    $finalError = $finalResult["error"] ?? ["message" => "Unknown Gemini error"];
    error_log("GEMINI FALLBACK: final retry failed: " . ($finalError["message"] ?? "Unknown error"));
    friendlyGeminiFailureResponse("all_models_failed");
}

function parseJsonFromAi($text, $errorTitle = "Failed to parse AI JSON") {
    $clean = trim($text);
    $clean = preg_replace('/^```json\s*/u', '', $clean);
    $clean = preg_replace('/^```\s*/u', '', $clean);
    $clean = preg_replace('/\s*```$/u', '', $clean);
    $clean = trim($clean);

    $parsed = json_decode($clean, true);

    if (!is_array($parsed)) {
        jsonResponse([
            "error" => $errorTitle,
            "raw" => $text
        ], 500);
    }

    return $parsed;
}

function generateConversationId() {
    return "chat_" . date("YmdHis") . "_" . substr(str_replace(".", "", uniqid("", true)), -10);
}

function normalizeConversationId($conversationId) {
    $conversationId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$conversationId);

    return $conversationId !== "" ? $conversationId : generateConversationId();
}

function requestConversationId() {
    $conversationId = isset($_POST["conversation_id"]) ? $_POST["conversation_id"] : "";

    return normalizeConversationId($conversationId);
}

function defaultConversationState($conversationId = null) {
    $conversationId = normalizeConversationId($conversationId);

    return [
        "conversation_id" => $conversationId,
        "stage" => "awaiting_description",
        "description" => null,
        "pending_description" => null,
        "proposed_description" => null,
        "category" => null,
        "urgency" => null,
        "additional_details" => null,
        "image_text_analysis" => null,
        "pending_image_analysis" => null,
        "emergency_notice_shown" => false,
        "closed" => false,
        "report_id" => null
    ];
}

function getConversationState($conversationId = null) {
    $conversationId = normalizeConversationId($conversationId);

    if (!isset($_SESSION["report_conversations"]) || !is_array($_SESSION["report_conversations"])) {
        $_SESSION["report_conversations"] = [];
    }

    if (!isset($_SESSION["report_conversations"][$conversationId]) || !is_array($_SESSION["report_conversations"][$conversationId])) {
        $_SESSION["report_conversations"][$conversationId] = defaultConversationState($conversationId);
    }

    $_SESSION["active_report_conversation_id"] = $conversationId;

    return array_merge(
        defaultConversationState($conversationId),
        $_SESSION["report_conversations"][$conversationId],
        ["conversation_id" => $conversationId]
    );
}

function saveConversationState($state) {
    $conversationId = normalizeConversationId($state["conversation_id"] ?? null);
    $state["conversation_id"] = $conversationId;

    if (!isset($_SESSION["report_conversations"]) || !is_array($_SESSION["report_conversations"])) {
        $_SESSION["report_conversations"] = [];
    }

    $_SESSION["report_conversations"][$conversationId] = array_merge(defaultConversationState($conversationId), $state);
    $_SESSION["active_report_conversation_id"] = $conversationId;
}

function basePayload($assistantMessage, $state, $extra = []) {
    $isClosed = !empty($state["closed"]);

    $payload = array_merge([
        "success" => true,
        "reply" => $assistantMessage,
        "assistant_message" => $assistantMessage,
        "conversation_id" => $state["conversation_id"] ?? ($GLOBALS["currentConversationId"] ?? null),
        "conversation_status" => $isClosed ? "closed" : "open",
        "disable_input" => $isClosed,
        "report_created" => false
    ], $extra);

    if (isset($GLOBALS["lastGeminiModelMeta"]) && is_array($GLOBALS["lastGeminiModelMeta"])) {
        if (!array_key_exists("used_model", $payload)) {
            $payload["used_model"] = $GLOBALS["lastGeminiModelMeta"]["used_model"] ?? null;
        }

        if (!array_key_exists("fallback_used", $payload)) {
            $payload["fallback_used"] = $GLOBALS["lastGeminiModelMeta"]["fallback_used"] ?? false;
        }
    }

    return $payload;
}

function respond($assistantMessage, $state, $extra = []) {
    saveConversationState($state);
    jsonResponse(basePayload($assistantMessage, $state, $extra));
}

function isAffirmative($text) {
    return preg_match('/\b(כן|נכון|מדויק|מאשר|מאשרת|מאושר|בסדר|תקין|בהחלט|זה נכון|זה מדויק)\b/u', trim($text)) === 1;
}

function isNegativeOnly($text) {
    $text = trim($text);
    return preg_match('/^(לא|לא נכון|לא מדויק|צריך לתקן|תיקון)$/u', $text) === 1;
}

function addAdditionalDetail(&$state, $detail) {
    $detail = trim($detail);

    if ($detail === '') {
        return;
    }

    if (empty($state["additional_details"])) {
        $state["additional_details"] = $detail;
        return;
    }

    if (strpos($state["additional_details"], $detail) === false) {
        $state["additional_details"] .= "\n" . $detail;
    }
}

function emergencyNotice() {
    return "חשוב לשים לב: הדיווח במערכת אינו מחליף טיפול חירום. אם מדובר בסכנה מיידית לחיים או לבריאות, יש לפנות מיד לגורם חירום מתאים, כמו מד״א 101, משטרה 100, כבאות 102 או רכז העמותה.";
}

function maybeEmergencyPrefix(&$state, $hasEmergency) {
    if (!$hasEmergency || !empty($state["emergency_notice_shown"])) {
        return "";
    }

    $state["emergency_notice_shown"] = true;
    return emergencyNotice() . "\n\n";
}

function normalizeUrgency($apiKey, $message) {
    $message = trim($message);

    if ($message === '') {
        return null;
    }

    if (preg_match('/(דחוף מאוד|חייבים|עכשיו|מיידי|מידי|סכנה|קריטי|חמור|בהול|היום)/u', $message)) {
        return "גבוהה";
    }

    if (preg_match('/(לא דחוף|לא ממהר|בהמשך|כשאפשר|אפשר לטפל בהמשך|נמוכה)/u', $message)) {
        return "נמוכה";
    }

    if (preg_match('/(בינוני|בינונית|בקרוב|די דחוף|כדאי לטפל|השבוע)/u', $message)) {
        return "בינונית";
    }

    if (preg_match('/(דחוף)/u', $message)) {
        return "גבוהה";
    }

    $instruction = "סווג רמת דחיפות של דיווח על צורך של קשיש.
ענה רק ב-JSON תקין בלי טקסט נוסף.

הערכים היחידים המותרים:
- נמוכה
- בינונית
- גבוהה
- unclear

פורמט:
{\"urgency\":\"נמוכה|בינונית|גבוהה|unclear\"}

כללים:
- \"לא דחוף\", \"אפשר בהמשך\" = נמוכה
- \"כדאי לטפל בקרוב\" = בינונית
- \"דחוף מאוד\", \"חייבים עכשיו\" = גבוהה
- אם אי אפשר להבין, החזר unclear";

    $reply = callGemini($apiKey, [["text" => $message]], $instruction, 25);
    $parsed = parseJsonFromAi($reply, "Failed to parse urgency JSON");
    $urgency = $parsed["urgency"] ?? "unclear";

    return in_array($urgency, ["נמוכה", "בינונית", "גבוהה"], true) ? $urgency : null;
}

function analyzeReportText($apiKey, $message, $context = "") {
    $instruction = "אתה צ׳אטבוט של מערכת \"נקודות חיבור\".
המתנדב כבר מזוהה, והקשיש כבר משויך אליו במערכת.
אסור לבקש שם מתנדב, שם קשיש, טלפון, תעודת זהות, פרטי קשר או פרטי התחברות.

המטרה: לנתח הודעת מתנדב על צורך או בעיה של קשיש, ולענות רק ב-JSON תקין.

פורמט:
{
  \"is_other_elder_request\": true/false,
  \"has_emergency_risk\": true/false,
  \"is_clear_description\": true/false,
  \"clarification_question\": \"...\",
  \"proposed_description\": \"...\",
  \"category\": \"...\",
  \"additional_details\": \"...\",
  \"should_confirm_rewrite\": true/false
}

כללים:
- אם המתנדב מבקש לדווח על קשיש אחר שאינו משויך אליו, is_other_elder_request=true
- תיאור כללי כמו \"הוא לא מרגיש טוב\" או \"יש בעיה בבית\" אינו מספיק ברור
- אם התיאור לא ברור, כתוב clarification_question קצרה אחת בלבד
- proposed_description יהיה ניסוח מקצועי, קצר וברור של הבעיה
- category היא קטגוריה דינמית קצרה בעברית, למשל מחסור במזון, בדידות, בעיה רפואית, בעיית תחזוקה בבית, בטיחות, אחר
- אם יש סכנת חיים או מצב חירום מיידי, has_emergency_risk=true
- אל תמליץ על עמותה ואל תבצע ניתוב";

    $parts = [];

    if (trim($context) !== '') {
        $parts[] = ["text" => "הקשר קודם:\n" . $context];
    }

    $parts[] = ["text" => "הודעת המתנדב:\n" . $message];

    $reply = callGemini($apiKey, $parts, $instruction, 35);
    $parsed = parseJsonFromAi($reply, "Failed to parse report analysis JSON");

    return [
        "is_other_elder_request" => !empty($parsed["is_other_elder_request"]),
        "has_emergency_risk" => !empty($parsed["has_emergency_risk"]),
        "is_clear_description" => !empty($parsed["is_clear_description"]),
        "clarification_question" => trim($parsed["clarification_question"] ?? ""),
        "proposed_description" => trim($parsed["proposed_description"] ?? ""),
        "category" => trim($parsed["category"] ?? "אחר"),
        "additional_details" => trim($parsed["additional_details"] ?? ""),
        "should_confirm_rewrite" => !empty($parsed["should_confirm_rewrite"])
    ];
}

function analyzeImage($apiKey, $message, $imageTmpPath, $imageMimeType) {
    $rawImage = file_get_contents($imageTmpPath);

    if ($rawImage === false) {
        jsonResponse(["error" => "Failed to read uploaded image"], 500);
    }

    $instruction = "אתה עוזר לנתח תמונה שצורפה לדיווח על צורך של קשיש.
התמונה עצמה לא נשמרת. יש לייצר רק ניתוח טקסטואלי קצר.
כתוב בעברית, בצורה זהירה ולא נחרצת מדי.
הסבר מה נראה בתמונה ומה הבעיה האפשרית.
אל תבקש פרטים אישיים.
סיים בשאלה: האם זה מתאר נכון את הבעיה?";

    $parts = [];

    if (trim($message) !== '') {
        $parts[] = ["text" => "טקסט שכתב המתנדב לצד התמונה:\n" . $message];
    }

    $parts[] = [
        "inline_data" => [
            "mime_type" => $imageMimeType,
            "data" => base64_encode($rawImage)
        ]
    ];

    return callGemini($apiKey, $parts, $instruction, 60);
}

function resolveImageConfirmation($apiKey, $imageAssessment, $volunteerReply) {
    $instruction = "אתה מקבל ניתוח תמונה קודם ותגובת מתנדב.
המטרה היא להבין האם המתנדב אישר את הניתוח, תיקן אותו, או שהתגובה לא ברורה.
ענה רק ב-JSON תקין.

פורמט:
{
  \"status\": \"confirmed_or_corrected\" | \"unclear\",
  \"final_image_text_analysis\": \"...\",
  \"followup_question\": \"...\"
}

כללים:
- אם המתנדב מאשר, final_image_text_analysis יהיה הניתוח הקודם
- אם הוא מתקן או מוסיף, שלב את התיקון שלו לניסוח מסודר
- אם לא ברור, status=unclear ושאל שאלה קצרה
- אין לשמור או להזכיר קובץ תמונה, רק טקסט";

    $reply = callGemini(
        $apiKey,
        [
            ["text" => "ניתוח קודם:\n" . $imageAssessment],
            ["text" => "תגובת המתנדב:\n" . $volunteerReply]
        ],
        $instruction,
        30
    );

    return parseJsonFromAi($reply, "Failed to parse image confirmation JSON");
}

function buildSummaryMessage($state) {
    $summary = "סיכום לפני יצירת הדיווח:\n\n";
    $summary .= "תיאור המקרה: " . ($state["description"] ?: "לא נמסר") . "\n";
    $summary .= "קטגוריה: " . ($state["category"] ?: "אחר") . "\n";
    $summary .= "רמת דחיפות: " . ($state["urgency"] ?: "לא נמסרה") . "\n";
    $summary .= "פירוט נוסף: " . (!empty($state["additional_details"]) ? $state["additional_details"] : "אין") . "\n\n";
    $summary .= "אם הסיכום מדויק, אפשר לאשר וליצור דיווח.";

    return $summary;
}

function summaryActions() {
    return [
        ["label" => "אישור ויצירת דיווח", "action" => "confirm_report", "variant" => "primary"],
        ["label" => "עריכת תיאור", "action" => "edit_description", "variant" => "secondary"],
        ["label" => "שינוי דחיפות", "action" => "change_urgency", "variant" => "secondary"],
        ["label" => "ביטול", "action" => "cancel", "variant" => "ghost"]
    ];
}

function respondWithSummary($state) {
    $state["stage"] = "awaiting_summary_confirmation";
    respond(buildSummaryMessage($state), $state, [
        "actions" => summaryActions(),
        "disable_input" => false,
        "conversation_status" => "open"
    ]);
}

function getCurrentVolunteerId() {
    if (!empty($_SESSION["volunteer_id"])) {
        return (int)$_SESSION["volunteer_id"];
    }

    if (!empty($_SESSION["user"]["volunteer_id"])) {
        return (int)$_SESSION["user"]["volunteer_id"];
    }

    return 1;
}

function getAssignedElderlyForVolunteer($pdo, $volunteerId) {
    if (!empty($_SESSION["elderly_id"])) {
        $stmt = $pdo->prepare("
            SELECT e.*
            FROM elderly e
            JOIN volunteer_elderly_assignments vea ON vea.elderly_id = e.id
            WHERE e.id = :elderly_id
              AND vea.volunteer_id = :volunteer_id
            LIMIT 1
        ");

        $stmt->execute([
            ':elderly_id' => (int)$_SESSION["elderly_id"],
            ':volunteer_id' => $volunteerId
        ]);

        $elderly = $stmt->fetch();

        if ($elderly) {
            return $elderly;
        }
    }

    $stmt = $pdo->prepare("
        SELECT e.*
        FROM elderly e
        JOIN volunteer_elderly_assignments vea ON vea.elderly_id = e.id
        WHERE vea.volunteer_id = :volunteer_id
        ORDER BY e.id ASC
        LIMIT 1
    ");

    $stmt->execute([
        ':volunteer_id' => $volunteerId
    ]);

    return $stmt->fetch();
}

function saveReport($pdo, $volunteerId, $elderlyId, $content, $urgency) {
    $stmt = $pdo->prepare("
        INSERT INTO reports
        (volunteer_id, elderly_id, content, urgency, status, classification_source, created_at)
        VALUES
        (:volunteer_id, :elderly_id, :content, :urgency, :status, :classification_source, NOW())
    ");

    $stmt->execute([
        ':volunteer_id' => $volunteerId,
        ':elderly_id' => $elderlyId,
        ':content' => $content,
        ':urgency' => $urgency,
        ':status' => 'חדש',
        ':classification_source' => 'AI'
    ]);

    return $pdo->lastInsertId();
}

function createReportFromState($pdo, $state, $volunteerId) {
    $elderly = getAssignedElderlyForVolunteer($pdo, $volunteerId);

    if (!$elderly) {
        throw new RuntimeException("No assigned elderly found for volunteer");
    }

    $content = "תיאור המקרה: " . ($state["description"] ?? "");
    $content .= "\nקטגוריה: " . ($state["category"] ?? "אחר");
    $content .= "\nרמת דחיפות: " . ($state["urgency"] ?? "");
    $content .= "\nפירוט נוסף: " . (!empty($state["additional_details"]) ? $state["additional_details"] : "אין");

    if (!empty($state["image_text_analysis"])) {
        $content .= "\nניתוח טקסטואלי שאושר מתמונה: " . $state["image_text_analysis"];
    }

    return saveReport(
        $pdo,
        $volunteerId,
        $elderly["id"],
        $content,
        $state["urgency"] ?? "בינונית"
    );
}

function askForUrgency($state, $prefix = "") {
    $state["stage"] = "awaiting_urgency";
    respond($prefix . "מה רמת הדחיפות של המקרה? אפשר לענות חופשי, למשל: לא דחוף, כדאי לטפל בקרוב, או דחוף מאוד.", $state);
}

function handleClearDescription($analysis, $state) {
    $state["category"] = $analysis["category"] ?: "אחר";
    addAdditionalDetail($state, $analysis["additional_details"]);

    $proposedDescription = $analysis["proposed_description"] ?: $state["pending_description"];
    $state["proposed_description"] = $proposedDescription;
    $state["stage"] = "awaiting_description_approval";

    $prefix = maybeEmergencyPrefix($state, $analysis["has_emergency_risk"]);
    $message = $prefix . "ניסחתי את הדיווח כך:\n\"" . $proposedDescription . "\"\n\nהאם זה מדויק מבחינתך?";

    respond($message, $state);
}

function processDescriptionMessage($apiKey, $message, $state) {
    $context = "";

    if (preg_match('/(קשיש אחר|קשישה אחרת|מישהו אחר|מישהי אחרת|לא משויך|לא משויכת|לא הקשיש שלי|לא הקשישה שלי)/u', $message)) {
        $state["stage"] = "awaiting_description";
        respond("אני מבין. כרגע ניתן לפתוח דיווח רק על קשיש שמשויך אליך במערכת. אם מדובר בקשיש אחר, יש לפנות לרכז כדי לפתוח תיק חדש או לשייך אותו אליך.", $state);
    }

    if (!empty($state["pending_description"])) {
        $context .= "תיאור קודם לא מלא:\n" . $state["pending_description"] . "\n";
    }

    if (!empty($state["image_text_analysis"])) {
        $context .= "ניתוח תמונה שאושר:\n" . $state["image_text_analysis"] . "\n";
    }

    $analysis = analyzeReportText($apiKey, $message, $context);

    if ($analysis["is_other_elder_request"]) {
        $state["stage"] = "awaiting_description";
        respond("אני מבין. כרגע ניתן לפתוח דיווח רק על קשיש שמשויך אליך במערכת. אם מדובר בקשיש אחר, יש לפנות לרכז כדי לפתוח תיק חדש או לשייך אותו אליך.", $state);
    }

    if (!$analysis["is_clear_description"]) {
        $state["stage"] = "needs_clarification";
        $state["pending_description"] = trim(($state["pending_description"] ? $state["pending_description"] . "\n" : "") . $message);
        $prefix = maybeEmergencyPrefix($state, $analysis["has_emergency_risk"]);
        $question = $analysis["clarification_question"] ?: "תוכל לפרט קצת יותר מה בדיוק קרה או מה הקושי המרכזי?";
        respond($prefix . $question, $state);
    }

    $state["pending_description"] = trim(($state["pending_description"] ? $state["pending_description"] . "\n" : "") . $message);
    handleClearDescription($analysis, $state);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(["error" => "Method not allowed"], 405);
}

$geminiApiKey = envValue('GEMINI_API_KEY');

if (!$geminiApiKey) {
    jsonResponse(["error" => "Missing API Key"], 500);
}

$conversationId = requestConversationId();
$GLOBALS["currentConversationId"] = $conversationId;
$currentVolunteerId = getCurrentVolunteerId();
$state = getConversationState($conversationId);
$message = trim(isset($_POST["message"]) ? $_POST["message"] : "");
$action = trim(isset($_POST["action"]) ? $_POST["action"] : "");
$imageUploaded = isset($_FILES["image"]) && $_FILES["image"]["error"] === UPLOAD_ERR_OK;

if (!empty($state["closed"])) {
    jsonResponse(basePayload(
        "השיחה הסתיימה לאחר יצירת הדיווח. כדי להמשיך יש לפתוח צ׳אט חדש.",
        $state,
        [
            "disable_input" => true,
            "report_created" => true,
            "report_id" => $state["report_id"] ?? null
        ]
    ));
}

if ($action !== "") {
    if ($action === "confirm_report") {
        if (empty($state["description"]) || empty($state["urgency"])) {
            respond("חסר מידע חובה לפני יצירת הדיווח. נמשיך להשלים אותו בקצרה.", $state);
        }

        try {
            $reportId = createReportFromState($pdo, $state, $currentVolunteerId);
            $state["closed"] = true;
            $state["stage"] = "closed";
            $state["report_id"] = (string)$reportId;
            saveConversationState($state);

            jsonResponse(basePayload(
                "תודה רבה, הדיווח נוצר בהצלחה. ניתן לעקוב אחרי הסטטוס שלו במסך הייעודי.",
                $state,
                [
                    "conversation_status" => "closed",
                    "disable_input" => true,
                    "report_created" => true,
                    "report_id" => (string)$reportId
                ]
            ));
        } catch (Throwable $e) {
            error_log("REPORT CREATE ERROR: " . $e->getMessage());
            $state["closed"] = false;
            saveConversationState($state);

            jsonResponse(basePayload(
                "לא הצלחנו ליצור את הדיווח כרגע. אפשר לנסות שוב בעוד רגע.",
                $state,
                [
                    "success" => false,
                    "conversation_status" => "open",
                    "disable_input" => false,
                    "report_created" => false,
                    "actions" => summaryActions()
                ]
            ), 500);
        }
    }

    if ($action === "edit_description") {
        $state["stage"] = "awaiting_description";
        $state["description"] = null;
        $state["pending_description"] = null;
        $state["proposed_description"] = null;
        respond("בסדר. כתוב לי את התיאור המתוקן של המקרה, ואני אדייק את הדיווח.", $state);
    }

    if ($action === "change_urgency") {
        $state["stage"] = "awaiting_urgency";
        $state["urgency"] = null;
        respond("מה רמת הדחיפות המעודכנת? אפשר לכתוב חופשי, למשל לא דחוף, כדאי לטפל בקרוב או דחוף מאוד.", $state);
    }

    if ($action === "cancel") {
        $state = defaultConversationState($conversationId);
        respond("ביטלתי את הסיכום. אם תרצה לפתוח דיווח חדש, כתוב לי בקצרה מה קרה.", $state);
    }

    jsonResponse(["error" => "Unknown action"], 400);
}

if (!$imageUploaded && $message === "") {
    jsonResponse(["error" => "No message or image provided"], 400);
}

if ($imageUploaded) {
    $imageTmpPath = $_FILES["image"]["tmp_name"];

    if (!is_uploaded_file($imageTmpPath)) {
        jsonResponse(["error" => "Uploaded image tmp file missing"], 500);
    }

    $imageMimeType = !empty($_FILES["image"]["type"]) ? $_FILES["image"]["type"] : "image/jpeg";
    $imageAssessment = analyzeImage($geminiApiKey, $message, $imageTmpPath, $imageMimeType);

    $state["pending_image_analysis"] = $imageAssessment;
    $state["stage"] = "awaiting_image_confirmation";

    if ($message !== "") {
        addAdditionalDetail($state, "הערת המתנדב לצד התמונה: " . $message);
    }

    respond($imageAssessment, $state);
}

if ($state["stage"] === "awaiting_image_confirmation") {
    $resolution = resolveImageConfirmation($geminiApiKey, $state["pending_image_analysis"] ?: "", $message);
    $status = $resolution["status"] ?? "unclear";

    if ($status === "unclear") {
        $question = trim($resolution["followup_question"] ?? "");
        respond($question !== "" ? $question : "כדי לדייק, האם הניתוח של התמונה נכון או שיש משהו שצריך לתקן?", $state);
    }

    $finalImageText = trim($resolution["final_image_text_analysis"] ?? "");
    $state["image_text_analysis"] = $finalImageText ?: ($state["pending_image_analysis"] ?: "");
    $state["pending_image_analysis"] = null;
    addAdditionalDetail($state, "מידע שאושר מהתמונה: " . $state["image_text_analysis"]);

    if (empty($state["description"])) {
        $state["stage"] = "awaiting_description";
        respond("תודה, עכשיו כתוב לי בקצרה מה קרה או מה הצורך המרכזי.", $state);
    }

    if (empty($state["urgency"])) {
        askForUrgency($state, "תודה, עדכנתי את הדיווח לפי התמונה.\n\n");
    }

    respondWithSummary($state);
}

if ($state["stage"] === "awaiting_description_approval") {
    if (isAffirmative($message)) {
        $state["description"] = $state["proposed_description"] ?: $state["pending_description"];
        $state["pending_description"] = null;
        $state["proposed_description"] = null;

        if (empty($state["urgency"])) {
            askForUrgency($state, "תודה, עדכנתי את תיאור המקרה.\n\n");
        }

        respondWithSummary($state);
    }

    if (isNegativeOnly($message)) {
        $state["stage"] = "awaiting_description";
        respond("אין בעיה. כתוב לי את התיקון או הניסוח המדויק, ואעדכן את הדיווח.", $state);
    }

    $state["pending_description"] = null;
    $state["proposed_description"] = null;
    processDescriptionMessage($geminiApiKey, $message, $state);
}

if ($state["stage"] === "awaiting_urgency") {
    $urgency = normalizeUrgency($geminiApiKey, $message);

    if ($urgency === null) {
        respond("לא הצלחתי להבין את רמת הדחיפות. האם היא נמוכה, בינונית או גבוהה?", $state);
    }

    $state["urgency"] = $urgency;
    respondWithSummary($state);
}

if ($state["stage"] === "awaiting_summary_confirmation") {
    respond("כדי להמשיך, אפשר להשתמש בכפתורים: אישור ויצירת דיווח, עריכת תיאור, שינוי דחיפות או ביטול.", $state, [
        "actions" => summaryActions()
    ]);
}

if ($state["stage"] === "needs_clarification") {
    $combined = trim(($state["pending_description"] ? $state["pending_description"] . "\n" : "") . $message);
    $state["pending_description"] = null;
    processDescriptionMessage($geminiApiKey, $combined, $state);
}

processDescriptionMessage($geminiApiKey, $message, $state);
