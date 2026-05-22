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
$versionBackup = $currentDir . '/_version_backups';
$tmpDir        = $currentDir . '/_upgrade_tmp';

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
    $zip->extractTo($targetDir);
    $zip->close();
    unlink($zipFile);
    // GitHub zipballs have a root folder like {repo}-{sha}/ — move contents up
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

function zipBackupSite($sourceDir, $destDir, $version) {
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $ts = date('Ymd-His');
    $zipFile = "{$destDir}/fridgestare-v{$version}-{$ts}.zip";
    $zip = new ZipArchive;
    if ($zip->open($zipFile, ZipArchive::CREATE) !== true) return null;
    $exclude = ['_version_backups', '_upgrade_tmp'];
    $it = new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $f) {
        $rel = substr($f->getRealPath(), strlen($sourceDir) + 1);
        $parts = explode(DIRECTORY_SEPARATOR, $rel);
        if (in_array($parts[0], $exclude)) continue;
        if ($f->isDir()) { $zip->addEmptyDir($rel); }
        else { $zip->addFile($f->getRealPath(), $rel); }
    }
    $zip->close();
    return $zipFile;
}

function copyNewFiles($sourceDir, $destDir) {
    $exclude = ['config.php', 'fridgestare.db', '_version_backups', '_upgrade_tmp'];
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
    $pending = pendingMigrations($fromVer, $toVer);
    $ran = 0;
    foreach ($pending as $fn) {
        $fn($db);
        $ran++;
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

function pendingMigrations($fromVer, $toVer) {
    $migrations = [
        // Example: '1.01' => function($db) {
        //     $db->exec("ALTER TABLE products ADD COLUMN new_col TEXT");
        // },
    ];
    $pending = [];
    foreach ($migrations as $ver => $fn) {
        if (version_compare($ver, $fromVer) > 0 && version_compare($ver, $toVer) <= 0) {
            $pending[$ver] = $fn;
        }
    }
    return $pending;
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
echo "  - Create a full-site zip snapshot in _version_backups/\n";
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

// 5. Full-site zip snapshot
echo "\nCreating full-site snapshot...\n";
$snapshot = zipBackupSite($currentDir, $versionBackup, $currentVer);
if ($snapshot) {
    echo "  Snapshot saved: $snapshot\n";
} else {
    echo "  Warning: could not create zip snapshot (continuing anyway).\n";
}

// 6. Download and extract
echo "Downloading v$latestTag...\n";
if (is_dir($tmpDir)) rrmdir($tmpDir);
mkdir($tmpDir, 0755, true);
$dlErr = downloadAndExtract($latestZip, $tmpDir);
if ($dlErr) {
    echo "Error: $dlErr\n";
    echo "The snapshot in _version_backups/ is intact — nothing has changed.\n";
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
$pending = pendingMigrations($currentVer, $latestTag);
if (file_exists($dbPath)) {
    if (count($pending) > 0) {
        if (!is_writable($dbPath)) {
            echo "  Warning: database is not writable by this user — skipping migrations.\n";
            echo "  Run this script as the web server user or chmod the database.\n";
        } else {
            try {
                $db = new PDO('sqlite:' . $dbPath);
                $db->exec("PRAGMA journal_mode=WAL");
                $migrated = runMigrations($db, $currentVer, $latestTag);
                echo "  $migrated migration(s) applied.\n";
            } catch (Exception $e) {
                echo "  Error running migrations: " . $e->getMessage() . "\n";
                echo "  Restore from the snapshot in _version_backups/ if needed.\n";
                rrmdir($tmpDir);
                exit(1);
            }
        }
    } else {
        echo "  No pending migrations — skipping.\n";
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
echo "\nA full-site snapshot was saved to _version_backups/.\n";
echo "Old snapshots there are harmless to leave — delete them whenever you want.\n";
