<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Emergency Runtime Fix Script (cPanel, no terminal)
|--------------------------------------------------------------------------
| Usage:
| 1) Set a strong secret key below.
| 2) Open: /fix-runtime.php?key=YOUR_KEY
| 3) Verify output is OK.
| 4) Delete this file immediately.
|
| Optional:
| - Add &self_delete=1 to attempt deleting this file after success.
*/

$secretKey = 'CHANGE_THIS_SECRET_KEY';

if ($secretKey === 'CHANGE_THIS_SECRET_KEY') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Set a strong \$secretKey in fix-runtime.php before using this script.\n";
    exit;
}

if (!isset($_GET['key']) || !hash_equals($secretKey, (string) $_GET['key'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

$basePath = realpath(__DIR__ . '/..');
if ($basePath === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unable to resolve base path.\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "Laravel runtime fix started...\n";
echo "Base path: {$basePath}\n\n";

$envPath = $basePath . '/.env';
echo ".env file: {$envPath}\n";
echo ".env exists: " . (is_file($envPath) ? "yes" : "no") . "\n";

$envValues = [];
if (is_file($envPath) && is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim((string) $line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separatorPos = strpos($line, '=');
        if ($separatorPos === false) {
            continue;
        }

        $name = trim(substr($line, 0, $separatorPos));
        $value = trim(substr($line, $separatorPos + 1));

        if ($value !== '') {
            $isDoubleQuoted = str_starts_with($value, '"') && str_ends_with($value, '"');
            $isSingleQuoted = str_starts_with($value, "'") && str_ends_with($value, "'");

            if ($isDoubleQuoted || $isSingleQuoted) {
                $value = substr($value, 1, -1);
            }
        }

        $envValues[$name] = $value;
    }
}

$jwtSecret = trim((string) ($envValues['JWT_SECRET'] ?? ''));
$appKey = trim((string) ($envValues['APP_KEY'] ?? ''));

echo "JWT_SECRET present: " . ($jwtSecret !== '' ? 'yes' : 'no') . "\n";
echo "APP_KEY present: " . ($appKey !== '' ? 'yes' : 'no') . "\n";
echo "bootstrap/cache/config.php exists (before clear): " . (is_file($basePath . '/bootstrap/cache/config.php') ? 'yes' : 'no') . "\n\n";

$dirs = [
    $basePath . '/storage/logs',
    $basePath . '/storage/framework/cache/data',
    $basePath . '/storage/framework/sessions',
    $basePath . '/storage/framework/views',
    $basePath . '/bootstrap/cache',
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (@mkdir($dir, 0775, true)) {
            echo "[OK] Created dir: {$dir}\n";
        } else {
            echo "[WARN] Failed to create dir: {$dir}\n";
        }
    } else {
        echo "[OK] Dir exists: {$dir}\n";
    }

    if (!@chmod($dir, 0775)) {
        echo "[WARN] Failed chmod 775 on dir: {$dir}\n";
    }
}

echo "\nClearing bootstrap/cache/*.php ...\n";

$deleted = 0;
$failed = 0;

foreach (glob($basePath . '/bootstrap/cache/*.php') ?: [] as $file) {
    if (basename($file) === '.gitignore') {
        continue;
    }

    if (@unlink($file)) {
        $deleted++;
        echo "[OK] Deleted: {$file}\n";
    } else {
        $failed++;
        echo "[WARN] Failed delete: {$file}\n";
    }
}

echo "\nAdjusting permissions in storage + bootstrap/cache ...\n";

$iterTargets = [$basePath . '/storage', $basePath . '/bootstrap/cache'];

foreach ($iterTargets as $target) {
    if (!is_dir($target)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();

        if ($item->isDir()) {
            @chmod($path, 0775);
        } else {
            @chmod($path, 0664);
        }
    }
}

echo "\nDone.\n";
echo "Deleted cache files: {$deleted}\n";
if ($failed > 0) {
    echo "Delete failed: {$failed}\n";
}

echo "bootstrap/cache/config.php exists (after clear): " . (is_file($basePath . '/bootstrap/cache/config.php') ? 'yes' : 'no') . "\n";

echo "\nIMPORTANT: Delete /public/fix-runtime.php now.\n";

if (isset($_GET['self_delete']) && (string) $_GET['self_delete'] === '1') {
    if (@unlink(__FILE__)) {
        echo "Self-delete: success\n";
    } else {
        echo "Self-delete: failed (delete manually).\n";
    }
}
