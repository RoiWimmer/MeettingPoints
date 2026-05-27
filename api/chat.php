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

function callGemini($apiKey, $parts, $systemInstruction = null, $timeout = 60) {
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

    return parseJsonFromAi($reply, "Failed to parse image confirmation JSON");
}

/*
    חיפוש קשיש לפי שם, אבל רק מתוך קשישים שמשויכים למתנדב.
    זה מונע מצב שבו יש שתי מרים במערכת והקוד בוחר את הלא נכונה.
*/
function findElderlyByName($pdo, $elderName, $volunteerId) {
    $elderName = trim($elderName);

    $stmt = $pdo->prepare("
        SELECT e.*
        FROM elderly e
        JOIN volunteer_elderly_assignments vea
            ON vea.elderly_id = e.id
        WHERE vea.volunteer_id = :volunteer_id
          AND (
                CONCAT(e.first_name, ' ', e.last_name) LIKE :full_name
                OR e.first_name LIKE :name
                OR e.last_name LIKE :name
              )
        LIMIT 1
    ");

    $stmt->execute([
        ':volunteer_id' => $volunteerId,
        ':full_name' => '%' . $elderName . '%',
        ':name' => '%' . $elderName . '%'
    ]);

    return $stmt->fetch();
}

function getVolunteerUserId($pdo, $volunteerId) {
    $stmt = $pdo->prepare("
        SELECT user_id
        FROM volunteers
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $volunteerId
    ]);

    $row = $stmt->fetch();

    if ($row && !empty($row["user_id"])) {
        return $row["user_id"];
    }

    return 1;
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
        ':status' => 'הוגש',
        ':classification_source' => 'AI'
    ]);

    return $pdo->lastInsertId();
}

function getActiveCategories($pdo) {
    $stmt = $pdo->query("
        SELECT id, name, description
        FROM need_categories
        WHERE active = 1
        ORDER BY id ASC
    ");

    return $stmt->fetchAll();
}

function classifyReportByCategories($apiKey, $pdo, $content) {
    $categories = getActiveCategories($pdo);

    if (empty($categories)) {
        return [];
    }

    $categoryText = "";

    foreach ($categories as $category) {
        $categoryText .= $category["id"] . ". " . $category["name"] . " - " . $category["description"] . "\n";
    }

    $validIds = array_map(function ($category) {
        return (int)$category["id"];
    }, $categories);

    $instruction = "אתה מסווג דיווח על צורך של קשיש.
בחר רק מתוך הקטגוריות הנתונות.
ענה רק ב-JSON תקין בלבד, בלי טקסט נוסף.

פורמט:
{
  \"categories\": [
    {
      \"category_id\": 1,
      \"confidence_score\": 0.85
    }
  ]
}

כללים:
- אפשר לבחור קטגוריה אחת או יותר
- confidence_score הוא מספר בין 0 ל-1
- אל תמציא category_id שלא קיים ברשימה
- אם אין התאמה מושלמת, בחר את הקטגוריה הכי קרובה
- החזר מקסימום 3 קטגוריות";

    $reply = callGemini(
        $apiKey,
        [
            ["text" => "רשימת קטגוריות מותרות:\n" . $categoryText],
            ["text" => "הדיווח:\n" . $content]
        ],
        $instruction,
        30
    );

    $parsed = parseJsonFromAi($reply, "Failed to parse classification JSON");

    $selected = $parsed["categories"] ?? [];
    $cleanSelected = [];

    foreach ($selected as $item) {
        $categoryId = isset($item["category_id"]) ? (int)$item["category_id"] : 0;

        if (!in_array($categoryId, $validIds, true)) {
            continue;
        }

        $score = isset($item["confidence_score"]) ? (float)$item["confidence_score"] : 0.7;

        if ($score < 0) {
            $score = 0;
        }

        if ($score > 1) {
            $score = 1;
        }

        $cleanSelected[] = [
            "category_id" => $categoryId,
            "confidence_score" => $score
        ];
    }

    return $cleanSelected;
}

function saveReportCategories($pdo, $reportId, $categories) {
    if (empty($categories)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO report_categories
        (report_id, category_id, confidence_score)
        VALUES
        (:report_id, :category_id, :confidence_score)
    ");

    foreach ($categories as $category) {
        if (empty($category["category_id"])) {
            continue;
        }

        $stmt->execute([
            ':report_id' => $reportId,
            ':category_id' => $category["category_id"],
            ':confidence_score' => $category["confidence_score"] ?? 0.7
        ]);
    }
}

function getCategoryName($pdo, $categoryId) {
    $stmt = $pdo->prepare("
        SELECT name
        FROM need_categories
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $categoryId
    ]);

    $row = $stmt->fetch();

    return $row ? $row["name"] : "";
}

function findMatchingOrganization($pdo, $categoryId, $city) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT o.*, osa.city AS service_area_city
        FROM organizations o
        JOIN organization_categories oc
            ON oc.organization_id = o.id
        LEFT JOIN organization_service_areas osa
            ON osa.organization_id = o.id
        WHERE oc.category_id = :category_id
          AND o.active = 1
          AND (
                osa.city = :city
                OR osa.city = 'כל הארץ'
                OR o.city = :city
              )
        ORDER BY
            CASE
                WHEN osa.city = :city THEN 1
                WHEN o.city = :city THEN 2
                WHEN osa.city = 'כל הארץ' THEN 3
                ELSE 4
            END ASC
        LIMIT 1
    ");

    $stmt->execute([
        ':category_id' => $categoryId,
        ':city' => $city
    ]);

    $organization = $stmt->fetch();

    if ($organization) {
        return $organization;
    }

    /*
        fallback:
        אם אין עמותה באותה עיר, עדיין נחפש עמותה פעילה לפי קטגוריה.
    */
    $fallbackStmt = $pdo->prepare("
        SELECT DISTINCT o.*
        FROM organizations o
        JOIN organization_categories oc
            ON oc.organization_id = o.id
        WHERE oc.category_id = :category_id
          AND o.active = 1
        LIMIT 1
    ");

    $fallbackStmt->execute([
        ':category_id' => $categoryId
    ]);

    return $fallbackStmt->fetch();
}

function createReferral($pdo, $reportId, $organizationId, $elderlyId, $categoryId, $notes) {
    $stmt = $pdo->prepare("
        INSERT INTO referrals
        (report_id, organization_id, elderly_id, category_id, notes, status, created_date, expected_resolution_date, created_at)
        VALUES
        (:report_id, :organization_id, :elderly_id, :category_id, :notes, :status, CURDATE(), NULL, NOW())
    ");

    $stmt->execute([
        ':report_id' => $reportId,
        ':organization_id' => $organizationId,
        ':elderly_id' => $elderlyId,
        ':category_id' => $categoryId,
        ':notes' => $notes,
        ':status' => 'חדש'
    ]);

    return $pdo->lastInsertId();
}

function createReferralStatusHistory($pdo, $referralId, $changedByUserId) {
    $stmt = $pdo->prepare("
        INSERT INTO referral_status_history
        (referral_id, old_status, new_status, changed_by_user_id, notes, created_at)
        VALUES
        (:referral_id, :old_status, :new_status, :changed_by_user_id, :notes, NOW())
    ");

    $stmt->execute([
        ':referral_id' => $referralId,
        ':old_status' => null,
        ':new_status' => 'חדש',
        ':changed_by_user_id' => $changedByUserId,
        ':notes' => 'פניה נפתחה אוטומטית על ידי הצ׳אטבוט'
    ]);
}

function finalizeReportAndRespond($pdo, $geminiApiKey, $state, $volunteerId) {
    $elderName = trim($state["elder_name"] ?? "");

    $elderly = findElderlyByName($pdo, $elderName, $volunteerId);

    if (!$elderly) {
        $state["elder_name"] = null;
        $_SESSION["report_state"] = $state;

        jsonResponse([
            "reply" => "לא מצאתי קשיש/ה בשם \"" . $elderName . "\" שמשויך/ת למתנדב הזה. אפשר לכתוב שם מלא, למשל שם פרטי ושם משפחה?"
        ]);
    }

    $content = "תיאור הבעיה: " . ($state["problem"] ?? "לא נמסר");

    if (!empty($state["extra"])) {
        $content .= "\nפרטים נוספים: " . $state["extra"];
    }

    $content .= "\nשם הקשיש/ה: " . $elderly["first_name"] . " " . $elderly["last_name"];
    $content .= "\nעיר: " . $elderly["city"];

    $reportId = saveReport(
        $pdo,
        $volunteerId,
        $elderly["id"],
        $content,
        $state["urgency"] ?? "לא נמסר"
    );

    $categories = classifyReportByCategories($geminiApiKey, $pdo, $content);

    saveReportCategories($pdo, $reportId, $categories);

    $organization = null;
    $referralId = null;
    $selectedCategoryName = "";

    foreach ($categories as $category) {
        $categoryId = $category["category_id"] ?? null;

        if (!$categoryId) {
            continue;
        }

        $foundOrganization = findMatchingOrganization($pdo, $categoryId, $elderly["city"]);

        if ($foundOrganization) {
            $organization = $foundOrganization;
            $selectedCategoryName = getCategoryName($pdo, $categoryId);

            $referralId = createReferral(
                $pdo,
                $reportId,
                $organization["id"],
                $elderly["id"],
                $categoryId,
                $content
            );

            $changedByUserId = getVolunteerUserId($pdo, $volunteerId);
            createReferralStatusHistory($pdo, $referralId, $changedByUserId);

            break;
        }
    }

    $categoryNames = [];

    foreach ($categories as $category) {
        if (!empty($category["category_id"])) {
            $name = getCategoryName($pdo, $category["category_id"]);

            if ($name !== "") {
                $categoryNames[] = $name;
            }
        }
    }

    $summary = "סיכום דיווח:\n\n";
    $summary .= "שם הקשיש/ה: " . $elderly["first_name"] . " " . $elderly["last_name"] . "\n";
    $summary .= "עיר: " . $elderly["city"] . "\n";
    $summary .= "תיאור הבעיה: " . ($state["problem"] ?: "לא נמסר") . "\n";
    $summary .= "רמת דחיפות: " . ($state["urgency"] ?: "לא נמסר") . "\n";
    $summary .= "פרטים נוספים: " . ($state["extra"] ?: "אין") . "\n\n";

    $summary .= "מספר דיווח: " . $reportId . "\n";

    if (!empty($categoryNames)) {
        $summary .= "הסיווג האוטומטי: " . implode(", ", $categoryNames) . "\n";
    } else {
        $summary .= "הסיווג האוטומטי: לא נמצאה קטגוריה מתאימה\n";
    }

    if ($organization) {
        $summary .= "עמותה מתאימה: " . $organization["name"] . "\n";
        $summary .= "קטגוריית הפנייה: " . $selectedCategoryName . "\n";
        $summary .= "מספר פנייה: " . $referralId . "\n\n";
        $summary .= "הדיווח נשמר ונפתחה פנייה אוטומטית להמשך טיפול.";
    } else {
        $summary .= "\nהדיווח נשמר, אבל לא נמצאה עמותה מתאימה אוטומטית לפי הקטגוריה והעיר.";
    }

    unset($_SESSION["report_state"]);

    jsonResponse([
        "reply" => $summary
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(["error" => "Method not allowed"], 405);
}

$geminiApiKey = envValue('GEMINI_API_KEY');

if (!$geminiApiKey) {
    jsonResponse(["error" => "Missing API Key"], 500);
}

/*
    כרגע אין login אמיתי בצ׳אטבוט.
    לכן משתמשים זמנית במתנדב מספר 1.
    בהמשך, אחרי התחברות, מחליפים את זה ל-ID של המתנדב המחובר.
*/
$currentVolunteerId = 1;

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
    1. אם הבוט מחכה לאישור/תיקון אחרי ניתוח תמונה
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
            "reply" => "תודה. מה שם הקשיש/ה? עדיף שם פרטי ושם משפחה."
        ]);
    }

    if (empty($state["urgency"])) {
        jsonResponse([
            "reply" => "מה רמת הדחיפות? נמוכה, בינונית או גבוהה?"
        ]);
    }

    finalizeReportAndRespond($pdo, $geminiApiKey, $state, $currentVolunteerId);
}

/*
    2. אם צורפה תמונה - מנתחים אותה קודם
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
    3. הודעה ראשונה - שומרים אותה כתיאור הבעיה
*/
if (empty($state["problem"])) {
    $state["problem"] = $message;
    $_SESSION["report_state"] = $state;

    jsonResponse([
        "reply" => "תודה על הדיווח. מה שם הקשיש/ה? עדיף שם פרטי ושם משפחה."
    ]);
}

/*
    4. חילוץ שם קשיש, דחיפות ופרטים נוספים מההודעה
*/
$extractInstruction = "אתה מחלץ מידע מתוך הודעת מתנדב על קשיש.
ענה רק ב-JSON תקין בלבד בלי טקסט נוסף.

השדות האפשריים:
elder_name
urgency
extra

כללים:
- אם ההודעה נראית כמו שם של אדם, שים אותו ב-elder_name
- אם ההודעה היא נמוכה / בינונית / גבוהה / דחוף / לא דחוף, שים אותה ב-urgency
- אם יש מידע נוסף שעוזר להבין את הדיווח, שים אותו ב-extra
- אם שדה לא מופיע בהודעה, החזר null
- אל תמציא מידע

פורמט תשובה:
{
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

$extracted = parseJsonFromAi($replyText, "Failed to parse extracted JSON");

/*
    5. עדכון state
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

/*
    הגנה פשוטה:
    אם הבוט חיכה לשם קשיש וה-AI לא הבין, נשתמש במה שהמשתמש כתב כשם.
*/
if (empty($state["elder_name"]) && empty($state["urgency"])) {
    $state["elder_name"] = $message;
}

$_SESSION["report_state"] = $state;

/*
    6. השרת מחליט מה לשאול הבא
*/
if (empty($state["elder_name"])) {
    jsonResponse([
        "reply" => "תודה. מה שם הקשיש/ה? עדיף שם פרטי ושם משפחה."
    ]);
}

if (empty($state["urgency"])) {
    jsonResponse([
        "reply" => "מה רמת הדחיפות? נמוכה, בינונית או גבוהה?"
    ]);
}

/*
    7. סיום:
    שמירה ב-DB, סיווג, מציאת עמותה ופתיחת פנייה
*/
finalizeReportAndRespond($pdo, $geminiApiKey, $state, $currentVolunteerId);