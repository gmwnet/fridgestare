<?php
// FridgeStare Upgrade Script
// CLI-only: php upgrade.php
// Checks GitHub for latest release and upgrades files + DB.
// Leaves config.php and fridgestare.db untouched (except version key in config).

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// --- Config ---
$repoOwner = 'gmwnet';
$repoName = 'fridgestare';
$githubApi  = "https://api.github.com/repos/$repoOwner/$repoName/releases/latest";
$currentDir = __DIR__;
$dbPath     = $currentDir . '/fridgestare.db';
$configPath = $currentDir . '/config.php';
$backupDir  = $currentDir . '/_upgrade_backup';
$tmpDir     = $currentDir . '/_upgrade_tmp';

// --- Helpers ---

function json($data) { echo json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n"; }

function readConfig($path) {
    if (!file_exists($path)) return ['version' => '1.00'];
    $cfg = include $path;
    if (!is_array($cfg)) return ['version' => '1.00'];
    if (!isset($cfg['version'])) $cfg['version'] = '1.00';
    return $cfg;
}

function writeConfig($path, $cfg) {
    $export = var_export($cfg, true);
    file_put_contents($path, "<?php\nreturn " . $export . ";\n");
}

function fetchJson($url) {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "User-Agent: FridgeStare-Upgrader/1.0\r\nAccept: application/vnd.github.v3+json\r\n",
        'timeout' => 15,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return [null, 'Network error: unable to reach GitHub'];
    $data = json_decode($body, true);
    if (!is_array($data)) return [null, 'Invalid response from GitHub'];
    if (isset($data['message'])) return [null, 'GitHub API: ' . $data['message']];
    return [$data, null];
}

function backupFile($path, $backupDir) {
    if (!file_exists($path)) return;
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
    $ts = date('Ymd-His');
    $base = basename($path);
    copy($path, "{$backupDir}/{$ts}_{$base}");
}

function rrmdir($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $f) {
        if ($f->isDir()) rmdir($f->getRealPath());
        else unlink($f->getRealPath());
    }
    rmdir($dir);
}

function downloadAndExtract($url, $targetDir) {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "User-Agent: FridgeStare-Upgrader/1.0\r\n",
        'timeout' => 60,
    ]]);
    $zipData = @file_get_contents($url, false, $ctx);
    if ($zipData === false) return 'Download failed';
    $zipFile = $targetDir . '/release.zip';
    file_put_contents($zipFile, $zipData);
    $zip = new ZipArchive;
    if ($zip->open($zipFile) !== true) { unlink($zipFile); return 'Invalid zip archive'; }
    // The zip contains a root folder named {repo}-{ref}/ — extract everything inside it
    $rootLen = null;
    for ($i = 0; $i < $zip->numEntries; $i++) {
        $name = $zip->getNameIndex($i);
        $pos = strpos($name, '/');
        if ($pos !== false) {
            $rootLen = $pos + 1;
            break;
        }
    }
    if ($rootLen === null) { $zip->close(); unlink($zipFile); return 'Unexpected archive structure'; }
    $zip->extractTo($targetDir);
    $zip->close();
    unlink($zipFile);
    // Move files out of the root folder into targetDir
    $subDirs = glob($targetDir . '/*', GLOB_ONLYDIR);
    $extractedRoot = reset($subDirs);
    if ($extractedRoot && is_dir($extractedRoot)) {
        $it = new RecursiveDirectoryIterator($extractedRoot, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($files as $f) {
            $rel = substr($f->getRealPath(), strlen($extractedRoot) + 1);
            $dest = $targetDir . '/' . $rel;
            if ($f->isDir()) {
                if (!is_dir($dest)) mkdir($dest, 0755, true);
            } else {
                copy($f->getRealPath(), $dest);
            }
        }
        rrmdir($extractedRoot);
    }
    return null;
}

function copyNewFiles($sourceDir, $destDir) {
    $exclude = ['config.php', 'fridgestare.db', '_upgrade_backup', '_upgrade_tmp'];
    $it = new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);
    $copied = [];
    foreach ($files as $f) {
        $rel = substr($f->getRealPath(), strlen($sourceDir) + 1);
        if (in_array($rel, $exclude)) continue;
        $dest = $destDir . '/' . $rel;
        if ($f->isDir()) {
            if (!is_dir($dest)) mkdir($dest, 0755, true);
        } else {
            copy($f->getRealPath(), $dest);
            $copied[] = $rel;
        }
    }
    return $copied;
}

function runMigrations($db, $fromVer, $toVer) {
    // Each migration is keyed by target version and receives ($db).
    // Runs all migrations between fromVer (exclusive) and toVer (inclusive).
    $migrations = [
        // Example: '1.01' => function($db) {
        //     $db->exec("ALTER TABLE products ADD COLUMN new_col TEXT");
        // },
    ];
    $ran = 0;
    foreach ($migrations as $ver => $fn) {
        if (version_compare($ver, $fromVer) > 0 && version_compare($ver, $toVer) <= 0) {
            $fn($db);
            $ran++;
        }
    }
    return $ran;
}

function mergeConfigKeys($currentCfg, $newExamplePath) {
    if (!file_exists($newExamplePath)) return $currentCfg;
    $newExample = include $newExamplePath;
    if (!is_array($newExample)) return $currentCfg;
    $changed = false;
    foreach ($newExample as $key => $val) {
        if ($key === 'version') continue;
        if (!array_key_exists($key, $currentCfg)) {
            $currentCfg[$key] = $val;
            $changed = true;
        }
    }
    return [$currentCfg, $changed];
}

// --- Main ---

echo "FridgeStare Upgrader\n";
echo str_repeat('-', 50) . "\n";

// 1. Read current version
$cfg = readConfig($configPath);
$currentVer = $cfg['version'];
echo "Current version: $currentVer\n";

// 2. Fetch latest release from GitHub
echo "Checking GitHub for updates...\n";
list($release, $err) = fetchJson($githubApi);
if ($err) {
    echo "Error: $err\n";
    echo "You can upgrade manually by downloading from https://github.com/$repoOwner/$repoName/releases\n";
    exit(1);
}

$latestTag = ltrim($release['tag_name'] ?? '', 'v');
$latestZip = $release['zipball_url'] ?? '';
if (!$latestTag || !$latestZip) {
    echo "Error: could not determine latest release information.\n";
    exit(1);
}
echo "Latest release: $latestTag\n";

// 3. Compare
if (version_compare($currentVer, $latestTag) >= 0) {
    echo "Already up to date (v$currentVer). Nothing to do.\n";
    exit(0);
}

echo "Upgrade available: v$currentVer \u{2192} v$latestTag\n";

// 4. Confirmation
echo "\nThis will:\n";
echo "  - Back up config.php and fridgestare.db to _upgrade_backup/\n";
echo "  - Download and extract v$latestTag from GitHub\n";
echo "  - Overwrite all files except config.php and fridgestare.db\n";
echo "  - Run any database migrations\n";
echo "  - Update version in config.php to $latestTag\n";
echo "\nType 'yes' to continue: ";
$handle = fopen('php://stdin', 'r');
$input = trim(fgets($handle));
fclose($handle);
if ($input !== 'yes') {
    echo "Aborted.\n";
    exit(1);
}

// 5. Backup
echo "\nBacking up config.php and fridgestare.db...\n";
backupFile($configPath, $backupDir);
backupFile($dbPath, $backupDir);
echo "  Backup saved to _upgrade_backup/\n";

// 6. Download and extract
echo "Downloading v$latestTag...\n";
if (is_dir($tmpDir)) rrmdir($tmpDir);
mkdir($tmpDir, 0755, true);
$dlErr = downloadAndExtract($latestZip, $tmpDir);
if ($dlErr) {
    echo "Error: $dlErr\n";
    echo "Backup files are in _upgrade_backup/. You can restore manually.\n";
    rrmdir($tmpDir);
    exit(1);
}
echo "  Downloaded and extracted.\n";

// 7. Copy new files
echo "Copying new files...\n";
$copied = copyNewFiles($tmpDir, $currentDir);
echo "  " . count($copied) . " files updated.\n";

// 8. Run DB migrations
echo "Checking database migrations...\n";
if (file_exists($dbPath)) {
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->exec("PRAGMA journal_mode=WAL");
        $migrated = runMigrations($db, $currentVer, $latestTag);
        echo "  $migrated migration(s) applied.\n";
    } catch (Exception $e) {
        echo "  Error running migrations: " . $e->getMessage() . "\n";
        echo "  Your data is backed up in _upgrade_backup/. You may need to restore.\n";
        rrmdir($tmpDir);
        exit(1);
    }
} else {
    echo "  No database found — skipping migrations (fresh install).\n";
}

// 9. Merge new config keys from the new config.example.php
echo "Checking for new config keys...\n";
$newExamplePath = $currentDir . '/config.example.php';
list($mergedCfg, $keysAdded) = mergeConfigKeys($cfg, $newExamplePath);
if ($keysAdded) {
    echo "  New config keys detected — appending defaults.\n";
}
$mergedCfg['version'] = $latestTag;
writeConfig($configPath, $mergedCfg);
echo "  Version updated to $latestTag in config.php\n";

// 10. Clean up
rrmdir($tmpDir);

echo "\n" . str_repeat('-', 50) . "\n";
echo "Upgrade complete! FridgeStare is now at v$latestTag.\n";
echo "Backup files are in _upgrade_backup/ — you can delete them after confirming everything works.\n";
