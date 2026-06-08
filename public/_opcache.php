<?php
header("Content-Type: text/plain");
if (function_exists("opcache_reset")) {
    $result = opcache_reset();
    echo "opcache_reset(): " . ($result ? "SUCCESS" : "FAILED") . "\n";
    $status = opcache_get_status(false);
    echo "OPcache enabled: " . ($status ? "yes" : "no") . "\n";
    if ($status) {
        echo "Cached scripts: " . $status["opcache_statistics"]["num_cached_scripts"] . "\n";
    }
} else {
    echo "opcache_reset() not available\n";
    echo "Trying file touch approach...\n";
    // Touch key files to force OPcache invalidation
    $base = dirname(__DIR__);
    $files = [
        "$base/app/Http/Controllers/EmployeeController.php",
        "$base/app/Exceptions/Handler.php",
    ];
    foreach ($files as $f) {
        if (file_exists($f)) {
            touch($f);
            echo "Touched: $f\n";
        }
    }
}
echo "Done.\n";