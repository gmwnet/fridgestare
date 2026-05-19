<?php
// GroScan — Grocery UPC Scanner
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
                CURLOPT_USERAGENT => 'GroScan/1.0',
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $response = @curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'GroScan/1.0']]);
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
                CURLOPT_USERAGENT => 'GroScan/1.0',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $response = @curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'GroScan/1.0', 'header' => implode("\r\n", $headers)]]);
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
            "INSERT OR REPLACE INTO products (upc, name, brand, category, quantity, image_url)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $upc, $productData['name'], $productData['brand'],
            $productData['category'], $productData['quantity'], $productData['image_url']
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
             VALUES (?, 1, datetime('now'))
             ON CONFLICT(upc) DO UPDATE SET quantity = quantity + 1, updated_at = datetime('now')",
            [$upc]
        );
    } else {
        dbExecWithRetry($db,
            "INSERT INTO inventory (upc, quantity, updated_at)
             VALUES (?, 0, datetime('now'))
             ON CONFLICT(upc) DO UPDATE SET
               quantity = CASE WHEN quantity > 0 THEN quantity - 1 ELSE 0 END,
               updated_at = datetime('now')",
            [$upc]
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
        "SELECT l.id, l.upc, COALESCE(p.name, 'Unknown') AS name, l.action, l.created_at, u.name AS user
         FROM ledger l
         LEFT JOIN products p ON l.upc = p.upc
         LEFT JOIN users u ON l.user_id = u.id
         ORDER BY l.id DESC
         LIMIT 200"
    );
    jsonResponse(['entries' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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
    $stmt = $db->prepare("SELECT id, name FROM users");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $match = null;
    foreach ($users as $u) {
        if (password_verify($pin, $u['pin_hash'])) { $match = $u; break; }
    }
    if (!$match) {
        dbExecWithRetry($db,
            "INSERT INTO rate_limits (ip, attempts, locked_until) VALUES (?, 1, NULL)
             ON CONFLICT(ip) DO UPDATE SET attempts = CASE WHEN locked_until IS NULL OR locked_until < datetime('now') THEN 1 ELSE attempts + 1 END",
            [$ip]
        );
        $stmt = $db->prepare("SELECT attempts FROM rate_limits WHERE ip = ?");
        $stmt->execute([$ip]);
        $attempts = (int)$stmt->fetchColumn();
        if ($attempts >= 3) {
            dbExecWithRetry($db, "UPDATE rate_limits SET locked_until = datetime('now', '+1 hour') WHERE ip = ?", [$ip]);
            jsonResponse(['error' => 'Too many attempts. Locked out for 1 hour.'], 429);
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
$navItems = [
    '/'         => ['label' => 'Scanner', 'icon' => '📷'],
    '/inventory' => ['label' => 'Inventory', 'icon' => '📋'],
    '/ledger'    => ['label' => 'Ledger', 'icon' => '📜'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<title>GroScan</title>
<script src="html5-qrcode.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#000;color:#fff;height:100dvh;display:flex;flex-direction:column;padding:48px 0 56px;overflow:hidden}
#navBar{position:fixed;top:0;left:0;right:0;height:48px;display:flex;align-items:center;padding:0 12px;background:#1a1a1a;border-bottom:1px solid #333;z-index:110}
#menuBtn{background:none;border:none;color:#fff;font-size:26px;cursor:pointer;padding:4px 8px;margin-right:10px;line-height:1}
#pageTitle{font-size:17px;font-weight:600}
#sideMenu{position:fixed;top:0;left:0;width:260px;height:100dvh;background:#1a1a1a;z-index:200;transform:translateX(-100%);transition:transform .25s;padding:56px 0 0 0}
#sideMenu.open{transform:translateX(0)}
#sideMenu a{display:flex;align-items:center;gap:12px;padding:16px 20px;color:#ccc;text-decoration:none;font-size:16px;border-bottom:1px solid #222}
#sideMenu a.active{color:#fff;background:#333;border-left:3px solid #007aff}
#overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:150;display:none}
#overlay.show{display:block}
#flash{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);padding:24px 48px;border-radius:16px;font-size:24px;font-weight:700;z-index:300;display:none;pointer-events:none}
#flash.show{display:block}
#flash.add{background:#34c759;color:#fff}
#flash.take{background:#ff9500;color:#fff}
#scanner{flex:1;position:relative;background:#111;overflow:hidden}
#reader video{width:100%;height:100%;object-fit:cover}
#result{position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.85);padding:12px 16px;transform:translateY(100%);transition:transform .3s}
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
#manual{position:fixed;bottom:0;left:0;right:0;display:flex;gap:8px;padding:8px 12px;background:#1a1a1a;z-index:60}
#manual input{flex:1;padding:14px 44px 14px 16px;font-size:20px;border:1px solid #555;border-radius:8px;background:#222;color:#fff}
#manual button{padding:14px 24px;font-size:20px;border:none;border-radius:8px;background:#007aff;color:#fff;cursor:pointer}
#clearUpc{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:#666;font-size:24px;cursor:pointer;line-height:1;padding:4px 8px;display:none;z-index:5}
#manualInputWrap{position:relative;flex:1;display:flex}
#banner{position:fixed;top:48px;left:0;right:0;background:#ff9500;color:#000;padding:8px 16px;font-size:13px;text-align:center;z-index:100;display:none}
#errorOverlay{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);padding:32px 48px;border-radius:16px;font-size:20px;font-weight:600;z-index:300;display:none;text-align:center;background:#ff3b30;color:#fff;min-width:200px;max-width:80vw;line-height:1.4}
#errorOverlay.show{display:block}
#errorClose{position:absolute;top:2px;right:10px;background:none;border:none;color:#fff;font-size:36px;cursor:pointer;font-weight:700;line-height:1;padding:4px 8px}
#pinOverlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.85);z-index:500;display:flex;align-items:center;justify-content:center}
#pinBox{background:#1a1a1a;padding:32px;border-radius:16px;text-align:center;min-width:300px}
#pinBox h2{font-size:22px;margin-bottom:4px}
#pinBox p{color:#888;font-size:14px;margin-bottom:20px}
#pinBox input{width:100%;padding:14px 16px;font-size:24px;text-align:center;border:1px solid #555;border-radius:8px;background:#222;color:#fff;outline:none;letter-spacing:8px}
#pinBox input:focus{border-color:#007aff}
#pinBox .pinBtns{display:flex;gap:10px;margin-top:16px}
#pinBox .pinBtns button{flex:1;padding:12px;font-size:16px;font-weight:600;border:none;border-radius:8px;cursor:pointer}
#pinError{color:#ff3b30;font-size:14px;margin-top:10px;display:none}
#userBadge{font-size:14px;color:#007aff;margin-left:auto;padding:4px 10px;cursor:pointer;white-space:nowrap}
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
.lg-time{color:#666;font-size:12px;white-space:nowrap}
</style>
</head>
<body>

<div id="navBar">
  <button id="menuBtn">&#9776;</button>
  <span id="pageTitle"><?= $page === 'inventory' ? 'Inventory' : ($page === 'ledger' ? 'Ledger' : 'GroScan') ?></span>
  <span id="userBadge"></span>
</div>

<div id="sideMenu">
  <a href="/" class="<?= $page === 'scan' ? 'active' : '' ?>">Scanner</a>
  <a href="/inventory" class="<?= $page === 'inventory' ? 'active' : '' ?>">Inventory</a>
  <a href="/ledger" class="<?= $page === 'ledger' ? 'active' : '' ?>">Ledger</a>
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
  <div class="pinBtns"><button id="pinSubmit" style="background:#007aff;color:#fff">Enter</button></div>
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

<?php else: ?>

<div id="scanner">
  <div id="reader"></div>
  <div id="result">
    <div style="position:relative">
      <input type="text" id="editName" class="edit-field" placeholder="Product name (required)" autocomplete="off">
      <div id="suggestions"></div>
    </div>
    <input type="text" id="editBrand" class="edit-field" placeholder="Brand (optional)" autocomplete="off">
    <div id="prodQty" style="font-size:14px;color:#34c759;margin-bottom:8px"></div>
    <div class="actions">
      <button id="btnAdd">ADD</button>
      <button id="btnTake">TAKE</button>
      <button id="btnCancel" style="background:#555;color:#fff;flex:0.5">Cancel</button>
    </div>
  </div>
</div>

<?php endif; ?>

<div id="manual">
  <div id="manualInputWrap">
    <input type="text" id="manualUpc" inputmode="numeric" pattern="[0-9]*" placeholder="Enter UPC..." maxlength="14">
    <button id="clearUpc">&times;</button>
  </div>
  <button id="btnManual">Lookup</button>
</div>

<script>
const $ = id => document.getElementById(id);
const page = '<?= $page ?>';

// --- Menu ---
function toggleMenu(open) {
  if (open) {
    $('errorOverlay').classList.remove('show');
    $('result').classList.remove('show');
    $('suggestions').classList.remove('show');
    lastUpc = null;
  }
  $('sideMenu').classList.toggle('open', open);
  $('overlay').classList.toggle('show', open);
}
$('menuBtn').addEventListener('click', () => toggleMenu(true));
$('overlay').addEventListener('click', () => toggleMenu(false));
document.querySelectorAll('#sideMenu a').forEach(a => a.addEventListener('click', () => toggleMenu(false)));

loadUser();

// --- Error overlay ---
function showError(msg) {
  $('errorMsg').textContent = msg;
  $('errorOverlay').classList.add('show');
}
$('errorClose').addEventListener('click', () => { $('errorOverlay').classList.remove('show'); if (page === 'scan' && scanning) setTimeout(() => startScanner(), 300); });
$('errorOverlay').addEventListener('click', (e) => { if (e.target === e.currentTarget) { $('errorOverlay').classList.remove('show'); if (page === 'scan' && scanning) setTimeout(() => startScanner(), 300); } });

// --- Auth ---
let currentUser = null;
function loadUser() {
  try { var u = JSON.parse(localStorage.getItem('groscan_user')); if (u && u.id && u.name && u.expires_at && Date.now() < u.expires_at) currentUser = u; else { localStorage.removeItem('groscan_user'); } } catch (e) {}
  if (currentUser) {
    $('userBadge').textContent = currentUser.name;
    $('pinOverlay').style.display = 'none';
  } else {
    $('userBadge').textContent = '';
    $('pinOverlay').style.display = 'flex';
    $('pinInput').focus();
  }
}
async function doAuth(pin) {
  try {
    const res = await fetch('/api/auth', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pin }) });
    const data = await res.json();
    if (!res.ok) { $('pinError').textContent = data.error || 'Not recognized'; $('pinError').style.display = 'block'; return; }
    currentUser = data.user;
    currentUser.expires_at = Date.now() + 30 * 24 * 60 * 60 * 1000;
    localStorage.setItem('groscan_user', JSON.stringify(currentUser));
    $('userBadge').textContent = currentUser.name;
    $('pinOverlay').style.display = 'none';
  } catch (e) { $('pinError').textContent = 'Network error'; $('pinError').style.display = 'block'; }
}
$('pinSubmit').addEventListener('click', () => { doAuth($('pinInput').value.trim()); });
$('pinInput').addEventListener('keydown', e => { if (e.key === 'Enter') $('pinSubmit').click(); });
$('userBadge').addEventListener('click', () => {
  localStorage.removeItem('groscan_user');
  currentUser = null;
  loadUser();
});
$('menuSwitchUser').addEventListener('click', (e) => {
  e.preventDefault(); toggleMenu(false);
  localStorage.removeItem('groscan_user');
  currentUser = null;
  loadUser();
});

// --- API helpers ---
async function apiAction(upc, action, name, brand) {
  const res = await fetch('/api/action', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ upc, action, name: name || null, brand: brand || null, user_id: currentUser ? currentUser.id : null })
  });
  return res.json();
}

function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

// --- Manual entry ---
$('btnManual').addEventListener('click', () => {
  const upc = $('manualUpc').value.trim();
  if (upc.length < 8) { showError('UPC must be 8\u201314 digits.'); return; }
  $('manualUpc').value = '';
  if (page !== 'scan') {
    window.location.href = '/?upc=' + encodeURIComponent(upc);
  } else {
    lookupUpc(upc);
  }
});
$('manualUpc').addEventListener('keydown', e => {
  if (e.key === 'Enter') $('btnManual').click();
});
$('manualUpc').addEventListener('input', () => {
  $('clearUpc').style.display = $('manualUpc').value ? 'block' : 'none';
});
$('clearUpc').addEventListener('click', () => {
  $('manualUpc').value = '';
  $('manualUpc').focus();
  $('clearUpc').style.display = 'none';
});

<?php if ($page === 'inventory'): ?>

// --- Inventory page ---
async function loadInvPage() {
  try {
    const res = await fetch('/api/inventory');
    const data = await res.json();
    $('invpList').innerHTML = data.items.map(item =>
      '<div class="invp-item">' +
        '<span class="invp-name">' + esc(item.name) + '</span>' +
        '<span class="invp-qty">' + item.qty + '</span>' +
        '<button class="invp-btn invp-take" data-upc="' + item.upc + '" data-action="take">\u2212</button>' +
        '<button class="invp-btn invp-add" data-upc="' + item.upc + '" data-action="add">+</button>' +
      '</div>'
    ).join('');
    document.querySelectorAll('#invpList .invp-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        $('manualUpc').value = '';
        const upc = btn.dataset.upc;
        const action = btn.dataset.action;
        const data = await apiAction(upc, action);
        if (data.success) loadInvPage();
      });
    });
  } catch (e) {}
}

loadInvPage();

<?php elseif ($page === 'ledger'): ?>

// --- Ledger page ---
async function loadLedger() {
  try {
    const res = await fetch('/api/ledger');
    const data = await res.json();
    $('lgList').innerHTML = data.entries.map(e =>
      '<div class="lg-entry">' +
        '<span class="lg-name">' + esc(e.name || 'Unknown') + (e.user ? ' <span style="color:#007aff;font-size:12px">(' + esc(e.user) + ')</span>' : '') + '</span>' +
        '<span class="lg-action lg-' + e.action + '">' + e.action.toUpperCase() + '</span>' +
        '<span class="lg-time">' + e.created_at + '</span>' +
      '</div>'
    ).join('');
  } catch (e) {}
}

loadLedger();

<?php else: ?>

// --- Scanner ---
let lastUpc = null;
let lastProduct = null;
let scanning = true;
let html5QrCode = null;

function startScanner() {
  if (html5QrCode) { html5QrCode.stop().catch(() => {}); }
  html5QrCode = new Html5Qrcode('reader');
  html5QrCode.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 250, height: 150 } },
    onScanSuccess,
    () => {}
  ).catch(() => {
    showError('Camera unavailable. Use manual entry below.');
  });
}

async function onScanSuccess(decodedText) {
  const upc = decodedText.replace(/[^0-9]/g, '');
  if (upc.length < 8 || upc === lastUpc) return;
  lastUpc = upc;
  await lookupUpc(upc);
}

async function lookupUpc(upc) {
  if (html5QrCode) { try { html5QrCode.pause(); } catch (e) {} }
  $('result').classList.remove('show');
  $('errorOverlay').classList.remove('show');
  $('btnAdd').disabled = true;
  try {
    const res = await fetch('/api/lookup?upc=' + encodeURIComponent(upc));
    const data = await res.json();
    if (!res.ok) { showError(data.error); return; }
    lastProduct = data;
    const p = data.product;
    $('editName').value = p && p.name ? p.name : '';
    $('editBrand').value = p && p.brand ? p.brand : '';
    $('editName').placeholder = p ? 'Product name' : 'Product name (required)';
    $('prodQty').textContent = data.inventory_qty > 0 ? 'In stock: ' + data.inventory_qty : '';
    $('btnAdd').disabled = !$('editName').value.trim();
    if (data.warning) showError(data.warning);
    $('result').classList.add('show');
    if (!p || !p.name) $('editName').focus();
  } catch (e) {
    showError('Network error. Check connection.');
  }
}

async function doAction(action) {
  if (!lastProduct) return;
  const name = $('editName').value.trim();
  if (action === 'add' && !name) { showError('Enter a product name first.'); return; }
  const brand = $('editBrand').value.trim();
  try {
    const data = await apiAction(lastProduct.upc, action, name, brand);
    if (data.success) {
      lastProduct.inventory_qty = data.new_qty;
      $('prodQty').textContent = data.new_qty > 0 ? 'In stock: ' + data.new_qty : '';
      const f = $('flash');
      f.textContent = action === 'add' ? 'Added!' : 'Taken!';
      f.className = 'show ' + action;
      setTimeout(() => { f.className = ''; }, 1000);
    }
  } catch (e) {}
  $('manualUpc').value = '';
  $('result').classList.remove('show');
  lastUpc = null;
  if (scanning) setTimeout(() => startScanner(), 300);
}

$('btnAdd').addEventListener('click', () => doAction('add'));
$('btnTake').addEventListener('click', () => doAction('take'));

$('btnCancel').addEventListener('click', () => {
  $('result').classList.remove('show');
  lastUpc = null;
  if (scanning) setTimeout(() => startScanner(), 300);
});

let suggestTimer = null;
$('editName').addEventListener('input', () => {
  $('btnAdd').disabled = !$('editName').value.trim();
  clearTimeout(suggestTimer);
  const val = $('editName').value.trim();
  if (val.length < 2) { $('suggestions').classList.remove('show'); return; }
  suggestTimer = setTimeout(async () => {
    try {
      const res = await fetch('/api/search?q=' + encodeURIComponent(val));
      const data = await res.json();
      const list = $('suggestions');
      list.innerHTML = data.results.map(r =>
        '<div data-upc="' + r.upc + '" data-name="' + esc(r.name) + '" data-brand="' + esc(r.brand || '') + '">' +
          esc(r.name) + (r.brand ? ' <span class="sug-brand">' + esc(r.brand) + '</span>' : '') +
        '</div>'
      ).join('');
      if (data.results.length) { list.classList.add('show'); } else { list.classList.remove('show'); }
      list.querySelectorAll('div').forEach(el => {
        el.addEventListener('click', () => {
          $('editName').value = el.dataset.name;
          $('editBrand').value = el.dataset.brand;
          $('suggestions').classList.remove('show');
          $('btnAdd').disabled = false;
          $('editName').focus();
        });
      });
    } catch (e) {}
  }, 200);
});
document.addEventListener('click', (e) => { if (!e.target.closest('#result > div')) $('suggestions').classList.remove('show'); });



const urlUpc = new URLSearchParams(window.location.search).get('upc');
if (urlUpc && urlUpc.length >= 8) {
  $('manualUpc').value = urlUpc;
  lookupUpc(urlUpc);
} else {
  $('manualUpc').value = '';
}

document.addEventListener('visibilitychange', () => {
  if (document.hidden) { scanning = false; } else { scanning = true; startScanner(); }
});

startScanner();

<?php endif; ?>
</script>
</body>
</html>
