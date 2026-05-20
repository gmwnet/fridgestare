<?php
// FridgeStare — Grocery UPC Scanner
// Single-file PHP app: scan barcodes, look up products, manage inventory

$dbPath = __DIR__ . '/groscan.db';
$cfg = (file_exists(__DIR__ . '/config.php')) ? include __DIR__ . '/config.php' : [];

// --- UPC Lookup Providers ---

abstract class UpcLookupProvider {
    abstract public function lookup($upc);
    // Returns: ['name'=>?, 'brand'=>?, 'category'=>?, 'quantity'=>?, 'image_url'=>?] or null
}

class OpenFoodFactsProvider extends UpcLookupProvider {
    private $apiBase = 'https://world.openfoodfacts.org/api/v2/product/';

    public function lookup($upc) {
        $url = $this->apiBase . $upc . '.json';
        $response = null;
        $httpCode = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_USERAGENT => 'FridgeStare/1.0',
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $response = @curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'FridgeStare/1.0']]);
            $response = @file_get_contents($url, false, $ctx);
            $httpCode = isset($http_response_header) ? (int)explode(' ', $http_response_header[0])[1] : 0;
        }
        if ($response === false || $httpCode !== 200) return null;
        $data = json_decode($response, true);
        if (($data['status'] ?? 0) !== 1) return null;
        $p = $data['product'] ?? [];
        return [
            'name'     => $p['product_name'] ?? null,
            'brand'    => $p['brands'] ?? null,
            'category' => $p['categories'] ?? null,
            'quantity' => $p['quantity'] ?? null,
            'image_url'=> $p['image_url'] ?? null,
        ];
    }
}

class UpcItemDbProvider extends UpcLookupProvider {
    private $apiKey;

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function lookup($upc) {
        $url = 'https://api.upcitemdb.com/prod/trial/lookup?upc=' . $upc;
        $response = null;
        $httpCode = 0;
        $headers = ['Accept: application/json'];
        if ($this->apiKey) $headers[] = 'user_key: ' . $this->apiKey;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_USERAGENT => 'FridgeStare/1.0',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $response = @curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'FridgeStare/1.0', 'header' => implode("\r\n", $headers)]]);
            $response = @file_get_contents($url, false, $ctx);
            $httpCode = isset($http_response_header) ? (int)explode(' ', $http_response_header[0])[1] : 0;
        }
        if ($response === false || $httpCode !== 200) return null;
        $data = json_decode($response, true);
        $items = $data['items'] ?? [];
        if (empty($items)) return null;
        $item = $items[0];
        return [
            'name'     => $item['title'] ?? null,
            'brand'    => $item['brand'] ?? null,
            'category' => $item['category'] ?? null,
            'quantity' => null,
            'image_url'=> ($item['images'] ?? [])[0] ?? null,
        ];
    }
}

// --- DB Init ---
$db = new PDO("sqlite:$dbPath");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("
    CREATE TABLE IF NOT EXISTS products (
        upc         TEXT PRIMARY KEY,
        name        TEXT,
        brand       TEXT,
        category    TEXT,
        quantity    TEXT,
        image_url   TEXT,
        tags        TEXT,
        fetched_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS inventory (
        upc         TEXT PRIMARY KEY,
        quantity    INTEGER DEFAULT 0,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS ledger (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        upc         TEXT NOT NULL,
        action      TEXT NOT NULL,
        user_id     INTEGER,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS users (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL UNIQUE,
        pin_hash    TEXT NOT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS rate_limits (
        ip          TEXT PRIMARY KEY,
        attempts    INTEGER DEFAULT 0,
        locked_until DATETIME
    );
");
try { $db->exec("ALTER TABLE ledger ADD COLUMN user_id INTEGER"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE ledger ADD COLUMN details TEXT"); } catch (PDOException $e) {}
try { $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, pin_hash TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"); } catch (PDOException $e) {}
try { $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (ip TEXT PRIMARY KEY, attempts INTEGER DEFAULT 0, locked_until DATETIME)"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE products ADD COLUMN tags TEXT"); } catch (PDOException $e) {}

// Insert default user if table is empty
$countStmt = $db->query("SELECT COUNT(*) FROM users");
if ($countStmt && (int)$countStmt->fetchColumn() === 0) {
    $defaultHash = password_hash('1234', PASSWORD_DEFAULT);
    $db->exec("INSERT INTO users (name, pin_hash) VALUES ('default user', '$defaultHash')");
}

// --- Helpers ---
function normalizeUpc($upc) {
    $digits = preg_replace('/[^0-9]/', '', $upc);
    $len = strlen($digits);
    if ($len === 12) return '0' . $digits;
    if ($len === 14) return substr($digits, 1);
    return $digits;
}

function getUpcVariants($rawUpc) {
    $d = preg_replace('/[^0-9]/', '', $rawUpc);
    $variants = [normalizeUpc($rawUpc)];
    if (strlen($d) === 12) {
        $variants[] = $d;
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += (int)$d[$i] * ($i % 2 === 0 ? 1 : 3);
        $variants[] = $d . ((10 - ($sum % 10)) % 10);
    }
    return array_unique($variants);
}

function formatTimestamp($utcString, $cfg) {
    $tz = $cfg['timezone'] ?? 'UTC';
    try {
        $dt = new DateTime($utcString, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format('M j g:i A');
    } catch (Exception $e) {
        return $utcString;
    }
}

function logAdminAction($db, $action, $details, $userId) {
    dbExecWithRetry($db, "INSERT INTO ledger (upc, action, user_id, details) VALUES ('', ?, ?, ?)", [$action, $userId, $details]);
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function dbExecWithRetry($db, $sql, $params = []) {
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            if ($e->getCode() !== 'HY000' || $attempt === 3) {
                throw $e;
            }
            usleep(50000);
        }
    }
}

function clientIp() {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return preg_replace('/[^0-9a-f.:]/', '', explode(',', $_SERVER[$k])[0]);
    }
    return '0.0.0.0';
}

// --- Router ---
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$uri = rtrim($uri, '/') ?: '/';

// --- API: Lookup ---
if ($uri === '/api/lookup' && $method === 'GET') {
    $rawUpc = $_GET['upc'] ?? '';
    if (!preg_match('/^\d{8,14}$/', $rawUpc)) {
        jsonResponse(['error' => 'Invalid UPC'], 400);
    }
    $upc = normalizeUpc($rawUpc);
    $variants = getUpcVariants($rawUpc);

    // Check local cache first — skip network if found
    $product = null;
    foreach ($variants as $v) {
        $stmt = $db->prepare("SELECT * FROM products WHERE upc = ?");
        $stmt->execute([$v]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['name']) { $product = $row; $upc = $v; break; }
    }
    if ($product) {
        $stmt = $db->prepare("SELECT quantity FROM inventory WHERE upc = ?");
        $stmt->execute([$upc]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        jsonResponse([
            'upc' => $upc,
            'product' => [
                'name' => $product['name'],
                'brand' => $product['brand'],
                'category' => $product['category'],
                'quantity' => $product['quantity'],
                'image_url' => $product['image_url'],
                'tags' => $product['tags'] ? json_decode($product['tags'], true) : [],
            ],
            'inventory_qty' => $inv ? (int)$inv['quantity'] : 0,
            'warning' => null,
        ]);
    }

    $warning = null;
    $productData = null;
    $provider = new OpenFoodFactsProvider();
    foreach ($variants as $v) {
        $productData = $provider->lookup($v);
        if ($productData !== null) { $upc = $v; break; }
        $productData = (new UpcItemDbProvider($cfg['upcitemdb_key'] ?? ''))->lookup($v);
        if ($productData !== null) { $upc = $v; break; }
    }
    if ($productData !== null) {
        // Cache fresh data
        $productData['upc'] = $upc;
        $stmt = $db->prepare(
            "INSERT OR REPLACE INTO products (upc, name, brand, category, quantity, image_url, tags)
             VALUES (?, ?, ?, ?, ?, ?, COALESCE((SELECT tags FROM products WHERE upc = ?), NULL))"
        );
        $stmt->execute([
            $upc, $productData['name'], $productData['brand'],
            $productData['category'], $productData['quantity'], $productData['image_url'], $upc
        ]);
        $product = $productData;
    } else {
        $product = null;
        $warning = 'Could not look up product.';
    }

    $stmt = $db->prepare("SELECT quantity FROM inventory WHERE upc = ?");
    $stmt->execute([$upc]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    jsonResponse([
        'upc'          => $upc,
        'product'      => $product ? [
            'name'     => $product['name'],
            'brand'    => $product['brand'],
            'category' => $product['category'],
            'quantity' => $product['quantity'],
            'image_url'=> $product['image_url'],
            'tags'     => !empty($product['tags']) ? json_decode($product['tags'], true) : [],
        ] : null,
        'inventory_qty' => $inv ? (int)$inv['quantity'] : 0,
        'warning'      => $warning,
    ]);
}

// --- API: Action ---
if ($uri === '/api/action' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $rawUpc = $input['upc'] ?? '';
    $action = $input['action'] ?? '';
    if (!preg_match('/^\d{8,14}$/', $rawUpc) || !in_array($action, ['add', 'take'])) {
        jsonResponse(['error' => 'Invalid request'], 400);
    }
    $upc = normalizeUpc($rawUpc);
    $name = $input['name'] ?? null;
    $brand = $input['brand'] ?? null;
    $qty = isset($input['qty']) ? (int)$input['qty'] : 1;
    if ($qty < 1) $qty = 1;
    $userId = $input['user_id'] ?? null;
    if ($userId !== null) $userId = (int)$userId;
    if ($name !== null || $brand !== null) {
        $existing = $db->prepare("SELECT COUNT(*) FROM products WHERE upc = ?");
        $existing->execute([$upc]);
        if ($existing->fetchColumn() > 0) {
            dbExecWithRetry($db, "UPDATE products SET name = COALESCE(NULLIF(?, ''), name), brand = COALESCE(NULLIF(?, ''), brand) WHERE upc = ?", [$name, $brand, $upc]);
        } else {
            dbExecWithRetry($db, "INSERT INTO products (upc, name, brand) VALUES (?, ?, ?)", [$upc, $name, $brand]);
        }
    }
    dbExecWithRetry($db, "INSERT INTO ledger (upc, action, user_id) VALUES (?, ?, ?)", [$upc, $action, $userId]);
    if ($action === 'add') {
        dbExecWithRetry($db,
            "INSERT INTO inventory (upc, quantity, updated_at)
             VALUES (?, ?, datetime('now'))
             ON CONFLICT(upc) DO UPDATE SET quantity = quantity + ?, updated_at = datetime('now')",
            [$upc, $qty, $qty]
        );
    } else {
        dbExecWithRetry($db,
            "INSERT INTO inventory (upc, quantity, updated_at)
             VALUES (?, 0, datetime('now'))
             ON CONFLICT(upc) DO UPDATE SET
               quantity = CASE WHEN quantity >= ? THEN quantity - ? ELSE 0 END,
               updated_at = datetime('now')",
            [$upc, $qty, $qty]
        );
        dbExecWithRetry($db, "DELETE FROM inventory WHERE upc = ? AND quantity = 0", [$upc]);
    }
    $stmt = $db->prepare("SELECT quantity FROM inventory WHERE upc = ?");
    $stmt->execute([$upc]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    jsonResponse(['success' => true, 'new_qty' => $inv ? (int)$inv['quantity'] : 0]);
}

// --- API: Inventory ---
if ($uri === '/api/inventory' && $method === 'GET') {
    $stmt = $db->query(
        "SELECT i.upc, COALESCE(p.name, 'Unknown Product') AS name, i.quantity, i.updated_at
         FROM inventory i
         LEFT JOIN products p ON i.upc = p.upc
         WHERE i.quantity > 0
         ORDER BY name COLLATE NOCASE ASC"
    );
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['items' => array_map(function ($r) {
        return ['upc' => $r['upc'], 'name' => $r['name'], 'qty' => (int)$r['quantity']];
    }, $items)]);
}

// --- API: Ledger ---
if ($uri === '/api/ledger' && $method === 'GET') {
    $stmt = $db->query(
        "SELECT l.id, l.upc, COALESCE(p.name, l.details, 'Unknown') AS name, l.action, l.created_at, l.details, u.name AS user
         FROM ledger l
         LEFT JOIN products p ON l.upc = p.upc
         LEFT JOIN users u ON l.user_id = u.id
         ORDER BY l.id DESC
         LIMIT 200"
    );
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($entries as &$e) {
        $e['created_at'] = formatTimestamp($e['created_at'], $cfg);
    }
    unset($e);
    jsonResponse(['entries' => $entries]);
}

// --- API: Search ---
if ($uri === '/api/search' && $method === 'GET') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { jsonResponse(['results' => []]); }
    $stmt = $db->prepare(
        "SELECT DISTINCT upc, name, brand FROM products WHERE name LIKE ? ORDER BY name LIMIT 10"
    );
    $stmt->execute(['%' . $q . '%']);
    jsonResponse(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// --- API: Health Check ---
if ($uri === '/api/health' && $method === 'GET') {
    $hasZbar = false;
    if (function_exists('shell_exec')) {
        $zbarPath = trim(@shell_exec('which zbarimg 2>/dev/null') ?: '');
        $hasZbar = $zbarPath && file_exists($zbarPath);
    }
    jsonResponse([
        'ok' => true,
        'version' => '0.1',
        'php' => PHP_VERSION,
        'sqlite' => $db->getAttribute(PDO::ATTR_SERVER_VERSION),
        'zbarimg' => $hasZbar,
        'timezone' => $cfg['timezone'] ?? 'UTC',
    ]);
}

// --- API: Scan Photo (safety-net fallback) ---
if ($uri === '/api/scan-photo' && $method === 'POST') {
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Photo upload failed';
        if (!empty($_FILES['photo']['error'])) {
            switch ($_FILES['photo']['error']) {
                case UPLOAD_ERR_INI_SIZE: $err = 'Photo too large. Increase upload_max_filesize in php.ini.'; break;
                case UPLOAD_ERR_FORM_SIZE: $err = 'Photo too large.'; break;
                case UPLOAD_ERR_PARTIAL: $err = 'Photo partially uploaded.'; break;
                case UPLOAD_ERR_NO_FILE: $err = 'No photo received.'; break;
            }
        }
        jsonResponse(['success' => false, 'error' => $err], 400);
    }

    $tmpFile = $_FILES['photo']['tmp_name'];
    $upc = null;
    $method = null;

    // Prefer zbarimg command-line tool
    if (function_exists('shell_exec')) {
        $zbarPath = trim(@shell_exec('which zbarimg 2>/dev/null') ?: '');
        if ($zbarPath && file_exists($zbarPath)) {
            $cmd = 'zbarimg --quiet --raw -S*.disable -Sean13.enable -Supca.enable -Sean8.enable -Supce.enable -Scode128.enable -Scode39.enable -Si25.enable '
                 . escapeshellarg($tmpFile) . ' 2>/dev/null';
            $output = @shell_exec($cmd);
            if ($output) {
                $lines = array_filter(explode("\n", trim($output)));
                foreach ($lines as $line) {
                    $parts = explode(':', $line, 2);
                    $code = isset($parts[1]) ? $parts[1] : $line;
                    $code = preg_replace('/[^0-9]/', '', $code);
                    if (preg_match('/^\d{8,14}$/', $code)) {
                        $upc = normalizeUpc($code);
                        $method = 'zbarimg';
                        break;
                    }
                }
            }
        }
    }

    @unlink($tmpFile);

    if ($upc) {
        jsonResponse(['success' => true, 'upc' => $upc, 'method' => $method]);
    } else {
        jsonResponse(['success' => false, 'error' => 'No barcode found in photo. Try manual entry.']);
    }
}

// --- API: Tag ---
if ($uri === '/api/tag' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $rawUpc = $input['upc'] ?? '';
    $tags = $input['tags'] ?? [];
    if (!preg_match('/^\d{8,14}$/', $rawUpc) || !is_array($tags)) {
        jsonResponse(['error' => 'Invalid request'], 400);
    }
    $upc = normalizeUpc($rawUpc);
    $validTags = ['Protein', 'Main', 'Sauce', 'Side', 'Snack', 'Dessert', 'Use Soon', 'Staple'];
    $cleanTags = array_values(array_filter($tags, function($t) use ($validTags) { return in_array($t, $validTags, true); }));
    $stmt = $db->prepare(
        "INSERT INTO products (upc, name, brand, category, quantity, image_url, tags, fetched_at)
         VALUES (?, NULL, NULL, NULL, NULL, NULL, ?, datetime('now'))
         ON CONFLICT(upc) DO UPDATE SET tags = excluded.tags"
    );
    $stmt->execute([$upc, json_encode($cleanTags)]);
    jsonResponse(['success' => true, 'tags' => $cleanTags]);
}

// --- API: Config ---
if ($uri === '/api/config' && $method === 'GET') {
    jsonResponse($cfg);
}
if ($uri === '/api/config' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) jsonResponse(['error' => 'Invalid request'], 400);
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;

    $allowed = ['upcitemdb_key','turnstile_site_key','turnstile_secret_key',
                'timezone','session_timeout_days','pin_max_attempts',
                'pin_lockout_hours','default_qty','debug'];
    $newCfg = $cfg;
    foreach ($allowed as $k) {
        if (array_key_exists($k, $input)) {
            $val = $input[$k];
            if (in_array($k, ['upcitemdb_key','turnstile_site_key','turnstile_secret_key'])) {
                $val = trim(strip_tags((string)$val));
                if (strlen($val) > 512) $val = substr($val, 0, 512);
            }
            $newCfg[$k] = $val;
        }
    }
    $export = var_export($newCfg, true);
    file_put_contents(__DIR__ . '/config.php', "<?php\nreturn " . $export . ";\n");

    $changes = [];
    $labels = [
        'upcitemdb_key' => 'UPCItemDB key',
        'turnstile_site_key' => 'Turnstile site key',
        'turnstile_secret_key' => 'Turnstile secret key',
        'timezone' => 'timezone',
        'session_timeout_days' => 'session timeout',
        'pin_max_attempts' => 'PIN max attempts',
        'pin_lockout_hours' => 'PIN lockout duration',
        'default_qty' => 'default quantity',
        'debug' => 'debug mode',
    ];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $input)) $changes[] = $labels[$k] ?? $k;
    }
    if ($changes) {
        logAdminAction($db, 'config_change', 'Updated ' . implode(', ', $changes), $userId);
    }
    jsonResponse(['success' => true]);
}

// --- API: Users ---
function pinExists($db, $pin) {
    $stmt = $db->query("SELECT pin_hash FROM users");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $hash) {
        if (password_verify($pin, $hash)) return true;
    }
    return false;
}

if ($uri === '/api/users' && $method === 'GET') {
    $stmt = $db->query("SELECT id, name, created_at FROM users ORDER BY name");
    jsonResponse(['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($uri === '/api/user' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['name'] ?? '');
    $pin = $input['pin'] ?? '';
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
    if (!$name || !preg_match('/^\d{4,8}$/', $pin)) {
        jsonResponse(['error' => 'Name and 4-8 digit PIN required'], 400);
    }
    if (pinExists($db, $pin)) {
        jsonResponse(['error' => 'That PIN is already in use. Choose a different one.'], 409);
    }
    $hash = password_hash($pin, PASSWORD_DEFAULT);
    try {
        dbExecWithRetry($db, "INSERT INTO users (name, pin_hash) VALUES (?, ?)", [$name, $hash]);
        logAdminAction($db, 'user_add', "Added user '$name'", $userId);
        jsonResponse(['success' => true]);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Name already exists'], 409);
    }
}

if (preg_match('#^/api/user/(\d+)$#', $uri, $m) && $method === 'POST') {
    $id = (int)$m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $name = isset($input['name']) ? trim($input['name']) : null;
    $pin = $input['pin'] ?? null;
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;

    $updates = [];
    $params = [];
    if ($name !== null && $name !== '') {
        $updates[] = "name = ?";
        $params[] = $name;
    }
    if ($pin !== null) {
        if (!preg_match('/^\d{4,8}$/', $pin)) {
            jsonResponse(['error' => 'PIN must be 4-8 digits'], 400);
        }
        if (pinExists($db, $pin)) {
            jsonResponse(['error' => 'That PIN is already in use. Choose a different one.'], 409);
        }
        $updates[] = "pin_hash = ?";
        $params[] = password_hash($pin, PASSWORD_DEFAULT);
    }
    if (empty($updates)) jsonResponse(['error' => 'Nothing to update'], 400);
    $params[] = $id;
    dbExecWithRetry($db, "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?", $params);

    $stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $oldName = $stmt->fetchColumn();
    if ($pin !== null) {
        logAdminAction($db, 'user_update', "Changed PIN for '$oldName'", $userId);
    }
    if ($name !== null) {
        logAdminAction($db, 'user_update', "Renamed user to '$name'", $userId);
    }
    jsonResponse(['success' => true]);
}

if (preg_match('#^/api/user/(\d+)$#', $uri, $m) && $method === 'DELETE') {
    $id = (int)$m[1];
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;

    $stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $name = $stmt->fetchColumn();
    if (!$name) jsonResponse(['error' => 'User not found'], 404);

    $count = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count <= 1) jsonResponse(['error' => 'Cannot delete the last user'], 400);

    dbExecWithRetry($db, "DELETE FROM users WHERE id = ?", [$id]);
    logAdminAction($db, 'user_delete', "Deleted user '$name'", $userId);
    jsonResponse(['success' => true]);
}

if ($uri === '/api/user/change-pin' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? (int)$input['id'] : null;
    $newPin = $input['new_pin'] ?? '';
    if (!$id || !preg_match('/^\d{4,8}$/', $newPin)) {
        jsonResponse(['error' => 'User ID and 4-8 digit new PIN required'], 400);
    }
    if (pinExists($db, $newPin)) {
        jsonResponse(['error' => 'That PIN is already in use. Choose a different one.'], 409);
    }
    $stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $name = $stmt->fetchColumn();
    if (!$name) jsonResponse(['error' => 'User not found'], 404);

    dbExecWithRetry($db, "UPDATE users SET pin_hash = ? WHERE id = ?", [password_hash($newPin, PASSWORD_DEFAULT), $id]);
    logAdminAction($db, 'user_update', "Changed PIN for '$name'", $id);
    jsonResponse(['success' => true]);
}

// --- API: Emergency Unlock ---
if ($uri === '/api/emergency-unlock' && $method === 'POST') {
    if (empty($cfg['emergency_unlock'])) {
        jsonResponse(['error' => 'Emergency unlock not enabled. Set emergency_unlock => true in config.php first.'], 403);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
    $count = $db->exec("DELETE FROM rate_limits");
    logAdminAction($db, 'emergency_unlock', "Cleared all PIN lockouts ($count rows)", $userId);
    jsonResponse(['success' => true, 'cleared' => (int)$count, 'note' => "Set emergency_unlock back to false in config.php now."]);
}

// --- API: Auth ---
if ($uri === '/api/auth' && $method === 'POST') {
    $ip = clientIp();
    $stmt = $db->prepare("SELECT attempts, locked_until FROM rate_limits WHERE ip = ?");
    $stmt->execute([$ip]);
    $rl = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($rl && $rl['locked_until']) {
        $lockedUntil = strtotime($rl['locked_until']);
        if ($lockedUntil > time()) {
            $remaining = ceil(($lockedUntil - time()) / 60);
            jsonResponse(['error' => "Too many attempts. Try again in {$remaining} minutes."], 429);
        }
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $pin = $input['pin'] ?? '';
    if (!preg_match('/^\d{4,8}$/', $pin)) {
        jsonResponse(['error' => 'PIN must be 4\u20138 digits'], 400);
    }
    $turnstileSecret = $cfg['turnstile_secret_key'] ?? '';
    if ($turnstileSecret) {
        $token = $input['turnstile_token'] ?? '';
        if (!$token) jsonResponse(['error' => 'Captcha required'], 400);
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['secret' => $turnstileSecret, 'response' => $token])]);
        $v = json_decode(@curl_exec($ch), true);
        curl_close($ch);
        if (!($v['success'] ?? false)) jsonResponse(['error' => 'Captcha failed. Try again.'], 403);
    }
    $stmt = $db->prepare("SELECT id, name, pin_hash FROM users");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $match = null;
    foreach ($users as $u) {
        if (password_verify($pin, $u['pin_hash'])) { $match = $u; break; }
    }
    if (!$match) {
        $maxAttempts = $cfg['pin_max_attempts'] ?? 3;
        $lockoutHours = $cfg['pin_lockout_hours'] ?? 1;
        dbExecWithRetry($db,
            "INSERT INTO rate_limits (ip, attempts, locked_until) VALUES (?, 1, NULL)
             ON CONFLICT(ip) DO UPDATE SET attempts = CASE WHEN locked_until IS NULL OR locked_until < datetime('now') THEN 1 ELSE attempts + 1 END",
            [$ip]
        );
        $stmt = $db->prepare("SELECT attempts FROM rate_limits WHERE ip = ?");
        $stmt->execute([$ip]);
        $attempts = (int)$stmt->fetchColumn();
        if ($attempts >= $maxAttempts) {
            dbExecWithRetry($db, "UPDATE rate_limits SET locked_until = datetime('now', '+" . (int)$lockoutHours . " hour') WHERE ip = ?", [$ip]);
            jsonResponse(['error' => "Too many attempts. Locked out for {$lockoutHours} hour(s)."], 429);
        }
        jsonResponse(['error' => 'PIN not recognized'], 401);
    }
    dbExecWithRetry($db, "DELETE FROM rate_limits WHERE ip = ?", [$ip]);
    jsonResponse(['user' => ['id' => (int)$match['id'], 'name' => $match['name']]]);
}

// --- Page routes ---
$page = 'scan';
if ($uri === '/inventory') $page = 'inventory';
if ($uri === '/ledger') $page = 'ledger';
if ($uri === '/settings') $page = 'settings';
if ($uri === '/users') $page = 'users';
$navItems = [
    '/'         => ['label' => 'Scanner', 'icon' => '📷'],
    '/inventory' => ['label' => 'Inventory', 'icon' => '📋'],
    '/ledger'    => ['label' => 'Ledger', 'icon' => '📜'],
    '/settings'  => ['label' => 'Settings', 'icon' => '⚙️'],
    '/users'     => ['label' => 'Users', 'icon' => '👤'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<meta name="theme-color" content="#111">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<title>FridgeStare</title>
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="manifest" href="/site.webmanifest">
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#000;color:#fff;height:100dvh;display:flex;flex-direction:column;padding:48px 0 46px;overflow-x:hidden;overscroll-behavior:none}
#navBar{position:fixed;top:0;left:0;right:0;height:48px;display:flex;align-items:center;padding:0 12px;background:#1a1a1a;border-bottom:1px solid #333;z-index:110}
#menuBtn{background:none;border:none;color:#fff;font-size:26px;cursor:pointer;padding:4px 8px;margin-right:10px;line-height:1}
#pageTitle{font-size:17px;font-weight:600}
#sideMenu{position:fixed;top:0;left:0;width:260px;height:100dvh;background:#1a1a1a;z-index:200;transform:translateX(-100%);transition:transform .25s;padding:8px 0 0 0}
#sideMenu.open{transform:translateX(0)}
#sideMenu a{display:flex;align-items:center;gap:12px;padding:16px 20px;color:#ccc;text-decoration:none;font-size:16px;border-bottom:1px solid #222}
#sideMenu a.active{color:#fff;background:#333;border-left:3px solid #007aff}
#overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:150;display:none}
#overlay.show{display:block}
#flash{position:fixed;top:60px;left:50%;transform:translateX(-50%);padding:16px 32px;border-radius:12px;font-size:20px;font-weight:700;z-index:300;display:none;pointer-events:none}
#flash.show{display:block}
#flash.add{background:#34c759;color:#fff}
#flash.take{background:#ff9500;color:#fff}
#scanner{flex:1;position:relative;background:#111;overflow:hidden;display:flex;flex-direction:column;align-items:center;justify-content:center}
#scanPrompt{display:flex;flex-direction:column;align-items:center;justify-content:center}
#scanPrompt p{color:#fff;font-size:18px;font-weight:500;margin-top:16px}
#scanPrompt .hint{color:#888;font-size:14px;margin-top:6px}
#result{position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.85);padding:10px 12px;transform:translateY(100%);transition:transform .3s}
#result.show{transform:translateY(0)}
.edit-field{width:100%;padding:14px 16px;font-size:20px;background:#222;border:1px solid #555;border-radius:8px;color:#fff;margin-bottom:6px;outline:none}
.edit-field:focus{border-color:#007aff}
.edit-field::placeholder{color:#666}
#suggestions{position:absolute;left:0;right:0;top:100%;background:#222;border:1px solid #555;border-top:none;border-radius:0 0 8px 8px;max-height:180px;overflow-y:auto;display:none;z-index:10}
#suggestions.show{display:block}
#suggestions div{padding:10px 14px;font-size:15px;cursor:pointer;border-bottom:1px solid #333}
#suggestions div:last-child{border-bottom:none}
#suggestions div:hover,#suggestions div.active{background:#333}
#suggestions .sug-brand{font-size:12px;color:#888}
.actions{display:flex;gap:12px}
.actions button{flex:1;padding:16px;font-size:18px;font-weight:600;border:none;border-radius:12px;cursor:pointer;touch-action:manipulation}
#btnAdd{background:#34c759;color:#fff}
#btnTake{background:#ff9500;color:#fff}
#manual{position:fixed;bottom:0;left:0;right:0;display:flex;gap:6px;padding:6px 8px;background:#1a1a1a;z-index:60}
#manual input{flex:1;padding:10px 36px 10px 12px;font-size:16px;border:1px solid #555;border-radius:8px;background:#222;color:#fff}
#manual button{padding:10px 16px;font-size:16px;border:none;border-radius:8px;background:#007aff;color:#fff;cursor:pointer}
#clearUpc{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:#666;font-size:20px;cursor:pointer;line-height:1;padding:2px 6px;display:none;z-index:5}
#manualInputWrap{position:relative;flex:1;display:flex}
#btnSnap{width:120px;height:120px;border-radius:50%;border:6px solid #fff;background:#34c759;color:#fff;font-size:56px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 24px rgba(0,0,0,.5);touch-action:manipulation}
#btnSnap:active{transform:scale(.92)}
#btnSnap:disabled{opacity:.5}
#photoOverlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.88);z-index:300;display:none;flex-direction:column;align-items:center;justify-content:center;padding:16px}
#photoOverlay.show{display:flex}
#photoThumb{max-width:85vw;max-height:45vh;border-radius:8px;margin-bottom:12px;object-fit:contain}
#photoStatus{color:#fff;font-size:16px;margin-bottom:16px;text-align:center}
#photoRetry, #photoCancel{background:none;border:1px solid #555;border-radius:8px;color:#fff;padding:10px 20px;font-size:15px;cursor:pointer;margin:0 6px}
#photoRetry{background:#007aff;border-color:#007aff}
#tagOverlay{position:fixed;top:48px;left:0;right:0;bottom:0;background:rgba(0,0,0,.92);z-index:250;display:none;flex-direction:column;align-items:center;justify-content:flex-start;padding:24px 16px 16px;overflow-y:auto}
#tagOverlay.show{display:flex}
#tagOverlay h3{color:#fff;font-size:20px;margin-bottom:6px;text-align:center}
#tagOverlay .tag-sub{color:#888;font-size:14px;margin-bottom:20px;text-align:center}
.tag-list{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-bottom:24px;max-width:400px}
.tag-btn{padding:10px 18px;border:2px solid #555;border-radius:24px;background:#1a1a1a;color:#ccc;font-size:15px;cursor:pointer;touch-action:manipulation;transition:all .15s}
.tag-btn.active{border-color:#34c759;background:#34c75922;color:#34c759}
.result-tags .tag-btn{padding:4px 10px;font-size:12px}
#tagActions{display:flex;gap:10px;width:100%;max-width:400px}
#tagActions button{flex:1;padding:12px;font-size:16px;font-weight:600;border:none;border-radius:10px;cursor:pointer}
#btnTagSave{background:#34c759;color:#fff}
#btnTagSkip{background:#555;color:#fff}
#banner{position:fixed;top:48px;left:0;right:0;background:#ff9500;color:#000;padding:8px 16px;font-size:13px;text-align:center;z-index:100;display:none}
#errorOverlay{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);padding:32px 48px;border-radius:16px;font-size:20px;font-weight:600;z-index:300;display:none;text-align:center;background:#ff3b30;color:#fff;min-width:200px;max-width:80vw;line-height:1.4}
#errorOverlay.show{display:block}
#errorClose{position:absolute;top:2px;right:10px;background:none;border:none;color:#fff;font-size:36px;cursor:pointer;font-weight:700;line-height:1;padding:4px 8px}
#pinOverlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.85);z-index:500;align-items:center;justify-content:center}
#pinBox{background:#1a1a1a;padding:32px;border-radius:16px;text-align:center;min-width:300px}
#pinBox h2{font-size:22px;margin-bottom:4px}
#pinBox p{color:#888;font-size:14px;margin-bottom:20px}
#pinBox input{width:100%;padding:14px 16px;font-size:24px;text-align:center;border:1px solid #555;border-radius:8px;background:#222;color:#fff;outline:none;letter-spacing:8px}
#pinBox input:focus{border-color:#007aff}
#pinBox .pinBtns{display:flex;gap:10px;margin-top:16px}
#pinBox .pinBtns button{flex:1;padding:12px;font-size:16px;font-weight:600;border:none;border-radius:8px;cursor:pointer}
#pinError{color:#ff3b30;font-size:14px;margin-top:10px;display:none}
#userBadge{font-size:14px;color:#ccc;padding:4px 10px;cursor:pointer;white-space:nowrap}
#invPage{flex:1;overflow-y:auto;padding:12px 16px 8px}
#invPage h2{font-size:18px;margin-bottom:12px}
.invp-item{display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid #333}
.invp-item:last-child{border-bottom:none}
.invp-name{flex:1;font-size:15px;font-weight:500}
.invp-qty{font-size:15px;color:#34c759;font-weight:600;min-width:24px;text-align:center}
.invp-btn{padding:8px 16px;font-size:16px;font-weight:600;border:none;border-radius:8px;cursor:pointer;touch-action:manipulation;min-width:48px}
.invp-add{background:#34c759;color:#fff}
.invp-take{background:#ff9500;color:#fff}
#lgPage{flex:1;overflow-y:auto;padding:12px 16px 8px}
#lgPage h2{font-size:18px;margin-bottom:12px}
.lg-entry{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #333;font-size:13px}
.lg-entry:last-child{border-bottom:none}
.lg-name{flex:1}
.lg-action{font-weight:600;padding:2px 8px;border-radius:4px;font-size:12px}
.lg-add{background:#34c75933;color:#34c759}
.lg-take{background:#ff3b3033;color:#ff3b30}
.lg-admin{background:#007aff33;color:#007aff}
.lg-time{color:#666;font-size:12px;white-space:nowrap}
#settingsPage,#usersPage{flex:1;overflow-y:auto;padding:12px 16px 60px;position:relative;z-index:10}
#settingsPage h2,#usersPage h2{font-size:18px;margin-bottom:12px}
.set-row{padding:12px 0;border-bottom:1px solid #333}
.set-row:last-child{border-bottom:none}
.set-label{display:block;font-size:13px;color:#888;margin-bottom:4px}
.set-val{display:block}
.set-val input,.set-val select{width:100%;padding:10px 12px;font-size:15px;border:1px solid #555;border-radius:6px;background:#222;color:#fff;touch-action:manipulation;pointer-events:auto}
.set-val input[type="checkbox"]{width:auto;padding:0;margin:0}
.usr-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #333}
.usr-row:last-child{border-bottom:none}
.usr-name{flex:1;font-size:15px}
.usr-del{padding:6px 12px;font-size:13px;border:none;border-radius:6px;background:#ff3b30;color:#fff;cursor:pointer}
#btnSaveSettings{padding:10px 20px;border:none;border-radius:8px;background:#34c759;color:#fff;font-size:15px;cursor:pointer;margin-top:12px;touch-action:manipulation}
</style>
</head>
<body>

<div id="navBar">
  <button id="menuBtn">&#9776;</button>
  <img src="/favicon-32x32.png" alt="" style="width:24px;height:24px;margin-right:8px;border-radius:4px">
  <span id="pageTitle"><?= $page === 'inventory' ? 'Inventory' : ($page === 'ledger' ? 'Ledger' : 'FridgeStare') ?></span>
  <span style="display:flex;align-items:center;margin-left:auto"><span id="userBadge"></span><span id="logoutIcon" style="cursor:pointer;padding:4px 6px 4px 0;color:#ccc;display:none">
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
      <polyline points="16 17 21 12 16 7"/>
      <line x1="21" y1="12" x2="9" y2="12"/>
    </svg>
  </span></span>
</div>

<div id="sideMenu">
  <div style="display:flex;align-items:center;gap:10px;padding:12px 20px 10px;border-bottom:1px solid #333;margin-bottom:4px">
    <img src="/favicon-32x32.png" alt="" style="width:28px;height:28px;border-radius:4px">
    <span style="font-size:18px;font-weight:600;color:#fff">FridgeStare</span>
  </div>
  <a href="/" class="<?= $page === 'scan' ? 'active' : '' ?>">Scanner</a>
  <a href="/inventory" class="<?= $page === 'inventory' ? 'active' : '' ?>">Inventory</a>
  <a href="/ledger" class="<?= $page === 'ledger' ? 'active' : '' ?>">Ledger</a>
  <a href="/settings" class="<?= $page === 'settings' ? 'active' : '' ?>">Settings</a>
  <a href="/users" class="<?= $page === 'users' ? 'active' : '' ?>">Users</a>
  <a href="#" id="menuSwitchUser" style="border-top:1px solid #333;margin-top:8px">&#x21C4; Switch User</a>
</div>

<div id="overlay"></div>
<div id="banner"></div>
<div id="flash"></div>
<div id="errorOverlay"><button id="errorClose">&times;</button><span id="errorMsg"></span></div>
<div id="pinOverlay" style="display:none"><div id="pinBox">
  <h2>Who's this?</h2>
  <p>Enter your PIN</p>
  <input type="tel" id="pinInput" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="off">
  <?php if (!empty($cfg['turnstile_site_key'])): ?><div id="turnstileWidget" class="cf-turnstile" data-sitekey="<?= $cfg['turnstile_site_key'] ?>" data-callback="onTurnstileSuccess" style="margin:12px 0"></div><?php endif; ?>
  <div class="pinBtns"><button id="pinSubmit" style="background:#007aff;color:#fff">Log On</button></div>
  <div id="pinError">PIN not recognized</div>
</div></div>

<?php if ($page === 'inventory'): ?>

<div id="invPage">
  <h2>Inventory</h2>
  <div id="invpList"></div>
</div>

<?php elseif ($page === 'ledger'): ?>

<div id="lgPage">
  <h2>Ledger</h2>
  <div id="lgList"></div>
</div>

<?php elseif ($page === 'settings'): ?>

<div id="settingsPage">
  <h2>Settings</h2>
  <div id="settingsForm">
    <div class="set-row">
      <span class="set-label">Timezone</span>
      <span class="set-val"><select id="cfg_timezone"><?php
$zones = DateTimeZone::listIdentifiers();
$currentTz = $cfg['timezone'] ?? 'UTC';
foreach ($zones as $zone) {
    $sel = ($zone === $currentTz) ? ' selected' : '';
    echo "<option value=\"$zone\"$sel>$zone</option>";
}
?></select></span>
    </div>
    <div class="set-row">
      <span class="set-label">Session Timeout (days)</span>
      <span class="set-val"><select id="cfg_session_timeout_days"><?php
$timeouts = [7,14,30,60,90,365];
$curSess = (int)($cfg['session_timeout_days'] ?? 30);
foreach ($timeouts as $v) {
    $sel = ($v === $curSess) ? ' selected' : '';
    $label = $v === 365 ? '1 year' : "$v days";
    echo "<option value=\"$v\"$sel>$label</option>";
}
?></select></span>
    </div>
    <div class="set-row">
      <span class="set-label">PIN Max Attempts</span>
      <span class="set-val"><select id="cfg_pin_max_attempts"><?php
$attempts = [1,2,3,5,10];
$curAtt = (int)($cfg['pin_max_attempts'] ?? 3);
foreach ($attempts as $v) {
    $sel = ($v === $curAtt) ? ' selected' : '';
    echo "<option value=\"$v\"$sel>$v</option>";
}
?></select></span>
    </div>
    <div class="set-row">
      <span class="set-label">PIN Lockout (hours)</span>
      <span class="set-val"><select id="cfg_pin_lockout_hours"><?php
$lockouts = [1,2,4,8,12,24];
$curLock = (int)($cfg['pin_lockout_hours'] ?? 1);
foreach ($lockouts as $v) {
    $sel = ($v === $curLock) ? ' selected' : '';
    $label = $v === 24 ? '1 day' : ($v === 1 ? '1 hour' : "$v hours");
    echo "<option value=\"$v\"$sel>$label</option>";
}
?></select></span>
    </div>
    <div class="set-row">
      <span class="set-label">Default Quantity</span>
      <span class="set-val"><select id="cfg_default_qty"><?php
$qtys = [1,2,3,5,10];
$curQty = (int)($cfg['default_qty'] ?? 1);
foreach ($qtys as $v) {
    $sel = ($v === $curQty) ? ' selected' : '';
    echo "<option value=\"$v\"$sel>$v</option>";
}
?></select></span>
    </div>
    <div class="set-row">
      <span class="set-label">Debug Mode</span>
      <span class="set-val"><input type="checkbox" id="cfg_debug"></span>
    </div>

    <h3 style="color:#ff3b30;font-size:16px;margin:20px 0 8px;border-top:1px solid #333;padding-top:12px">Danger Zone</h3>
    <p style="color:#888;font-size:13px;margin-bottom:12px">These settings affect external services and security.</p>

    <div class="set-row">
      <span class="set-label">Turnstile Site Key</span>
      <span class="set-val"><input type="text" id="cfg_turnstile_site_key" placeholder="Cloudflare Turnstile Site Key"></span>
    </div>
    <div class="set-row">
      <span class="set-label">Turnstile Secret Key</span>
      <span class="set-val"><input type="text" id="cfg_turnstile_secret_key" placeholder="Cloudflare Turnstile Secret Key"></span>
    </div>
    <div class="set-row">
      <span class="set-label">UPCItemDB Key</span>
      <span class="set-val"><input type="text" id="cfg_upcitemdb_key" placeholder="UPCItemDB API Key (optional)"></span>
    </div>

    <button id="btnSaveSettings">Save Settings</button>
  </div>
</div>

<?php elseif ($page === 'users'): ?>

<div id="usersPage">
  <h2>Users</h2>
  <div id="usersList"></div>
  <div id="usersForm" style="margin-top:20px;padding-top:16px;border-top:1px solid #333">
    <h3 style="font-size:16px;margin-bottom:12px">Add User</h3>
    <input type="text" id="newUserName" class="edit-field" placeholder="Name" style="margin-bottom:8px">
    <input type="tel" id="newUserPin" class="edit-field" placeholder="PIN (4-8 digits)" inputmode="numeric" pattern="[0-9]*" maxlength="8" style="margin-bottom:12px">
    <button id="btnAddUser" style="padding:10px 20px;border:none;border-radius:8px;background:#34c759;color:#fff;font-size:15px;cursor:pointer">Add User</button>
  </div>
  <div id="changePinForm" style="margin-top:20px;padding-top:16px;border-top:1px solid #333">
    <h3 style="font-size:16px;margin-bottom:12px">Change My PIN</h3>
    <input type="tel" id="selfNewPin" class="edit-field" placeholder="New PIN (4-8 digits)" inputmode="numeric" pattern="[0-9]*" maxlength="8" style="margin-bottom:8px">
    <input type="tel" id="selfConfirmPin" class="edit-field" placeholder="Confirm new PIN" inputmode="numeric" pattern="[0-9]*" maxlength="8" style="margin-bottom:12px">
    <button id="btnChangeMyPin" style="padding:10px 20px;border:none;border-radius:8px;background:#007aff;color:#fff;font-size:15px;cursor:pointer">Change PIN</button>
  </div>
</div>

<?php else: ?>

<div id="scanner">
  <div id="scanPrompt">
    <button id="btnSnap" title="Snap barcode photo">&#128247;</button>
    <p>Click to scan a UPC code</p>
    <span class="hint">Opens your camera app</span>
    <button id="btnNoUpc" style="margin-top:20px;padding:12px 24px;border:2px solid #666;border-radius:10px;background:#222;color:#fff;font-size:15px;cursor:pointer;touch-action:manipulation">No barcode? Add manually</button>
  </div>
  <div id="result">
    <div style="position:relative">
      <input type="text" id="editName" class="edit-field" placeholder="Product name (required)" autocomplete="off">
      <div id="suggestions"></div>
    </div>
    <input type="text" id="editBrand" class="edit-field" placeholder="Brand (optional)" autocomplete="off">
    <div id="resultTags" class="result-tags" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px"></div>
    <div id="prodQty" style="font-size:14px;color:#34c759;margin-bottom:8px"></div>
    <div class="actions">
      <button id="btnAdd">ADD</button>
      <button id="btnTake">TAKE</button>
      <button id="btnCancel" style="background:#555;color:#fff;flex:0.5">Cancel</button>
    </div>
  </div>
</div>

<?php endif; ?>
<?php if ($page === 'scan'): ?>
<div id="manual">
  <div id="manualInputWrap">
    <input type="text" id="manualUpc" inputmode="numeric" pattern="[0-9]*" placeholder="Enter UPC..." maxlength="14">
    <button id="clearUpc">&times;</button>
  </div>
  <button id="btnManual">Lookup</button>
</div>
<input type="file" id="photoInput" accept="image/*" capture="environment" style="display:none">
<div id="photoOverlay">
  <img id="photoThumb" src="" alt="">
  <div id="photoStatus">Reading barcode...</div>
  <div>
    <button id="photoRetry">&#128247; Snap Again</button>
    <button id="photoCancel">Cancel</button>
  </div>
</div>
<div id="tagOverlay">
  <h3>Add New Item</h3>
  <p class="tag-sub">Enter details and pick categories</p>
  <div style="position:relative;width:100%;max-width:400px">
    <input type="text" id="manualName" class="edit-field" placeholder="Name (required)" autocomplete="off" style="margin-bottom:6px">
    <div id="manualSuggestions" style="position:absolute;left:0;right:0;top:100%;background:#222;border:1px solid #555;border-top:none;border-radius:0 0 8px 8px;max-height:180px;overflow-y:auto;display:none;z-index:10"></div>
  </div>
  <input type="text" id="manualBrand" class="edit-field" placeholder="Brand (optional)" autocomplete="off" style="margin-bottom:6px;max-width:400px">
  <div class="qty-row" style="display:flex;align-items:center;gap:12px;margin-bottom:16px;max-width:400px;width:100%">
    <span style="color:#888;font-size:15px">Quantity:</span>
    <button id="manualQtyMinus" style="width:40px;height:40px;border:none;border-radius:8px;background:#555;color:#fff;font-size:20px;cursor:pointer">-</button>
    <span id="manualQty" style="font-size:18px;font-weight:600;color:#fff;min-width:24px;text-align:center">1</span>
    <button id="manualQtyPlus" style="width:40px;height:40px;border:none;border-radius:8px;background:#34c759;color:#fff;font-size:20px;cursor:pointer">+</button>
  </div>
  <div class="tag-list">
    <button class="tag-btn" data-tag="Protein">Protein</button>
    <button class="tag-btn" data-tag="Main">Main</button>
    <button class="tag-btn" data-tag="Sauce">Sauce</button>
    <button class="tag-btn" data-tag="Side">Side</button>
    <button class="tag-btn" data-tag="Snack">Snack</button>
    <button class="tag-btn" data-tag="Dessert">Dessert</button>
    <button class="tag-btn" data-tag="Use Soon">Use Soon</button>
    <button class="tag-btn" data-tag="Staple">Staple</button>
  </div>
  <div id="tagActions">
    <button id="btnTagSave">Save to Inventory</button>
    <button id="btnTagSkip">Cancel</button>
  </div>
</div>
<div id="scannerLog" style="position:fixed;top:48px;left:0;right:0;background:rgba(0,0,0,.85);color:#0f0;font:12px monospace;padding:6px 10px;max-height:80px;overflow-y:auto;z-index:105;display:none"></div>
<?php endif; ?>

<script id="groscan-config" type="application/json">{"page":"<?= $page ?>","turnstileKey":"<?= !empty($cfg['turnstile_site_key']) ? $cfg['turnstile_site_key'] : '' ?>","debug":<?= !empty($cfg['debug']) && $cfg['debug'] ? 'true' : 'false' ?>,"sessionDays":<?= (int)($cfg['session_timeout_days'] ?? 30) ?>}</script>
<?php if ($page === 'scan'): ?><script src="zbar-wasm.js"></script><?php endif; ?>
<script src="groscan.js"></script>
</body>
</html>
