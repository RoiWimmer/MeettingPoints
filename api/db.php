<?php

function dbEnvValue($key, $default = null) {
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

$dbHost = dbEnvValue('DB_HOST', 'localhost');
$dbName = dbEnvValue('DB_NAME');
$dbUser = dbEnvValue('DB_USER');
$dbPass = dbEnvValue('DB_PASS', '');

if (!$dbName || !$dbUser) {
    http_response_code(500);
    header("Content-Type: application/json; charset=UTF-8");

    echo json_encode([
        "error" => "Missing database configuration",
        "message" => "Please set DB_HOST, DB_NAME, DB_USER, DB_PASS in .env"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    http_response_code(500);
    header("Content-Type: application/json; charset=UTF-8");

    echo json_encode([
        "error" => "Database connection failed",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);

    exit;
}