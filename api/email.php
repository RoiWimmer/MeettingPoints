<?php

function emailEnvValue($key, $default = null)
{
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

function sendBrevoEmail($subject, $textBody, $htmlBody = null)
{
    $apiKey = emailEnvValue('BREVO_API_KEY');

    if (!$apiKey) {
        error_log('BREVO API KEY MISSING');
        return false;
    }

    $data = array(
        'sender' => array(
            'name' => emailEnvValue('SMTP_FROM_NAME', 'Nekudot Hibur'),
            'email' => emailEnvValue('SMTP_FROM_EMAIL', 'meetingpoint180@gmail.com')
        ),
        'to' => array(
            array(
                'email' => 'meetingpoint180@gmail.com'
            )
        ),
        'subject' => $subject,
        'textContent' => $textBody
    );

    if ($htmlBody !== null) {
        $data['htmlContent'] = $htmlBody;
    }

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, 'https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json'
    ));

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($curlError) {
        error_log('BREVO CURL ERROR: ' . $curlError);
        return false;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        error_log('BREVO EMAIL SENT SUCCESSFULLY');
        return true;
    }

    error_log('BREVO ERROR HTTP ' . $httpCode . ': ' . $response);
    return false;
}

function createEmailStatusToken($reportId, $status)
{
    $secret = emailEnvValue('BREVO_API_KEY');

    if (!$secret) {
        $secret = 'fallback-secret';
    }

    return hash_hmac('sha256', $reportId . '|' . $status, $secret);
}

function createEmailStatusLink($reportId, $status)
{
    $baseUrl = 'http://isroiwi.mtacloud.co.il/Meetting_Points/api/update_status_from_email.php';

    $token = createEmailStatusToken($reportId, $status);

    return $baseUrl
        . '?report_id=' . urlencode($reportId)
        . '&status=' . urlencode($status)
        . '&token=' . urlencode($token);
}

function sendReportCreatedEmail($details)
{
    $reportId = $details['report_id'] ?? '';

    $subject = 'דיווח חדש #' . $reportId . ' - נקודות חיבור';

    $inProgressLink = createEmailStatusLink($reportId, 'בטיפול');
    $doneLink = createEmailStatusLink($reportId, 'טופל');

    $textBody = "נוצר דיווח חדש במערכת.\n\n";
    $textBody .= "מספר דיווח: " . ($details['report_id'] ?? '') . "\n";
    $textBody .= "מספר מתנדב: " . ($details['volunteer_id'] ?? '') . "\n";
    $textBody .= "מתנדב: " . ($details['volunteer_name'] ?? '') . "\n";
    $textBody .= "מספר קשיש: " . ($details['elderly_id'] ?? '') . "\n";
    $textBody .= "קשיש: " . ($details['elderly_name'] ?? '') . "\n";
    $textBody .= "דחיפות: " . ($details['urgency'] ?? '') . "\n";
    $textBody .= "סטטוס: " . ($details['status'] ?? '') . "\n";
    $textBody .= "תאריך: " . ($details['created_at'] ?? '') . "\n";
    $textBody .= "קטגוריה: " . ($details['category'] ?? '') . "\n\n";
    $textBody .= "תיאור הדיווח:\n";
    $textBody .= ($details['description'] ?? '') . "\n\n";
    $textBody .= "קישור לעדכון לסטטוס בטיפול:\n" . $inProgressLink . "\n\n";
    $textBody .= "קישור לסימון כטופל:\n" . $doneLink . "\n";

    $htmlBody = '
    <div dir="rtl" style="font-family: Arial, sans-serif; line-height: 1.7; color: #1e293b;">
        <h2 style="margin-bottom: 10px;">דיווח חדש - נקודות חיבור</h2>

        <p>נוצר דיווח חדש במערכת.</p>

        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px; margin: 16px 0;">
            <strong>מספר דיווח:</strong> ' . htmlspecialchars($details['report_id'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>מספר מתנדב:</strong> ' . htmlspecialchars($details['volunteer_id'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>מתנדב:</strong> ' . htmlspecialchars($details['volunteer_name'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>מספר קשיש:</strong> ' . htmlspecialchars($details['elderly_id'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>קשיש:</strong> ' . htmlspecialchars($details['elderly_name'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>דחיפות:</strong> ' . htmlspecialchars($details['urgency'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>סטטוס:</strong> ' . htmlspecialchars($details['status'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>תאריך:</strong> ' . htmlspecialchars($details['created_at'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>קטגוריה:</strong> ' . htmlspecialchars($details['category'] ?? '', ENT_QUOTES, 'UTF-8') . '
        </div>

        <p><strong>תיאור הדיווח:</strong></p>

        <div style="white-space: pre-line; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px;">
            ' . nl2br(htmlspecialchars($details['description'] ?? '', ENT_QUOTES, 'UTF-8')) . '
        </div>

        <p style="margin-top: 22px;"><strong>עדכון סטטוס מהיר:</strong></p>

        <div style="margin-top: 12px;">
            <a href="' . htmlspecialchars($inProgressLink, ENT_QUOTES, 'UTF-8') . '"
               style="display:inline-block; background:#2563eb; color:white; padding:12px 18px; border-radius:12px; text-decoration:none; font-weight:bold; margin-left:8px;">
               העבר לבטיפול
            </a>

            <a href="' . htmlspecialchars($doneLink, ENT_QUOTES, 'UTF-8') . '"
               style="display:inline-block; background:#059669; color:white; padding:12px 18px; border-radius:12px; text-decoration:none; font-weight:bold;">
               סמן כטופל
            </a>
        </div>
    </div>';

    return sendBrevoEmail($subject, $textBody, $htmlBody);
}

function sendReportStatusChangedEmail($details)
{
    $subject = 'עדכון סטטוס לדיווח #' . ($details['report_id'] ?? '') . ' - נקודות חיבור';

    $textBody = "סטטוס דיווח עודכן במערכת.\n\n";
    $textBody .= "מספר דיווח: " . ($details['report_id'] ?? '') . "\n";
    $textBody .= "סטטוס קודם: " . ($details['old_status'] ?? '') . "\n";
    $textBody .= "סטטוס חדש: " . ($details['new_status'] ?? '') . "\n";
    $textBody .= "מספר מתנדב: " . ($details['volunteer_id'] ?? '') . "\n";
    $textBody .= "מספר קשיש: " . ($details['elderly_id'] ?? '') . "\n";
    $textBody .= "דחיפות: " . ($details['urgency'] ?? '') . "\n";
    $textBody .= "תאריך יצירת הדיווח: " . ($details['created_at'] ?? '') . "\n";
    $textBody .= "תאריך עדכון הסטטוס: " . ($details['updated_at'] ?? '') . "\n\n";
    $textBody .= "תוכן הדיווח:\n";
    $textBody .= ($details['description'] ?? '');

    $htmlBody = '
    <div dir="rtl" style="font-family: Arial, sans-serif; line-height: 1.7; color: #1e293b;">
        <h2>עדכון סטטוס לדיווח #' . htmlspecialchars($details['report_id'] ?? '', ENT_QUOTES, 'UTF-8') . '</h2>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:16px;">
            <strong>סטטוס קודם:</strong> ' . htmlspecialchars($details['old_status'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>סטטוס חדש:</strong> ' . htmlspecialchars($details['new_status'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>מספר מתנדב:</strong> ' . htmlspecialchars($details['volunteer_id'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>מספר קשיש:</strong> ' . htmlspecialchars($details['elderly_id'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>דחיפות:</strong> ' . htmlspecialchars($details['urgency'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>תאריך עדכון:</strong> ' . htmlspecialchars($details['updated_at'] ?? '', ENT_QUOTES, 'UTF-8') . '
        </div>

        <p><strong>תוכן הדיווח:</strong></p>
        <div style="white-space: pre-line; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:14px;">
            ' . nl2br(htmlspecialchars($details['description'] ?? '', ENT_QUOTES, 'UTF-8')) . '
        </div>
    </div>';

    return sendBrevoEmail($subject, $textBody, $htmlBody);
}

function sendReportFollowUpReminderEmail($details)
{
    $subject = 'תזכורת טיפול בדיווח #' . ($details['report_id'] ?? '') . ' - נקודות חיבור';

    $textBody = "תזכורת: דיווח נמצא בסטטוס בטיפול כבר מעל שבוע.\n\n";
    $textBody .= "מספר דיווח: " . ($details['report_id'] ?? '') . "\n";
    $textBody .= "סטטוס נוכחי: בטיפול\n";
    $textBody .= "בתהליך מאז: " . ($details['in_progress_since'] ?? '') . "\n";
    $textBody .= "מספר מתנדב: " . ($details['volunteer_id'] ?? '') . "\n";
    $textBody .= "מספר קשיש: " . ($details['elderly_id'] ?? '') . "\n";
    $textBody .= "דחיפות: " . ($details['urgency'] ?? '') . "\n";
    $textBody .= "תאריך יצירת הדיווח: " . ($details['created_at'] ?? '') . "\n\n";
    $textBody .= "נדרש לבדוק האם חלה התקדמות בטיפול ולעדכן את הסטטוס במערכת.\n\n";
    $textBody .= "תוכן הדיווח:\n";
    $textBody .= ($details['description'] ?? '');

    $htmlBody = '
    <div dir="rtl" style="font-family: Arial, sans-serif; line-height: 1.7; color: #1e293b;">
        <h2>תזכורת טיפול בדיווח #' . htmlspecialchars($details['report_id'] ?? '', ENT_QUOTES, 'UTF-8') . '</h2>

        <p>דיווח נמצא בסטטוס <strong>בטיפול</strong> כבר מעל שבוע.</p>

        <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:14px; padding:16px;">
            <strong>בתהליך מאז:</strong> ' . htmlspecialchars($details['in_progress_since'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>מספר מתנדב:</strong> ' . htmlspecialchars($details['volunteer_id'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>מספר קשיש:</strong> ' . htmlspecialchars($details['elderly_id'] ?? '', ENT_QUOTES, 'UTF-8') . '<br>
            <strong>דחיפות:</strong> ' . htmlspecialchars($details['urgency'] ?? '', ENT_QUOTES, 'UTF-8') . '
        </div>

        <p>נדרש לבדוק האם חלה התקדמות בטיפול ולעדכן את הסטטוס במערכת.</p>

        <p><strong>תוכן הדיווח:</strong></p>
        <div style="white-space: pre-line; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:14px;">
            ' . nl2br(htmlspecialchars($details['description'] ?? '', ENT_QUOTES, 'UTF-8')) . '
        </div>
    </div>';

    return sendBrevoEmail($subject, $textBody, $htmlBody);
}