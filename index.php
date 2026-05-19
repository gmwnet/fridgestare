<?php
// GroScan — Grocery UPC Scanner
// Single-file PHP app: scan barcodes, look up products, manage inventory

$dbPath = __DIR__ . '/groscan.db';

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
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

// --- Helpers ---
function normalizeUpc($upc) {
    return str_pad(preg_replace('/[^0-9]/', '', $upc), 13, '0', STR_PAD_LEFT);
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

// --- Router ---
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$uri = rtrim($uri, '/') ?: '/';

// --- API: Lookup ---
if ($uri === '/api/lookup' && $method === 'GET') {
    $rawUpc = $_GET['upc'] ?? '';
    if (!preg_match('/^\d{12,13}$/', $rawUpc)) {
        jsonResponse(['error' => 'Invalid UPC — must be 12 or 13 digits'], 400);
    }
    $upc = normalizeUpc($rawUpc);

    // Check local cache
    $stmt = $db->prepare("SELECT * FROM products WHERE upc = ?");
    $stmt->execute([$upc]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $warning = null;
    $provider = new OpenFoodFactsProvider();
    $productData = $provider->lookup($upc);

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
    } elseif ($row) {
        // API failed, serve cached
        $product = $row;
        $warning = 'Using cached data \u2014 network unavailable.';
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
    if (!preg_match('/^\d{12,13}$/', $rawUpc) || !in_array($action, ['add', 'take'])) {
        jsonResponse(['error' => 'Invalid request'], 400);
    }
    $upc = normalizeUpc($rawUpc);
    $name = $input['name'] ?? null;
    $brand = $input['brand'] ?? null;
    if ($name !== null || $brand !== null) {
        $existing = $db->prepare("SELECT COUNT(*) FROM products WHERE upc = ?");
        $existing->execute([$upc]);
        if ($existing->fetchColumn() > 0) {
            dbExecWithRetry($db, "UPDATE products SET name = COALESCE(NULLIF(?, ''), name), brand = COALESCE(NULLIF(?, ''), brand) WHERE upc = ?", [$name, $brand, $upc]);
        } else {
            dbExecWithRetry($db, "INSERT INTO products (upc, name, brand) VALUES (?, ?, ?)", [$upc, $name, $brand]);
        }
    }
    dbExecWithRetry($db, "INSERT INTO ledger (upc, action) VALUES (?, ?)", [$upc, $action]);
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
         ORDER BY i.updated_at DESC"
    );
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['items' => array_map(function ($r) {
        return ['upc' => $r['upc'], 'name' => $r['name'], 'qty' => (int)$r['quantity']];
    }, $items)]);
}

// --- API: Ledger ---
if ($uri === '/api/ledger' && $method === 'GET') {
    $stmt = $db->query(
        "SELECT l.id, l.upc, COALESCE(p.name, 'Unknown') AS name, l.action, l.created_at
         FROM ledger l
         LEFT JOIN products p ON l.upc = p.upc
         ORDER BY l.id DESC
         LIMIT 200"
    );
    jsonResponse(['entries' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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
<script src="https://unpkg.com/html5-qrcode"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#000;color:#fff;height:100dvh;display:flex;flex-direction:column;padding:48px 0 56px;overflow:hidden}
#navBar{position:fixed;top:0;left:0;right:0;height:48px;display:flex;align-items:center;padding:0 12px;background:#1a1a1a;border-bottom:1px solid #333;z-index:110}
#menuBtn{background:none;border:none;color:#fff;font-size:22px;cursor:pointer;padding:4px 8px;margin-right:10px;line-height:1}
#pageTitle{font-size:17px;font-weight:600}
#sideMenu{position:fixed;top:0;left:0;width:260px;height:100dvh;background:#1a1a1a;z-index:200;transform:translateX(-100%);transition:transform .25s;padding:56px 0 0 0}
#sideMenu.open{transform:translateX(0)}
#sideMenu a{display:flex;align-items:center;gap:12px;padding:16px 20px;color:#ccc;text-decoration:none;font-size:16px;border-bottom:1px solid #222}
#sideMenu a.active{color:#fff;background:#333;border-left:3px solid #007aff}
#overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:150;display:none}
#overlay.show{display:block}
#scanner{flex:1;position:relative;background:#111;overflow:hidden}
#reader video{width:100%;height:100%;object-fit:cover}
#result{position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.85);padding:12px 16px;transform:translateY(100%);transition:transform .3s}
#result.show{transform:translateY(0)}
.edit-field{width:100%;padding:6px 0;font-size:15px;background:transparent;border:none;border-bottom:1px solid #555;color:#fff;margin-bottom:2px;outline:none}
.edit-field:focus{border-bottom-color:#007aff}
.edit-field::placeholder{color:#666}
.actions{display:flex;gap:12px}
.actions button{flex:1;padding:16px;font-size:18px;font-weight:600;border:none;border-radius:12px;cursor:pointer;touch-action:manipulation}
#btnAdd{background:#34c759;color:#fff}
#btnTake{background:#ff3b30;color:#fff}
#manual{position:fixed;bottom:0;left:0;right:0;display:flex;gap:8px;padding:8px 12px;background:#1a1a1a;z-index:60}
#manual input{flex:1;padding:10px 12px;font-size:16px;border:1px solid #555;border-radius:8px;background:#222;color:#fff}
#manual button{padding:10px 20px;font-size:16px;border:none;border-radius:8px;background:#007aff;color:#fff;cursor:pointer}
#banner{position:fixed;top:48px;left:0;right:0;background:#ff9500;color:#000;padding:8px 16px;font-size:13px;text-align:center;z-index:100;display:none}
#invPage{flex:1;overflow-y:auto;padding:12px 16px 8px}
#invPage h2{font-size:18px;margin-bottom:12px}
.invp-item{display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid #333}
.invp-item:last-child{border-bottom:none}
.invp-name{flex:1;font-size:15px;font-weight:500}
.invp-qty{font-size:15px;color:#34c759;font-weight:600;min-width:24px;text-align:center}
.invp-btn{padding:8px 16px;font-size:16px;font-weight:600;border:none;border-radius:8px;cursor:pointer;touch-action:manipulation;min-width:48px}
.invp-add{background:#34c759;color:#fff}
.invp-take{background:#ff3b30;color:#fff}
.error-text{color:#ff3b30;font-size:13px;margin-top:4px}
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
</div>

<div id="sideMenu">
  <a href="/" class="<?= $page === 'scan' ? 'active' : '' ?>">Scanner</a>
  <a href="/inventory" class="<?= $page === 'inventory' ? 'active' : '' ?>">Inventory</a>
  <a href="/ledger" class="<?= $page === 'ledger' ? 'active' : '' ?>">Ledger</a>
</div>

<div id="overlay"></div>
<div id="banner"></div>

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
    <input type="text" id="editName" class="edit-field" placeholder="Product name (required)" autocomplete="off">
    <input type="text" id="editBrand" class="edit-field" placeholder="Brand (optional)" autocomplete="off">
    <div id="prodQty" style="font-size:14px;color:#34c759;margin-bottom:8px"></div>
    <div class="actions">
      <button id="btnAdd">ADD</button>
      <button id="btnTake">TAKE</button>
      <button id="btnCancel" style="background:#555;color:#fff;flex:0.5">Cancel</button>
    </div>
    <div id="prodError" class="error-text"></div>
  </div>
</div>

<?php endif; ?>

<div id="manual">
  <input type="text" id="manualUpc" inputmode="numeric" pattern="[0-9]*" placeholder="Enter UPC..." maxlength="13">
  <button id="btnManual">Lookup</button>
</div>

<script>
const $ = id => document.getElementById(id);
const page = '<?= $page ?>';

// --- Menu ---
function toggleMenu(open) {
  $('sideMenu').classList.toggle('open', open);
  $('overlay').classList.toggle('show', open);
}
$('menuBtn').addEventListener('click', () => toggleMenu(true));
$('overlay').addEventListener('click', () => toggleMenu(false));
document.querySelectorAll('#sideMenu a').forEach(a => a.addEventListener('click', () => toggleMenu(false)));

// --- Banner ---
function showBanner(msg, persistent) {
  const b = $('banner');
  b.textContent = msg;
  b.style.display = 'block';
  if (!persistent) setTimeout(() => b.style.display = 'none', 3000);
}

// --- API helpers ---
async function apiAction(upc, action, name, brand) {
  const res = await fetch('/api/action', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ upc, action, name: name || null, brand: brand || null })
  });
  return res.json();
}

function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

// --- Manual entry ---
$('btnManual').addEventListener('click', () => {
  const upc = $('manualUpc').value.trim();
  if (upc.length < 12) { return; }
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
        '<span class="lg-name">' + esc(e.name || 'Unknown') + '</span>' +
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
    showBanner('Camera unavailable. Use manual entry below.', true);
  });
}

async function onScanSuccess(decodedText) {
  const upc = decodedText.replace(/[^0-9]/g, '');
  if (upc.length < 12 || upc === lastUpc) return;
  lastUpc = upc;
  await lookupUpc(upc);
}

async function lookupUpc(upc) {
  if (html5QrCode) { try { html5QrCode.pause(); } catch (e) {} }
  $('result').classList.remove('show');
  $('prodError').textContent = '';
  $('btnAdd').disabled = true;
  try {
    const res = await fetch('/api/lookup?upc=' + encodeURIComponent(upc));
    const data = await res.json();
    if (!res.ok) { $('prodError').textContent = data.error; $('result').classList.add('show'); return; }
    lastProduct = data;
    const p = data.product;
    $('editName').value = p && p.name ? p.name : '';
    $('editBrand').value = p && p.brand ? p.brand : '';
    $('editName').placeholder = p ? 'Product name' : 'Product name (required)';
    $('prodQty').textContent = data.inventory_qty > 0 ? 'In stock: ' + data.inventory_qty : '';
    $('btnAdd').disabled = !$('editName').value.trim();
    if (data.warning) showBanner(data.warning, false);
    $('result').classList.add('show');
    if (!p || !p.name) $('editName').focus();
  } catch (e) {
    $('prodError').textContent = 'Network error. Check connection.';
    $('result').classList.add('show');
  }
}

async function doAction(action) {
  if (!lastProduct) return;
  const name = $('editName').value.trim();
  if (action === 'add' && !name) { $('prodError').textContent = 'Enter a product name first.'; return; }
  const brand = $('editBrand').value.trim();
  try {
    const data = await apiAction(lastProduct.upc, action, name, brand);
    if (data.success) {
      lastProduct.inventory_qty = data.new_qty;
      $('prodQty').textContent = data.new_qty > 0 ? 'In stock: ' + data.new_qty : '';
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

$('editName').addEventListener('input', () => {
  $('btnAdd').disabled = !$('editName').value.trim();
});



const urlUpc = new URLSearchParams(window.location.search).get('upc');
if (urlUpc && urlUpc.length >= 12) {
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
