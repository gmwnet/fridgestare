var cfg = JSON.parse(document.getElementById('groscan-config').textContent);
var $ = function(id) { return document.getElementById(id); };
var page = cfg.page;
var turnstileKey = cfg.turnstileKey;
var debug = cfg.debug || false;
var sessionDays = cfg.sessionDays || 30;
var turnstileToken = null;
var currentUser = null;
var lastUpc = null;
var lastProduct = null;
var lookupId = 0;
var suggestTimer = null;
var zbarReady = false;
var scanLogCount = 0;
var processingPhoto = false;
var photoSeq = 0;
var tagUpc = null;
var selectedTags = [];

function scanLog(msg) {
  if (!debug) return;
  scanLogCount++;
  var el = $('scannerLog');
  if (!el) return;
  el.style.display = 'block';
  var t = document.createTextNode('[' + scanLogCount + '] ' + msg);
  el.appendChild(document.createElement('br'));
  el.appendChild(t);
  el.scrollTop = el.scrollHeight;
}

initZbar();
function initZbar() {
  if (typeof zbarWasm === 'undefined') { scanLog('zbarWasm global not found'); return; }
  if (zbarReady) { return; }
  scanLog('Initializing ZBar WASM...');

  zbarWasm.setModuleArgs({
    locateFile: function(f, d) { return '/zbar.wasm'; }
  });

  zbarWasm.getInstance().then(function() {
    zbarReady = true;
    scanLog('ZBar WASM ready');
  }).catch(function(err) {
    scanLog('ZBar init error: ' + (err.message || err));
  });
}

window.addEventListener('error', function(e) {
  console.error('Global error:', e.error || e.message);
});
window.addEventListener('unhandledrejection', function(e) {
  console.error('Unhandled rejection:', e.reason);
  if (page === 'scan') showError('Scanner error: ' + (e.reason && e.reason.message ? e.reason.message : e.reason));
});

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
    if (page === 'scan') setScanPrompt(true);
  } else {
    $('userBadge').textContent = '';
    $('logoutIcon').style.display = 'none';
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
    currentUser.expires_at = Date.now() + sessionDays * 24 * 60 * 60 * 1000;
    localStorage.setItem('groscan_user', JSON.stringify(currentUser));
    $('userBadge').textContent = currentUser.name;
    $('logoutIcon').style.display = 'inline';
    $('pinOverlay').style.display = 'none';
    turnstileToken = null;
    if (page === 'scan') setScanPrompt(true);
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
$('errorClose').addEventListener('click', function() { $('errorOverlay').classList.remove('show'); });
$('errorOverlay').addEventListener('click', function(e) { if (e.target === e.currentTarget) { $('errorOverlay').classList.remove('show'); } });

// --- API helpers ---
async function apiAction(upc, action, name, brand, qty) {
  var payload = { upc: upc, action: action, name: name || null, brand: brand || null, user_id: currentUser ? currentUser.id : null };
  if (typeof qty !== 'undefined') payload.qty = qty;
  var res = await fetch('/api/action', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  return res.json();
}

function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function dom(tag, attrs) {
  var el = document.createElement(tag);
  if (attrs) for (var k in attrs) el.setAttribute(k, attrs[k]);
  for (var i = 2; i < arguments.length; i++) {
    var c = arguments[i];
    if (typeof c === 'string') el.appendChild(document.createTextNode(c));
    else if (c) el.appendChild(c);
  }
  return el;
}

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

function setScanPrompt(show) {
  var el = $('scanPrompt');
  if (el) el.style.display = show ? 'flex' : 'none';
}

// --- Photo snap scanning ---
if ($('btnSnap')) $('btnSnap').addEventListener('click', function() {
  if (processingPhoto) return;
  $('photoInput').click();
});
$('photoInput').addEventListener('change', function(e) {
  var file = e.target.files[0];
  this.value = '';
  if (!file) return;
  processingPhoto = true;
  setScanPrompt(false);
  processPhotoFile(file);
});
$('photoRetry').addEventListener('click', function() {
  if (processingPhoto) return;
  $('photoOverlay').classList.remove('show');
  $('photoInput').click();
});
$('photoCancel').addEventListener('click', function() {
  photoSeq++;
  $('photoOverlay').classList.remove('show');
  resetPhotoState();
  lastUpc = null;
  setScanPrompt(true);
});

function loadPhotoToCanvas(file, maxDim) {
  return new Promise(function(resolve, reject) {
    var reader = new FileReader();
    reader.onload = function(e) {
      var img = new Image();
      img.onload = function() {
        var w = img.width, h = img.height;
        if (w > maxDim || h > maxDim) {
          if (w > h) { h = Math.round(h * maxDim / w); w = maxDim; }
          else { w = Math.round(w * maxDim / h); h = maxDim; }
        }
        var c = document.createElement('canvas');
        c.width = w; c.height = h;
        var ctx = c.getContext('2d');
        ctx.drawImage(img, 0, 0, w, h);
        resolve({ canvas: c, width: w, height: h });
      };
      img.onerror = reject;
      img.src = e.target.result;
    };
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

function extractGrayBuffer(canvas, width, height) {
  var ctx = canvas.getContext('2d');
  var imageData = ctx.getImageData(0, 0, width, height);
  var rgba = imageData.data;
  var gray = new Uint8Array(width * height);
  for (var g = 0; g < gray.length; g++) {
    var p = g * 4;
    gray[g] = (19595 * rgba[p] + 38469 * rgba[p+1] + 7472 * rgba[p+2]) >> 16;
  }
  // contrast stretch
  var min = 255, max = 0;
  for (var g = 0; g < gray.length; g++) {
    if (gray[g] < min) min = gray[g];
    if (gray[g] > max) max = gray[g];
  }
  var range = max - min;
  if (range > 10) {
    for (var g = 0; g < gray.length; g++) {
      gray[g] = ((gray[g] - min) * 255 / range) | 0;
    }
  }
  return gray;
}

async function processPhotoFile(file) {
  var mySeq = ++photoSeq;
  $('btnSnap').disabled = true;
  $('photoThumb').src = '';
  $('photoStatus').textContent = 'Loading photo...';
  $('photoOverlay').classList.add('show');

  try {
    var info = await loadPhotoToCanvas(file, 1600);
    if (mySeq !== photoSeq) return;
    $('photoThumb').src = info.canvas.toDataURL('image/jpeg', 0.5);
    $('photoStatus').textContent = 'Reading barcode...';

    var gray = extractGrayBuffer(info.canvas, info.width, info.height);
    var code = null;

    if (zbarReady) {
      try {
        var symbols = await zbarWasm.scanGrayBuffer(gray.buffer, info.width, info.height);
        if (mySeq !== photoSeq) return;
        if (symbols && symbols.length > 0) {
          for (var j = 0; j < symbols.length; j++) {
            var c = symbols[j].decode().replace(/[^0-9]/g, '');
            if (c.length >= 8 && c.length <= 14) { code = c; break; }
          }
        }
      } catch (e) {
        scanLog('Photo ZBar error: ' + (e.message || e));
      }
    }

    if (code) {
      $('photoOverlay').classList.remove('show');
      resetPhotoState();
      beep();
      lookupUpc(code);
      return;
    }

    // Client-side failed — try server-side safety net
    $('photoStatus').textContent = 'Checking server...';
    await uploadPhotoFile(file, mySeq);
  } catch (e) {
    if (mySeq !== photoSeq) return;
    $('photoStatus').textContent = 'Error: ' + (e.message || e);
    scanLog('Photo process error: ' + (e.message || e));
  }
}

function resetPhotoState() {
  processingPhoto = false;
  $('btnSnap').disabled = false;
}

async function uploadPhotoFile(file, mySeq) {
  try {
    var info = await loadPhotoToCanvas(file, 2400);
    if (mySeq !== photoSeq) return;
    var blob = await new Promise(function(resolve, reject) {
      info.canvas.toBlob(function(b) { b ? resolve(b) : reject(new Error('Canvas toBlob failed')); }, 'image/jpeg', 0.85);
    });
    var form = new FormData();
    form.append('photo', blob, 'barcode.jpg');
    var res = await fetch('/api/scan-photo', { method: 'POST', body: form });
    if (mySeq !== photoSeq) return;
    var data = await res.json();
    if (mySeq !== photoSeq) return;
    if (data.success && data.upc) {
      $('photoOverlay').classList.remove('show');
      resetPhotoState();
      beep();
      lookupUpc(data.upc);
      return;
    }
    $('photoStatus').textContent = data.error || 'No barcode found.';
    resetPhotoState();
  } catch (e) {
    if (mySeq !== photoSeq) return;
    $('photoStatus').textContent = 'Server check failed.';
    resetPhotoState();
    scanLog('Photo upload error: ' + (e.message || e));
  }
}

// --- Tag overlay ---
var manualQty = 1;
var manualSuggestTimer = null;

function showTagOverlay(upc, name, brand) {
  tagUpc = upc;
  selectedTags = [];
  document.querySelectorAll('.tag-btn').forEach(function(btn) {
    btn.classList.remove('active');
  });
  $('tagOverlay').classList.add('show');
}

function hideTagOverlay() {
  $('tagOverlay').classList.remove('show');
  tagUpc = null;
  $('manualSuggestions').style.display = 'none';
}

function resetManualForm() {
  $('manualName').value = '';
  $('manualBrand').value = '';
  manualQty = 1;
  $('manualQty').textContent = '1';
  selectedTags = [];
  document.querySelectorAll('.tag-btn').forEach(function(btn) {
    btn.classList.remove('active');
  });
}

async function saveManualItem() {
  var name = $('manualName').value.trim();
  if (!name) { showError('Enter a product name first.'); return; }
  var brand = $('manualBrand').value.trim();
  try {
    var data = await apiAction(tagUpc, 'add', name, brand, manualQty);
    if (data.success) {
      var f = $('flash');
      f.textContent = 'Added!';
      f.className = 'show add';
      setTimeout(function() { f.className = ''; }, 1000);
      await fetch('/api/tag', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ upc: tagUpc, tags: selectedTags })
      });
    } else {
      showError(data.error || 'Action failed');
    }
  } catch (e) {
    showError('Network error');
  }
  hideTagOverlay();
  resetManualForm();
  lastUpc = null;
  setScanPrompt(true);
}

document.querySelectorAll('.tag-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var tag = this.dataset.tag;
    if (selectedTags.includes(tag)) {
      selectedTags = selectedTags.filter(function(t) { return t !== tag; });
      this.classList.remove('active');
    } else {
      selectedTags.push(tag);
      this.classList.add('active');
    }
  });
});

$('btnTagSave').addEventListener('click', saveManualItem);
$('btnTagSkip').addEventListener('click', function() { hideTagOverlay(); resetManualForm(); lastUpc = null; setScanPrompt(true); });

$('manualQtyMinus').addEventListener('click', function() {
  if (manualQty > 1) { manualQty--; $('manualQty').textContent = String(manualQty); }
});
$('manualQtyPlus').addEventListener('click', function() {
  manualQty++; $('manualQty').textContent = String(manualQty);
});

$('manualName').addEventListener('input', function() {
  clearTimeout(manualSuggestTimer);
  var val = $('manualName').value.trim();
  if (val.length < 2) { $('manualSuggestions').style.display = 'none'; return; }
  manualSuggestTimer = setTimeout(async function() {
    try {
      var res = await fetch('/api/search?q=' + encodeURIComponent(val));
      var data = await res.json();
      var list = $('manualSuggestions');
      while (list.firstChild) list.removeChild(list.firstChild);
      data.results.forEach(function(r) {
        var div = dom('div', {'style':'padding:10px 14px;font-size:15px;cursor:pointer;border-bottom:1px solid #333'}, esc(r.name));
        if (r.brand) div.appendChild(dom('span', {'style':'font-size:12px;color:#888'}, ' ' + esc(r.brand)));
        div.addEventListener('click', function() {
          $('manualName').value = r.name;
          $('manualBrand').value = r.brand || '';
          $('manualSuggestions').style.display = 'none';
        });
        list.appendChild(div);
      });
      list.style.display = data.results.length > 0 ? 'block' : 'none';
    } catch (e) {}
  }, 200);
});
document.addEventListener('click', function(e) { if (!e.target.closest('#manualName')) $('manualSuggestions').style.display = 'none'; });

// --- Detection feedback ---
function detectionFlash() {
  var r = $('reader');
  r.style.boxShadow = 'inset 0 0 80px rgba(0,255,0,.6)';
  setTimeout(function() { r.style.boxShadow = ''; }, 300);
}

function beep() {
  try {
    var ctx = new (window.AudioContext || window.webkitAudioContext)();
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.type = 'sine';
    osc.frequency.value = 880;
    gain.gain.value = 0.3;
    osc.start();
    osc.stop(ctx.currentTime + 0.12);
  } catch (e) {}
}

async function lookupUpc(upc) {
  processingPhoto = false;
  var id = ++lookupId;
  lastUpc = upc;
  $('result').classList.remove('show');
  $('errorOverlay').classList.remove('show');
  $('banner').style.display = 'none';
  $('btnAdd').disabled = true;
  try {
    var res = await fetch('/api/lookup?upc=' + encodeURIComponent(upc));
    var data = await res.json();
    if (lookupId !== id) return;
    if (!res.ok) { showError(data.error); return; }
    lastProduct = data;
    var p = data.product;
    $('editName').value = p && p.name ? p.name : '';
    $('editBrand').value = p && p.brand ? p.brand : '';
    $('editName').placeholder = p ? 'Product name' : 'Product name (required)';
    $('prodQty').textContent = data.inventory_qty > 0 ? 'In stock: ' + data.inventory_qty : '';
    $('btnAdd').disabled = !$('editName').value.trim();
    if (data.warning) { $('banner').textContent = upc + ': ' + data.warning; $('banner').style.display = 'block'; }
    setScanPrompt(false);
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
    } else {
      showError(data.error || 'Action failed');
    }
  } catch (e) {
    showError('Network error');
  }
  $('manualUpc').value = '';
  $('result').classList.remove('show');
  lastUpc = null;
  setScanPrompt(true);
}

// --- Page-specific code ---
if (page === 'inventory') {

async function loadInvPage() {
  try {
    var res = await fetch('/api/inventory');
    var data = await res.json();
    var list = $('invpList');
    while (list.firstChild) list.removeChild(list.firstChild);
    data.items.forEach(function(item) {
      var name = dom('span', {'class':'invp-name'}, esc(item.name));
      var qty = dom('span', {'class':'invp-qty'}, String(item.qty));
      var take = dom('button', {'class':'invp-btn invp-take', 'data-upc':item.upc, 'data-action':'take'}, '\u2212');
      var add = dom('button', {'class':'invp-btn invp-add', 'data-upc':item.upc, 'data-action':'add'}, '+');
      take.addEventListener('click', async function() {
        $('manualUpc').value = '';
        var d = await apiAction(this.dataset.upc, 'take');
        if (d.success) loadInvPage();
      });
      add.addEventListener('click', async function() {
        $('manualUpc').value = '';
        var d = await apiAction(this.dataset.upc, 'add');
        if (d.success) loadInvPage();
      });
      list.appendChild(dom('div', {'class':'invp-item'}, name, qty, take, add));
    });
  } catch (e) {}
}

loadInvPage();

} else if (page === 'ledger') {

async function loadLedger() {
  try {
    var res = await fetch('/api/ledger');
    var data = await res.json();
    var list = $('lgList');
    while (list.firstChild) list.removeChild(list.firstChild);
    data.entries.forEach(function(e) {
      var name = dom('span', {'class':'lg-name'}, e.name || 'Unknown');
      if (e.user) name.appendChild(dom('span', {'style':'color:#007aff;font-size:12px'}, ' (' + esc(e.user) + ')'));
      var action = dom('span', {'class':'lg-action lg-' + e.action}, e.action.toUpperCase());
      var time = dom('span', {'class':'lg-time'}, e.created_at);
      list.appendChild(dom('div', {'class':'lg-entry'}, name, action, time));
    });
  } catch (e) {}
}

loadLedger();

} else {

// --- Scanner page initializations ---
function generateInternalUpc() {
  return '2' + String(Date.now()).slice(-12);
}

if ($('btnNoUpc')) $('btnNoUpc').addEventListener('click', function() {
  var upc = generateInternalUpc();
  lastUpc = upc;
  tagUpc = upc;
  resetManualForm();
  setScanPrompt(false);
  showTagOverlay(upc, '', '');
  $('manualName').focus();
});

$('btnAdd').addEventListener('click', function() { doAction('add'); });
$('btnTake').addEventListener('click', function() { doAction('take'); });

$('btnCancel').addEventListener('click', function() {
  $('result').classList.remove('show');
  lastUpc = null;
  setScanPrompt(true);
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
      while (list.firstChild) list.removeChild(list.firstChild);
      data.results.forEach(function(r) {
        var div = dom('div', {'data-upc':r.upc, 'data-name':r.name, 'data-brand':r.brand || ''}, esc(r.name));
        if (r.brand) div.appendChild(dom('span', {'class':'sug-brand'}, ' ' + esc(r.brand)));
        div.addEventListener('click', function() {
          $('editName').value = this.dataset.name;
          $('editBrand').value = this.dataset.brand;
          $('suggestions').classList.remove('show');
          $('btnAdd').disabled = false;
          $('editName').focus();
        });
        list.appendChild(div);
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
}
