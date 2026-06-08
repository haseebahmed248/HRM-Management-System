<?php
header("Content-Type: text/plain");
$base = dirname(__DIR__);

// Check OPcache config
echo "=== OPcache config ===\n";
echo "opcache.enable: " . ini_get("opcache.enable") . "\n";
echo "opcache.validate_timestamps: " . ini_get("opcache.validate_timestamps") . "\n";
echo "opcache.revalidate_freq: " . ini_get("opcache.revalidate_freq") . "\n";
if (function_exists("opcache_invalidate")) {
    $r1 = opcache_invalidate($base . "/app/Http/Controllers/EmployeeController.php", true);
    echo "opcache_invalidate EmployeeController: " . ($r1 ? "ok" : "fail") . "\n";
}

// Check employees table constraints
$envRaw = file_get_contents("$base/.env");
$envLines = [];
foreach (explode("\n", str_replace("\r\n", "\n", $envRaw)) as $line) {
    $line = trim($line);
    if ($line && strpos($line, "=") !== false && $line[0] !== "#") {
        [$k, $v] = explode("=", $line, 2);
        $envLines[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}
$dsn = "mysql:host=" . $envLines["DB_HOST"] . ";port=" . $envLines["DB_PORT"] . ";dbname=" . $envLines["DB_DATABASE"] . ";charset=utf8mb4";
$pdo = new PDO($dsn, $envLines["DB_USERNAME"], $envLines["DB_PASSWORD"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "\n=== employees table unique indexes ===\n";
$indexes = $pdo->query("SHOW INDEX FROM `employees` WHERE Non_unique = 0 AND Key_name != \"PRIMARY\"")->fetchAll(PDO::FETCH_ASSOC);
foreach ($indexes as $idx) {
    echo "  Key: {$idx["Key_name"]}, Col: {$idx["Column_name"]}\n";
}

echo "\n=== biometric_emp_id=1 existing count ===\n";
$count = $pdo->query("SELECT COUNT(*) FROM employees WHERE biometric_emp_id = \"1\"")->fetchColumn();
echo "  Rows with biometric_emp_id=1: $count\n";
if ($count > 0) {
    $rows = $pdo->query("SELECT id, user_id, created_by, biometric_emp_id FROM employees WHERE biometric_emp_id = \"1\"")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) echo "  id={$r["id"]} user_id={$r["user_id"]} created_by={$r["created_by"]}\n";
}