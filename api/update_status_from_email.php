<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';

function statusLinkSecret()
{
    $secret = emailEnvValue('BREVO_API_KEY');

    if (!$secret) {
        $secret = 'fallback-secret';
    }

    return $secret;
}

function createStatusToken($reportId, $status)
{
    return hash_hmac(
        'sha256',
        $reportId . '|' . $status,
        statusLinkSecret()
    );
}

function renderResultPage($title, $message, $success = true)
{
    $color = $success ? '#059669' : '#dc2626';
    $bg = $success ? '#ecfdf5' : '#fef2f2';

    echo '<!DOCTYPE html>
    <html lang="he" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>עדכון סטטוס</title>
        <style>
            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f8fafc;
                font-family: Arial, sans-serif;
                direction: rtl;
            }

            .card {
                width: min(520px, 90%);
                background: white;
                border-radius: 22px;
                padding: 32px;
                box-shadow: 0 14px 40px rgba(15, 23, 42, 0.12);
                border: 1px solid #e2e8f0;
                text-align: center;
            }

            .badge {
                display: inline-block;
                background: ' . $bg . ';
                color: ' . $color . ';
                padding: 10px 16px;
                border-radius: 999px;
                font-weight: 800;
                margin-bottom: 16px;
            }

            h1 {
                margin: 0 0 12px;
                color: #1e293b;
            }

            p {
                color: #64748b;
                font-size: 1.05rem;
                line-height: 1.6;
            }

            a {
                display: inline-block;
                margin-top: 18px;
                color: white;
                background: #f1a340;
                padding: 11px 18px;
                border-radius: 12px;
                text-decoration: none;
                font-weight: 800;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="badge">' . ($success ? 'בוצע בהצלחה' : 'שגיאה') . '</div>
            <h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>
            <p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
            <a href="../reports.html?v=70">חזרה לדיווחים</a>
        </div>
    </body>
    </html>';
}

$reportId = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
$newStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

$allowedStatuses = ['בטיפול', 'טופל'];

if (!$reportId || !in_array($newStatus, $allowedStatuses, true) || !$token) {
    renderResultPage('בקשה לא תקינה', 'חסרים פרטים לעדכון הסטטוס.', false);
    exit;
}

$expectedToken = createStatusToken($reportId, $newStatus);

if (!hash_equals($expectedToken, $token)) {
    renderResultPage('קישור לא תקין', 'לא ניתן לאמת את קישור עדכון הסטטוס.', false);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, volunteer_id, elderly_id, content, urgency, status, created_at
        FROM reports
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $reportId
    ]);

    $report = $stmt->fetch();

    if (!$report) {
        renderResultPage('דיווח לא נמצא', 'לא נמצא דיווח מתאים במערכת.', false);
        exit;
    }

    $oldStatus = $report['status'];

    if ($oldStatus === $newStatus) {
        renderResultPage(
            'הסטטוס כבר מעודכן',
            'דיווח #' . $reportId . ' כבר נמצא בסטטוס ' . $newStatus . '.',
            true
        );
        exit;
    }

    $pdo->beginTransaction();

    $updateStmt = $pdo->prepare("
        UPDATE reports
        SET status = :status
        WHERE id = :id
    ");

    $updateStmt->execute([
        ':status' => $newStatus,
        ':id' => $reportId
    ]);

    $historyStmt = $pdo->prepare("
        INSERT INTO report_status_history
        (report_id, old_status, new_status, changed_by, notes, created_at)
        VALUES
        (:report_id, :old_status, :new_status, :changed_by, :notes, NOW())
    ");

    $historyStmt->execute([
        ':report_id' => $reportId,
        ':old_status' => $oldStatus,
        ':new_status' => $newStatus,
        ':changed_by' => 1,
        ':notes' => 'Status updated from email action link'
    ]);

    $pdo->commit();

    try {
        if (function_exists('sendReportStatusChangedEmail')) {
            sendReportStatusChangedEmail([
                'report_id' => (string)$reportId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'volunteer_id' => (string)$report['volunteer_id'],
                'elderly_id' => (string)$report['elderly_id'],
                'urgency' => $report['urgency'],
                'description' => $report['content'],
                'created_at' => $report['created_at'],
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
    } catch (Exception $emailError) {
        error_log('STATUS EMAIL AFTER LINK ERROR: ' . $emailError->getMessage());
    }

    renderResultPage(
        'הסטטוס עודכן',
        'דיווח #' . $reportId . ' עודכן מ-' . $oldStatus . ' ל-' . $newStatus . '.',
        true
    );

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('EMAIL STATUS LINK ERROR: ' . $e->getMessage());

    renderResultPage(
        'שגיאה בעדכון הסטטוס',
        'לא הצלחנו לעדכן את הסטטוס. נסי שוב או עדכני דרך המערכת.',
        false
    );
}