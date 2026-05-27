<?php
session_start();

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

function callGemini($apiKey, $parts, $systemInstruction = null, $timeout = 60) {
    #$modelName = "gemini-3.1-flash-lite-preview";
    $modelName = "gemini-3-flash-preview";
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
    error_log("GEMINI DEBUG: calling API");
    $result = @file_get_contents($url, false, $context);

    if ($result === false) {
        jsonResponse(["error" => "Request to Gemini failed"], 500);
    }

    $response = json_decode($result, true);

    if (!is_array($response)) {
        jsonResponse([
            "error" => "Gemini did not return valid JSON",
            "raw" => $result
        ], 500);
    }

    if (isset($response["error"])) {
        $msg = isset($response["error"]["message"]) ? $response["error"]["message"] : "Gemini API error";
        jsonResponse([
            "error" => $msg,
            "details" => $response
        ], 500);
    }

    $replyText = $response["candidates"][0]["content"]["parts"][0]["text"] ?? null;

    if (!$replyText) {
        jsonResponse([
            "error" => "No reply returned from Gemini",
            "details" => $response
        ], 500);
    }

    error_log("GEMINI DEBUG RESPONSE: " . $replyText);
    return $replyText;
}

function cleanImageAssessment($text) {
    $text = trim($text);

    $text = preg_replace('/\s*האם זו הערכה נכונה\?\s*האם יש משהו להוסיף או לתקן\?\s*$/u', '', $text);
    $text = preg_replace('/\s*האם זו הערכה נכונה\?\s*$/u', '', $text);

    return trim($text);
}

function resolveImageConfirmation($apiKey, $imageAssessment, $volunteerReply) {
    $instruction = "אתה מקבל:
1. הערכה קודמת שניתנה על סמך תמונה
2. תגובת מתנדב

המטרה:
להחליט האם תגובת המתנדב:
- מאשרת את ההערכה
- מתקנת או מוסיפה מידע
- לא ברורה ודורשת שאלה נוספת

ענה רק ב-JSON תקין בלבד, בלי טקסט נוסף.

פורמט:
{
  \"status\": \"confirmed_or_added\" | \"unclear\",
  \"final_problem\": \"...\",
  \"followup_question\": \"...\"
}

כללים:
- אם המתנדב מאשר, מפרגן, או כותב שאין מה לתקן, final_problem צריך להיות תיאור הבעיה הקודם
- אם המתנדב מוסיף או מתקן, final_problem צריך להיות ניסוח מסודר אחד שמבוסס על ההערכה הקודמת יחד עם התיקון או ההוספה
- אם התגובה לא ברורה בכלל, status יהיה unclear ותכתוב followup_question קצרה בעברית
- אל תחזיר null
- אל תכתוב הסברים מחוץ ל-JSON";

    $reply = callGemini(
        $apiKey,
        [
            ["text" => "הערכה קודמת:\n" . $imageAssessment],
            ["text" => "תגובת המתנדב:\n" . $volunteerReply]
        ],
        $instruction,
        30
    );

    $cleanReply = trim($reply);
    $cleanReply = preg_replace('/^```json\s*/u', '', $cleanReply);
    $cleanReply = preg_replace('/^```\s*/u', '', $cleanReply);
    $cleanReply = preg_replace('/\s*```$/u', '', $cleanReply);

    $parsed = json_decode($cleanReply, true);

    if (!is_array($parsed)) {
        jsonResponse([
            "error" => "Failed to parse image confirmation JSON",
            "raw" => $reply
        ], 500);
    }

    return $parsed;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(["error" => "Method not allowed"], 405);
}

$envPath = __DIR__ . '/../.env';
$geminiApiKey = null;

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || (strlen($line) > 0 && $line[0] === '#')) {
            continue;
        }

        if (strpos($line, 'GEMINI_API_KEY=') === 0) {
            $geminiApiKey = trim(substr($line, strlen('GEMINI_API_KEY=')));
            break;
        }
    }
}

if (!$geminiApiKey) {
    jsonResponse(["error" => "Missing API Key"], 500);
}

$message = trim(isset($_POST["message"]) ? $_POST["message"] : "");
$imageUploaded = isset($_FILES["image"]) && $_FILES["image"]["error"] === UPLOAD_ERR_OK;

if (!$imageUploaded && $message === "") {
    jsonResponse(["error" => "No message or image provided"], 400);
}

if (!isset($_SESSION["report_state"])) {
    $_SESSION["report_state"] = [
        "problem" => null,
        "elder_name" => null,
        "urgency" => null,
        "extra" => null,
        "waiting_image_confirmation" => false,
        "image_assessment" => null
    ];
}

$state = $_SESSION["report_state"];

/*
  1) אם הבוט מחכה לאישור/תיקון אחרי ניתוח תמונה,
  ההודעה הבאה של המתנדב תנותח לפי ההקשר של ההערכה הקודמת
*/
if (!empty($state["waiting_image_confirmation"]) && !$imageUploaded) {
    $resolution = resolveImageConfirmation(
        $geminiApiKey,
        $state["image_assessment"] ?: "",
        $message
    );

    $status = $resolution["status"] ?? "unclear";
    $finalProblem = trim($resolution["final_problem"] ?? "");
    $followupQuestion = trim($resolution["followup_question"] ?? "");

    if ($status === "unclear") {
        $_SESSION["report_state"] = $state;

        jsonResponse([
            "reply" => $followupQuestion !== "" ? $followupQuestion : "כדי לדייק, האם ההערכה על התמונה נכונה או שיש משהו שצריך לתקן?"
        ]);
    }

    $state["problem"] = $finalProblem !== "" ? $finalProblem : ($state["image_assessment"] ?: $message);
    $state["waiting_image_confirmation"] = false;
    $_SESSION["report_state"] = $state;

    if (empty($state["elder_name"])) {
        jsonResponse([
            "reply" => "תודה. מה שם הקשיש/ה?"
        ]);
    }

    if (empty($state["urgency"])) {
        jsonResponse([
            "reply" => "מה רמת הדחיפות?"
        ]);
    }

    $summary = "סיכום דיווח:\n\n";
    $summary .= "תיאור הבעיה: " . ($state["problem"] ?: "לא נמסר") . "\n";
    $summary .= "שם הקשיש: " . ($state["elder_name"] ?: "לא נמסר") . "\n";
    $summary .= "רמת דחיפות: " . ($state["urgency"] ?: "לא נמסר") . "\n";
    $summary .= "פרטים נוספים: " . ($state["extra"] ?: "אין") . "\n\n";
    $summary .= "הדיווח נאסף בהצלחה והפרטים הועברו להמשך טיפול.";

    unset($_SESSION["report_state"]);

    jsonResponse([
        "reply" => $summary
    ]);
}

/*
  2) אם צורפה תמונה - מנתחים אותה קודם, מחזירים הערכה ראשונית,
  ושואלים את המתנדב אם ההערכה נכונה ומה יש להוסיף או לתקן
*/
if ($imageUploaded) {
    $imageTmpPath = $_FILES["image"]["tmp_name"];

    error_log("IMAGE DEBUG: entered image branch");
    error_log("IMAGE DEBUG: tmp path = " . $imageTmpPath);

    if (!is_uploaded_file($imageTmpPath)) {
        jsonResponse(["error" => "Uploaded image tmp file missing"], 500);
    }

    $rawImage = file_get_contents($imageTmpPath);
    if ($rawImage === false) {
        jsonResponse(["error" => "Failed to read uploaded image"], 500);
    }

    $imageMimeType = 'image/jpeg';
    if (isset($_FILES["image"]["type"]) && !empty($_FILES["image"]["type"])) {
        $imageMimeType = $_FILES["image"]["type"];
    }

    $imageData = base64_encode($rawImage);

    $visionInstruction = "אתה עוזר לנתח תמונה שצורפה בדיווח על קשיש.
תן הערכה ראשונית קצרה וברורה למה שנראה בתמונה ולמה הבעיה האפשרית.
אל תכתוב בוודאות מוחלטת אם אינך בטוח.
אם יש גם טקסט מהמתנדב, התחשב בו.
ענה בעברית, בצורה אנושית וקצרה.
בסוף שאל בדיוק:
האם זו הערכה נכונה? האם יש משהו להוסיף או לתקן?";

    $visionParts = [];

    if ($message !== '') {
        $visionParts[] = ["text" => "הערת המתנדב: " . $message];
    }

    $visionParts[] = [
        "inline_data" => [
            "mime_type" => $imageMimeType,
            "data" => $imageData
        ]
    ];

    $visionReply = callGemini($geminiApiKey, $visionParts, $visionInstruction, 60);

    $state["image_assessment"] = cleanImageAssessment($visionReply);

    if ($message !== '') {
        if (empty($state["extra"])) {
            $state["extra"] = $message;
        } else {
            $state["extra"] .= " | " . $message;
        }
    }

    $state["waiting_image_confirmation"] = true;
    $_SESSION["report_state"] = $state;

    jsonResponse([
        "reply" => trim($visionReply)
    ]);
}

/*
  3) אם זו ההודעה הראשונה בשיחה ואין עדיין problem,
  מניחים שזו הבעיה כי כבר שאלת במסך:
  שלום! מה זיהית בשטח אצל הקשיש?
*/
if (empty($state["problem"])) {
    $state["problem"] = $message;
    $_SESSION["report_state"] = $state;

    jsonResponse([
        "reply" => "תודה על הדיווח. מה שם הקשיש/ה?"
    ]);
}

/*
  4) חילוץ שדות חסרים מההודעה הנוכחית
*/
$extractInstruction = "אתה מחלץ מידע מתוך הודעת מתנדב על קשיש.
ענה רק ב-JSON תקין בלבד בלי טקסט נוסף.

השדות האפשריים:
problem
elder_name
urgency
extra

כללים:
- אם שדה לא מופיע בהודעה, החזר null
- אל תמציא מידע
- elder_name = שם הקשיש בלבד
- urgency = אמור להיות מסווג לפי האופן שהוא בוחר, רק שישמע הגיוני
- extra = מידע נוסף שיכול לעזור

פורמט תשובה:
{
  \"problem\": null,
  \"elder_name\": null,
  \"urgency\": null,
  \"extra\": null
}";

$replyText = callGemini(
    $geminiApiKey,
    [
        ["text" => $message]
    ],
    $extractInstruction,
    30
);

$cleanReply = trim($replyText);
$cleanReply = preg_replace('/^```json\s*/u', '', $cleanReply);
$cleanReply = preg_replace('/^```\s*/u', '', $cleanReply);
$cleanReply = preg_replace('/\s*```$/u', '', $cleanReply);

$extracted = json_decode($cleanReply, true);

if (!is_array($extracted)) {
    jsonResponse([
        "error" => "Failed to parse extracted JSON",
        "raw" => $replyText
    ], 500);
}

/*
  5) עדכון state
*/
if (empty($state["elder_name"]) && !empty($extracted["elder_name"])) {
    $state["elder_name"] = trim($extracted["elder_name"]);
}

if (empty($state["urgency"]) && !empty($extracted["urgency"])) {
    $state["urgency"] = trim($extracted["urgency"]);
}

if (!empty($extracted["extra"])) {
    if (empty($state["extra"])) {
        $state["extra"] = trim($extracted["extra"]);
    } else {
        $state["extra"] .= " | " . trim($extracted["extra"]);
    }
}

$_SESSION["report_state"] = $state;

/*
  6) השרת מחליט מה לשאול הבא
*/
if (empty($state["elder_name"])) {
    jsonResponse([
        "reply" => "תודה. מה שם הקשיש/ה?"
    ]);
}

if (empty($state["urgency"])) {
    jsonResponse([
        "reply" => "מה רמת הדחיפות?"
    ]);
}

/*
  7) סיכום
*/
$summary = "סיכום דיווח:\n\n";
$summary .= "תיאור הבעיה: " . ($state["problem"] ?: "לא נמסר") . "\n";
$summary .= "שם הקשיש: " . ($state["elder_name"] ?: "לא נמסר") . "\n";
$summary .= "רמת דחיפות: " . ($state["urgency"] ?: "לא נמסר") . "\n";
$summary .= "פרטים נוספים: " . ($state["extra"] ?: "אין") . "\n\n";
$summary .= "הדיווח נאסף בהצלחה והפרטים הועברו להמשך טיפול.";

unset($_SESSION["report_state"]);

jsonResponse([
    "reply" => $summary
]);