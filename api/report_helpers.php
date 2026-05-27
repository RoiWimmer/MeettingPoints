<?php

function mpQuoteIdentifier($name) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$name)) {
        throw new InvalidArgumentException("Invalid database identifier");
    }

    return "`" . $name . "`";
}

function mpDbTableExists($pdo, $tableName) {
    static $cache = [];
    $cacheKey = (string)$tableName;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $cacheKey)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS table_count
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
    ");

    $stmt->execute([
        ":table_name" => $cacheKey
    ]);

    $row = $stmt->fetch();
    $cache[$cacheKey] = !empty($row["table_count"]);

    return $cache[$cacheKey];
}

function mpDbTableColumns($pdo, $tableName) {
    static $cache = [];
    $cacheKey = (string)$tableName;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if (!mpDbTableExists($pdo, $cacheKey)) {
        $cache[$cacheKey] = [];
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
    ");

    $stmt->execute([
        ":table_name" => $cacheKey
    ]);

    $columns = [];

    foreach ($stmt->fetchAll() as $row) {
        $columns[$row["COLUMN_NAME"]] = true;
    }

    $cache[$cacheKey] = $columns;

    return $columns;
}

function mpColumnExists($columns, $columnName) {
    return isset($columns[$columnName]);
}

function mpFirstNonEmpty($row, $keys, $default = "") {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = trim((string)$row[$key]);

        if ($value !== "") {
            return $value;
        }
    }

    return $default;
}

function mpContentLabelToField($label) {
    $label = trim((string)$label);

    if (preg_match('/תיאור/u', $label)) {
        return "description";
    }

    if (preg_match('/קטגוריה|סוג צורך|need/i', $label)) {
        return "category";
    }

    if (preg_match('/דחיפות/u', $label)) {
        return "urgency";
    }

    if (preg_match('/פירוט|פרטים/u', $label)) {
        return "additional_details";
    }

    if (preg_match('/תמונה/u', $label)) {
        return "image_analysis";
    }

    if (preg_match('/אזור|עיר|יישוב/u', $label)) {
        return "area";
    }

    return null;
}

function mpParseReportContent($content) {
    $fields = [
        "description" => "",
        "category" => "",
        "urgency" => "",
        "additional_details" => "",
        "image_analysis" => "",
        "area" => ""
    ];

    $currentField = null;
    $lines = preg_split('/\R/u', (string)$content);

    foreach ($lines as $line) {
        if (preg_match('/^\s*([^:：]+)\s*[:：]\s*(.*)$/u', $line, $matches)) {
            $field = mpContentLabelToField($matches[1]);

            if ($field) {
                $currentField = $field;
                $fields[$field] = trim($matches[2]);
                continue;
            }
        }

        if ($currentField && trim($line) !== "") {
            $fields[$currentField] = trim($fields[$currentField] . "\n" . trim($line));
        }
    }

    return $fields;
}

function mpInferNeedType($text) {
    $text = trim((string)$text);

    $patterns = [
        "ריח גז|גז|שריפה|עשן|נפילה|סכנה|חירום|חשמל חשוף|הצפה" => "בטיחות",
        "בודד|בודדה|בדידות|לבד|שיחה|ביקור" => "בדידות",
        "מזון|אוכל|ארוחה|קניות|מצרכים" => "מזון",
        "תרופה|תרופות|מרשם|רופא|בדיקה|כאב|חולה|בריאות" => "בעיה רפואית",
        "ליווי|הסעה|תור|מרפאה|קופת חולים" => "ליווי רפואי",
        "תיקון|נזילה|דוד|מנעול|מקרר|תחזוקה|בית" => "בעיה תחזוקתית בבית",
        "ביטוח לאומי|רשויות|טופס|זכויות|חשבון|בירוקרטיה" => "סיוע מול רשויות"
    ];

    foreach ($patterns as $pattern => $needType) {
        if (preg_match('/(' . $pattern . ')/u', $text)) {
            return $needType;
        }
    }

    return "אחר";
}

function mpNormalizeUrgencyValue($value, $context = "") {
    $rawValue = trim((string)$value);

    if (in_array($rawValue, ["נמוכה", "בינונית", "גבוהה", "קריטית"], true)) {
        return $rawValue;
    }

    $combined = trim((string)$value . " " . (string)$context);

    if (preg_match('/קריט|סכנת חיים|ריח גז|גז|שריפה|אש|עשן|נפילה|נפל|נפלה|לא נושם|לא נושמת|התעלף|התעלפה|חירום|חשמל חשוף|הצפה|סכנה מיידית|סכנה מידית/u', $combined)) {
        return "קריטית";
    }

    if (preg_match('/גבוה|דחוף|מיידי|מידי|סכנה|חמור|בהול|הידרדרות|הדרדרות|בלבול|תרופה|תרופות/u', $combined)) {
        return "גבוהה";
    }

    if (preg_match('/נמוכ|לא דחוף|בהמשך|כשאפשר|לא ממהר/u', $combined)) {
        return "נמוכה";
    }

    if (preg_match('/בינונ|בקרוב|השבוע|כדאי לטפל/u', $combined)) {
        return "בינונית";
    }

    return "בינונית";
}

function mpNormalizeStatus($status) {
    $status = trim((string)$status);

    if ($status === "חדש" || $status === "פתוח") {
        return "הוגש";
    }

    if ($status === "סגור" || $status === "נסגר" || strtolower($status) === "closed" || strtolower($status) === "done") {
        return "טופל";
    }

    return $status !== "" ? $status : "הוגש";
}

function mpIsDoneStatus($status) {
    return mpNormalizeStatus($status) === "טופל";
}

function mpDaysBetween($start, $end = null) {
    if (!$start) {
        return 0;
    }

    try {
        $startDate = new DateTime((string)$start);
        $endDate = $end ? new DateTime((string)$end) : new DateTime();
        $diff = $startDate->diff($endDate);

        return max(0, (int)$diff->format("%a"));
    } catch (Throwable $e) {
        return 0;
    }
}

function mpClosedAtFromHistory($history) {
    foreach ($history as $item) {
        if (mpIsDoneStatus($item["new_status"] ?? "")) {
            return $item["created_at"] ?? null;
        }
    }

    return null;
}

function mpCountBy($items, $key) {
    $counts = [];

    foreach ($items as $item) {
        $value = trim((string)($item[$key] ?? "לא ידוע"));
        $value = $value !== "" ? $value : "לא ידוע";
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }

    arsort($counts);

    return $counts;
}

function mpMonthKey($createdAt) {
    try {
        $date = new DateTime((string)$createdAt);
        return $date->format("Y-m");
    } catch (Throwable $e) {
        return "לא ידוע";
    }
}

function mpReportAssignedOrg($row) {
    $value = mpFirstNonEmpty($row, [
        "assigned_org",
        "assigned_organization",
        "assigned_organization_name",
        "organization_name",
        "org_name",
        "routing_org",
        "routed_to",
        "elderly_assigned_org",
        "elderly_organization_name"
    ]);

    if ($value !== "") {
        return $value;
    }

    $idValue = mpFirstNonEmpty($row, [
        "assigned_org_id",
        "assigned_organization_id",
        "organization_id",
        "routed_to_org_id",
        "routing_id"
    ]);

    return $idValue !== "" ? "גורם #" . $idValue : null;
}

function mpHasRoutingMetadata($reportColumns) {
    foreach (["assigned_org", "assigned_org_id", "assigned_organization_id", "organization_id", "routing_id", "routed_to_org_id"] as $column) {
        if (mpColumnExists($reportColumns, $column)) {
            return true;
        }
    }

    return false;
}

function mpResourceGap($row, $reportColumns, $assignedOrg, $daysOpen) {
    if ($assignedOrg) {
        return false;
    }

    if (mpIsDoneStatus($row["status"] ?? "")) {
        return false;
    }

    if (mpHasRoutingMetadata($reportColumns)) {
        return true;
    }

    $status = mpNormalizeStatus($row["status"] ?? "");
    $urgency = $row["urgency"] ?? "";

    return in_array($status, ["הוגש", "ממתין לגורם חיצוני"], true)
        || in_array($urgency, ["גבוהה", "קריטית"], true)
        || (int)$daysOpen >= 2;
}

function mpFetchStatusHistory($pdo, $reportIds) {
    if (!$reportIds || !mpDbTableExists($pdo, "report_status_history")) {
        return [];
    }

    $historyColumns = mpDbTableColumns($pdo, "report_status_history");

    if (!mpColumnExists($historyColumns, "report_id")) {
        return [];
    }

    $placeholders = [];
    $params = [];

    foreach (array_values($reportIds) as $index => $id) {
        $key = ":id_" . $index;
        $placeholders[] = $key;
        $params[$key] = (int)$id;
    }

    $orderParts = [];

    if (mpColumnExists($historyColumns, "created_at")) {
        $orderParts[] = "created_at ASC";
    }

    if (mpColumnExists($historyColumns, "id")) {
        $orderParts[] = "id ASC";
    }

    $sql = "SELECT * FROM report_status_history WHERE report_id IN (" . implode(",", $placeholders) . ")";

    if ($orderParts) {
        $sql .= " ORDER BY " . implode(", ", $orderParts);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $grouped = [];

    foreach ($stmt->fetchAll() as $item) {
        $reportId = (int)($item["report_id"] ?? 0);

        if (!$reportId) {
            continue;
        }

        if (!isset($grouped[$reportId])) {
            $grouped[$reportId] = [];
        }

        $grouped[$reportId][] = $item;
    }

    return $grouped;
}

function mpFetchNormalizedReports($pdo) {
    $reportColumns = mpDbTableColumns($pdo, "reports");

    if (!$reportColumns) {
        throw new RuntimeException("reports table was not found");
    }

    $select = ["r.*"];
    $join = "";

    if (mpColumnExists($reportColumns, "elderly_id") && mpDbTableExists($pdo, "elderly")) {
        $elderlyColumns = mpDbTableColumns($pdo, "elderly");

        if (mpColumnExists($elderlyColumns, "id")) {
            foreach ([
                "area",
                "city",
                "neighborhood",
                "address",
                "assigned_org",
                "organization_name",
                "organization_id",
                "org_id",
                "ngo_id",
                "association_id",
                "assigned_org_id",
                "assigned_organization_id",
                "volunteer_id",
                "assigned_volunteer_id",
                "primary_volunteer_id"
            ] as $column) {
                if (mpColumnExists($elderlyColumns, $column)) {
                    $select[] = "e." . mpQuoteIdentifier($column) . " AS " . mpQuoteIdentifier("elderly_" . $column);
                }
            }

            $join = " LEFT JOIN elderly e ON e.id = r.elderly_id";
        }
    }

    $order = mpColumnExists($reportColumns, "created_at")
        ? " ORDER BY r.created_at DESC, r.id DESC"
        : " ORDER BY r.id DESC";

    $stmt = $pdo->prepare("SELECT " . implode(", ", $select) . " FROM reports r" . $join . $order);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $reportIds = [];

    foreach ($rows as $row) {
        if (!empty($row["id"])) {
            $reportIds[] = (int)$row["id"];
        }
    }

    $historyByReport = mpFetchStatusHistory($pdo, $reportIds);
    $normalized = [];

    foreach ($rows as $row) {
        $id = (int)($row["id"] ?? 0);
        $content = (string)($row["content"] ?? "");
        $parsed = mpParseReportContent($content);
        $history = $historyByReport[$id] ?? [];
        $status = mpNormalizeStatus($row["status"] ?? "");
        $category = mpFirstNonEmpty($row, ["need_type", "category"], $parsed["category"]);
        $category = trim($category) !== "" ? $category : mpInferNeedType($content);
        $urgency = mpNormalizeUrgencyValue(mpFirstNonEmpty($row, ["urgency"], $parsed["urgency"]), $content);
        $description = mpFirstNonEmpty($row, ["description"], $parsed["description"]);
        $description = $description !== "" ? $description : trim($content);
        $area = mpFirstNonEmpty($row, [
            "area",
            "city",
            "neighborhood",
            "elderly_area",
            "elderly_city",
            "elderly_neighborhood"
        ], $parsed["area"]);
        $area = $area !== "" ? $area : "לא ידוע";
        $additionalDetails = mpFirstNonEmpty($row, ["additional_details"], $parsed["additional_details"]);
        $closedAt = mpIsDoneStatus($status) ? mpClosedAtFromHistory($history) : null;
        $daysOpen = mpDaysBetween($row["created_at"] ?? null, $closedAt);
        $assignedOrg = mpReportAssignedOrg($row);
        $organizationId = mpFirstNonEmpty($row, [
            "organization_id",
            "org_id",
            "ngo_id",
            "association_id",
            "assigned_org_id",
            "assigned_organization_id",
            "routed_to_org_id",
            "elderly_organization_id",
            "elderly_org_id",
            "elderly_ngo_id",
            "elderly_association_id",
            "elderly_assigned_org_id",
            "elderly_assigned_organization_id"
        ]);
        $organizationName = mpFirstNonEmpty($row, [
            "organization_name",
            "org_name",
            "assigned_org",
            "assigned_organization",
            "elderly_organization_name",
            "elderly_assigned_org"
        ]);

        $resourceGap = mpResourceGap(
            array_merge($row, ["status" => $status, "urgency" => $urgency]),
            $reportColumns,
            $assignedOrg,
            $daysOpen
        );

        $normalized[] = [
            "id" => $id,
            "volunteer_id" => $row["volunteer_id"] ?? null,
            "elderly_id" => $row["elderly_id"] ?? null,
            "organization_id" => $organizationId !== "" ? (int)$organizationId : null,
            "organization_name" => $organizationName,
            "content" => $content,
            "urgency" => $urgency,
            "status" => $row["status"] ?? $status,
            "normalized_status" => $status,
            "classification_source" => $row["classification_source"] ?? null,
            "created_at" => $row["created_at"] ?? null,
            "category" => $category,
            "need_type" => $category,
            "parsed_category" => $category,
            "parsed_description" => $description,
            "description" => $description,
            "parsed_additional_details" => $additionalDetails,
            "additional_details" => $additionalDetails,
            "image_analysis" => $parsed["image_analysis"],
            "area" => $area,
            "assigned_org" => $assignedOrg,
            "status_history" => $history,
            "days_open" => $daysOpen,
            "resource_gap" => $resourceGap,
            "has_resource_gap" => $resourceGap
        ];
    }

    return $normalized;
}

function mpBuildReportStats($reports) {
    $total = count($reports);
    $open = 0;
    $submitted = 0;
    $inProgress = 0;
    $done = 0;
    $urgentOpen = 0;
    $daysOpenSum = 0;
    $openForAverage = 0;
    $attentionCases = [];
    $reportsByMonth = [];

    foreach ($reports as $report) {
        $status = mpNormalizeStatus($report["normalized_status"] ?? ($report["status"] ?? ""));
        $isDone = mpIsDoneStatus($status);

        if ($status === "הוגש") {
            $submitted++;
        }

        if ($status === "בטיפול") {
            $inProgress++;
        }

        if ($isDone) {
            $done++;
        } else {
            $open++;
            $daysOpenSum += (int)($report["days_open"] ?? 0);
            $openForAverage++;
        }

        if (in_array(($report["urgency"] ?? ""), ["גבוהה", "קריטית"], true) && !$isDone) {
            $urgentOpen++;
        }

        $monthKey = mpMonthKey($report["created_at"] ?? "");
        $reportsByMonth[$monthKey] = ($reportsByMonth[$monthKey] ?? 0) + 1;

        $reasons = [];

        if (in_array(($report["urgency"] ?? ""), ["גבוהה", "קריטית"], true) && !$isDone) {
            $reasons[] = ($report["urgency"] ?? "") === "קריטית" ? "דחיפות קריטית" : "דחיפות גבוהה";
        }

        if (!$isDone && (int)($report["days_open"] ?? 0) > 5) {
            $reasons[] = "פתוח מעל 5 ימים";
        }

        if (!empty($report["resource_gap"])) {
            $reasons[] = "חסר גורם/משאב";
        }

        if ($reasons) {
            $attentionCases[] = [
                "id" => $report["id"],
                "need_type" => $report["need_type"] ?? "לא ידוע",
                "area" => $report["area"] ?? "לא ידוע",
                "urgency" => $report["urgency"] ?? "בינונית",
                "status" => $status,
                "days_open" => (int)($report["days_open"] ?? 0),
                "reasons" => $reasons
            ];
        }
    }

    ksort($reportsByMonth);

    return [
        "total" => $total,
        "open" => $open,
        "submitted" => $submitted,
        "in_progress" => $inProgress,
        "done" => $done,
        "urgent_open" => $urgentOpen,
        "by_need_type" => mpCountBy($reports, "need_type"),
        "by_status" => mpCountBy($reports, "normalized_status"),
        "by_urgency" => mpCountBy($reports, "urgency"),
        "by_area" => mpCountBy($reports, "area"),
        "reports_by_month" => $reportsByMonth,
        "average_days_open" => $openForAverage ? round($daysOpenSum / $openForAverage, 1) : 0,
        "resource_gaps" => count(array_filter($reports, function ($report) {
            return !empty($report["resource_gap"]);
        })),
        "resource_gaps_by_need_type" => mpCountBy(array_filter($reports, function ($report) {
            return !empty($report["resource_gap"]);
        }), "need_type"),
        "attention_cases" => array_slice($attentionCases, 0, 20)
    ];
}
