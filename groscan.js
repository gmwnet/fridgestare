var cfg = JSON.parse(document.getElementById('groscan-config').textContent);
var $ = function(id) { return document.getElementById(id); };
var page = cfg.page;
var turnstileKey = cfg.turnstileKey;
var turnstileToken = null;
var currentUser = null;
var lastUpc = null;
var lastProduct = null;
var scanning = true;
var html5QrCode = null;
var suggestTimer = null;

// --- Menu ---
function toggleMenu(open) {
  if (open) {
    $('errorOverlay').classList.remove('show');
    var el = $('result'); if (el) el.classList.remove('show');
    el = $('suggestions'); if (el) el.classList.remove('show');
    lastUpc = null;
  }
  $('sideMenu').classList.toggle('open', open);
  $('overlay').classList.toggle('show', open);
}
$('menuBtn').addEventListener('click', function() { toggleMenu(true); });
$('overlay').addEventListener('click', function() { toggleMenu(false); });
document.querySelectorAll('#sideMenu a').forEach(function(a) { a.addEventListener('click', function() { toggleMenu(false); }); });

// --- Auth ---
function loadUser() {
  try { var u = JSON.parse(localStorage.getItem('groscan_user')); if (u && u.id && u.name && u.expires_at && Date.now() < u.expires_at) currentUser = u; else { localStorage.removeItem('groscan_user'); } } catch (e) {}
  if (currentUser) {
    $('userBadge').textContent = currentUser.name;
    $('logoutIcon').style.display = 'inline';
    $('pinOverlay').style.display = 'none';
    if (page === 'scan' && !html5QrCode) { scanning = true; startScanner(); }
  } else {
    $('userBadge').textContent = '';
    $('logoutIcon').style.display = 'none';
    if (html5QrCode) { try { html5QrCode.stop(); } catch (e) {} }
    var r = $('reader'); if (r) r.style.opacity = '0';
    scanning = false;
    $('pinOverlay').style.display = 'flex';
    $('pinInput').focus();
    turnstileToken = null; if (window.turnstile) turnstile.reset();
  }
}
function onTurnstileSuccess(token) { turnstileToken = token; }
async function doAuth(pin) {
  if (turnstileKey && !turnstileToken) { $('pinError').textContent = 'Please complete the captcha.'; $('pinError').style.display = 'block'; return; }
  try {
    var res = await fetch('/api/auth', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pin: pin, turnstile_token: turnstileToken }) });
    var data = await res.json();
    if (!res.ok) { turnstileToken = null; if (window.turnstile) turnstile.reset(); $('pinError').textContent = data.error || 'Not recognized'; $('pinError').style.display = 'block'; return; }
    currentUser = data.user;
    currentUser.expires_at = Date.now() + 30 * 24 * 60 * 60 * 1000;
    localStorage.setItem('groscan_user', JSON.stringify(currentUser));
    $('userBadge').textContent = currentUser.name;
    $('logoutIcon').style.display = 'inline';
    $('pinOverlay').style.display = 'none';
    turnstileToken = null;
    if (page === 'scan') { scanning = true; startScanner(); }
  } catch (e) { $('pinError').textContent = 'Network error'; $('pinError').style.display = 'block'; }
}
$('pinSubmit').addEventListener('click', function() { doAuth($('pinInput').value.trim()); });
$('pinInput').addEventListener('keydown', function(e) { if (e.key === 'Enter') $('pinSubmit').click(); });
$('userBadge').addEventListener('click', function() {
  localStorage.removeItem('groscan_user');
  currentUser = null;
  loadUser();
});
$('logoutIcon').addEventListener('click', function() {
  localStorage.removeItem('groscan_user');
  currentUser = null;
  loadUser();
});
$('menuSwitchUser').addEventListener('click', function(e) {
  e.preventDefault(); toggleMenu(false);
  localStorage.removeItem('groscan_user');
  currentUser = null;
  loadUser();
});

loadUser();

// --- Error overlay ---
function showError(msg) {
  $('errorMsg').textContent = msg;
  $('errorOverlay').classList.add('show');
}
$('errorClose').addEventListener('click', function() { $('errorOverlay').classList.remove('show'); if (page === 'scan' && scanning) setTimeout(function() { startScanner(); }, 300); });
$('errorOverlay').addEventListener('click', function(e) { if (e.target === e.currentTarget) { $('errorOverlay').classList.remove('show'); if (page === 'scan' && scanning) setTimeout(function() { startScanner(); }, 300); } });

// --- API helpers ---
async function apiAction(upc, action, name, brand) {
  var res = await fetch('/api/action', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ upc: upc, action: action, name: name || null, brand: brand || null, user_id: currentUser ? currentUser.id : null })
  });
  return res.json();
}

function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

// --- Manual entry ---
$('btnManual').addEventListener('click', function() {
  var upc = $('manualUpc').value.trim();
  if (upc.length < 8) { showError('UPC must be 8\u201314 digits.'); return; }
  $('manualUpc').value = '';
  if (page !== 'scan') {
    window.location.href = '/?upc=' + encodeURIComponent(upc);
  } else {
    lookupUpc(upc);
  }
});
$('manualUpc').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') $('btnManual').click();
});
$('manualUpc').addEventListener('input', function() {
  $('clearUpc').style.display = $('manualUpc').value ? 'block' : 'none';
});
$('clearUpc').addEventListener('click', function() {
  $('manualUpc').value = '';
  $('manualUpc').focus();
  $('clearUpc').style.display = 'none';
});

// --- Scanner functions (defined globally, used on scan page) ---
function startScanner() {
  if (html5QrCode) { try { html5QrCode.stop(); } catch (e) {} }
  $('reader').style.opacity = '1';
  html5QrCode = new Html5Qrcode('reader');
  html5QrCode.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 280, height: 150 } },
    onScanSuccess,
    function() {}
  ).catch(function() {
    $('reader').style.opacity = '0';
    showError('Camera unavailable. Use manual entry below.');
  });
  if ($('btnPause')) $('btnPause').textContent = '⏸';
}

async function onScanSuccess(decodedText) {
  var upc = decodedText.replace(/[^0-9]/g, '');
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
    var res = await fetch('/api/lookup?upc=' + encodeURIComponent(upc));
    var data = await res.json();
    if (!res.ok) { showError(data.error); return; }
    lastProduct = data;
    var p = data.product;
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
  var name = $('editName').value.trim();
  if (action === 'add' && !name) { showError('Enter a product name first.'); return; }
  var brand = $('editBrand').value.trim();
  try {
    var data = await apiAction(lastProduct.upc, action, name, brand);
    if (data.success) {
      lastProduct.inventory_qty = data.new_qty;
      $('prodQty').textContent = data.new_qty > 0 ? 'In stock: ' + data.new_qty : '';
      var f = $('flash');
      f.textContent = action === 'add' ? 'Added!' : 'Taken!';
      f.className = 'show ' + action;
      setTimeout(function() { f.className = ''; }, 1000);
    }
  } catch (e) {}
  $('manualUpc').value = '';
  $('result').classList.remove('show');
  lastUpc = null;
  if (scanning) setTimeout(function() { startScanner(); }, 300);
}

// --- Page-specific code ---
if (page === 'inventory') {

async function loadInvPage() {
  try {
    var res = await fetch('/api/inventory');
    var data = await res.json();
    $('invpList').innerHTML = data.items.map(function(item) {
      return '<div class="invp-item">' +
        '<span class="invp-name">' + esc(item.name) + '</span>' +
        '<span class="invp-qty">' + item.qty + '</span>' +
        '<button class="invp-btn invp-take" data-upc="' + item.upc + '" data-action="take">\u2212</button>' +
        '<button class="invp-btn invp-add" data-upc="' + item.upc + '" data-action="add">+</button>' +
      '</div>';
    }).join('');
    document.querySelectorAll('#invpList .invp-btn').forEach(function(btn) {
      btn.addEventListener('click', async function() {
        $('manualUpc').value = '';
        var upc = btn.dataset.upc;
        var action = btn.dataset.action;
        var data2 = await apiAction(upc, action);
        if (data2.success) loadInvPage();
      });
    });
  } catch (e) {}
}

loadInvPage();

} else if (page === 'ledger') {

async function loadLedger() {
  try {
    var res = await fetch('/api/ledger');
    var data = await res.json();
    $('lgList').innerHTML = data.entries.map(function(e) {
      return '<div class="lg-entry">' +
        '<span class="lg-name">' + esc(e.name || 'Unknown') + (e.user ? ' <span style="color:#007aff;font-size:12px">(' + esc(e.user) + ')</span>' : '') + '</span>' +
        '<span class="lg-action lg-' + e.action + '">' + e.action.toUpperCase() + '</span>' +
        '<span class="lg-time">' + e.created_at + '</span>' +
      '</div>';
    }).join('');
  } catch (e) {}
}

loadLedger();

} else {

// --- Scanner page initializations ---
$('btnAdd').addEventListener('click', function() { doAction('add'); });
$('btnTake').addEventListener('click', function() { doAction('take'); });

$('btnCancel').addEventListener('click', function() {
  $('result').classList.remove('show');
  lastUpc = null;
  if (scanning) setTimeout(function() { startScanner(); }, 300);
});

$('editName').addEventListener('input', function() {
  $('btnAdd').disabled = !$('editName').value.trim();
  clearTimeout(suggestTimer);
  var val = $('editName').value.trim();
  if (val.length < 2) { $('suggestions').classList.remove('show'); return; }
  suggestTimer = setTimeout(async function() {
    try {
      var res = await fetch('/api/search?q=' + encodeURIComponent(val));
      var data = await res.json();
      var list = $('suggestions');
      list.innerHTML = data.results.map(function(r) {
        return '<div data-upc="' + r.upc + '" data-name="' + esc(r.name) + '" data-brand="' + esc(r.brand || '') + '">' +
          esc(r.name) + (r.brand ? ' <span class="sug-brand">' + esc(r.brand) + '</span>' : '') +
        '</div>';
      }).join('');
      if (data.results.length) { list.classList.add('show'); } else { list.classList.remove('show'); }
      list.querySelectorAll('div').forEach(function(el) {
        el.addEventListener('click', function() {
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
document.addEventListener('click', function(e) { if (!e.target.closest('#result > div')) $('suggestions').classList.remove('show'); });

var urlUpc = new URLSearchParams(window.location.search).get('upc');
if (urlUpc && urlUpc.length >= 8) {
  $('manualUpc').value = urlUpc;
  lookupUpc(urlUpc);
} else {
  $('manualUpc').value = '';
}

document.addEventListener('visibilitychange', function() {
  if (document.hidden) { scanning = false; } else { scanning = true; startScanner(); }
});

$('btnPause').addEventListener('click', async function() {
  if (scanning) {
    if (html5QrCode) { try { await html5QrCode.stop(); } catch (e) {} }
    scanning = false;
    this.textContent = '▶';
    $('reader').style.opacity = '0';
  } else {
    scanning = true;
    $('reader').style.opacity = '1';
    startScanner();
    this.textContent = '⏸';
  }
});

// Start scanner on page load or after login
if (currentUser) startScanner();

}
