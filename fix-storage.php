<?php
/*
 * fix-storage.php — replicates `php artisan storage:link` for shared
 * hosts where CLI is unavailable (e.g. Infratel cPanel).
 *
 * Drop at the public_html root, visit https://afripay-hr.co.zm/fix-storage.php
 * once, then DELETE THE FILE.
 *
 * Probes both standard Laravel cPanel layouts:
 *   A) docroot IS the laravel public/ folder      → public_html/storage -> ../storage/app/public
 *   B) full Laravel repo lives in public_html/    → public_html/public/storage -> ../../storage/app/public
 *                                                   AND public_html/storage -> public/storage (for /storage/* URLs)
 */

header('Content-Type: text/plain; charset=utf-8');

$layouts = [
    'A: docroot is laravel/public' => [
        'target' => __DIR__ . '/../storage/app/public',
        'link'   => __DIR__ . '/storage',
    ],
    'B: full repo in public_html (public/storage link)' => [
        'target' => __DIR__ . '/storage/app/public',
        'link'   => __DIR__ . '/public/storage',
    ],
    'B-extra: full repo in public_html (top-level alias)' => [
        // so URL /storage/* resolves at the docroot
        'target' => __DIR__ . '/public/storage',
        'link'   => __DIR__ . '/storage',
    ],
];

echo "=== fix-storage.php ===\n";
echo "__DIR__ = " . __DIR__ . "\n\n";

foreach ($layouts as $label => $pair) {
    [$target, $link] = [$pair['target'], $pair['link']];
    echo "[$label]\n";
    echo "  target: $target\n";
    echo "  link:   $link\n";

    $resolvedTarget = realpath($target);
    if ($resolvedTarget === false) {
        echo "  → target does NOT exist on disk — skipping.\n\n";
        continue;
    }

    if (is_link($link)) {
        echo "  → link already exists, points to: " . readlink($link) . "\n\n";
        continue;
    }

    if (file_exists($link)) {
        echo "  → '$link' exists but is NOT a symlink. Refusing to overwrite. Inspect manually.\n\n";
        continue;
    }

    if (@symlink($resolvedTarget, $link)) {
        echo "  → symlink CREATED → $resolvedTarget\n\n";
    } else {
        $err = error_get_last();
        echo "  → symlink() FAILED: " . ($err['message'] ?? 'unknown') . "\n";
        echo "    Hosts that disable symlink() — fall back to copying.\n\n";
    }
}

echo "=== Inventory ===\n";
$paths = [
    __DIR__ . '/storage/app/public',
    __DIR__ . '/storage/app/public/media',
    __DIR__ . '/storage/app/public/media/logo',
    __DIR__ . '/public/storage',
    __DIR__ . '/storage',
];
foreach ($paths as $p) {
    if (is_dir($p)) {
        $files = glob($p . '/*') ?: [];
        echo "  EXISTS  $p  (" . count($files) . " entries)\n";
    } elseif (is_link($p)) {
        echo "  SYMLINK $p -> " . readlink($p) . "\n";
    } else {
        echo "  MISSING $p\n";
    }
}

echo "\n=== Done. DELETE THIS FILE after running. ===\n";
