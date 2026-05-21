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

var hiddenAt = 0;

document.addEventListener('visibilitychange', function() {
  if (document.hidden) { hiddenAt = Date.now(); return; }
  var away = Date.now() - hiddenAt;
  if (away > 30000) { location.reload(); return; }
  if (page !== 'scan') return;
  if (processingPhoto) {
    photoSeq++;
    var po = $('photoOverlay'); if (po) po.classList.remove('show');
    resetPhotoState();
  }
  if (!zbarReady) initZbar();
});
window.addEventListener('pageshow', function(e) {
  if (e.persisted) { location.reload(); }
});

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

if (page === 'scan') initZbar();
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
$('pinInput').addEventListener('focus', function() {
  setTimeout(function() { $('pinBox').scrollIntoView({block:'center'}); }, 300);
});
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

function setScanPrompt(show) {
  var el = $('scanPrompt');
  if (el) el.style.display = show ? 'flex' : 'none';
}

if (page === 'scan') {

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
  var btn = $('btnSnap'); if (btn) btn.disabled = false;
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

    selectedTags = (p && p.tags) ? p.tags.slice() : [];
    var tagsContainer = $('resultTags');
    while (tagsContainer.firstChild) tagsContainer.removeChild(tagsContainer.firstChild);
    ['Protein','Main','Sauce','Side','Snack','Dessert','Use Soon','Staple'].forEach(function(tag) {
      var btn = dom('button', {'class':'tag-btn', 'data-tag':tag}, tag);
      if (selectedTags.includes(tag)) btn.classList.add('active');
      btn.addEventListener('click', function() {
        if (selectedTags.includes(tag)) {
          selectedTags = selectedTags.filter(function(t) { return t !== tag; });
          btn.classList.remove('active');
        } else {
          selectedTags.push(tag);
          btn.classList.add('active');
        }
      });
      tagsContainer.appendChild(btn);
    });

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
    if (!data.success) { showError(data.error || 'Action failed'); return; }
    lastProduct.inventory_qty = data.new_qty;
    $('prodQty').textContent = data.new_qty > 0 ? 'In stock: ' + data.new_qty : '';
    await fetch('/api/tag', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ upc: lastProduct.upc, tags: selectedTags })
    });
    var f = $('flash');
    f.textContent = action === 'add' ? 'Added!' : 'Taken!';
    f.className = 'show ' + action;
    setTimeout(function() { f.className = ''; }, 1000);
  } catch (e) {
    showError('Network error');
    return;
  }
  $('manualUpc').value = '';
  $('result').classList.remove('show');
  lastUpc = null;
  setScanPrompt(true);
}

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
  lastProduct = null;
  selectedTags = [];
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

// --- Camera detection ---
(function() {
  var note = $('noCameraNote');
  if (!note) return;
  function hasNoCam() { note.style.display = 'block'; }
  function hasCam() { note.style.display = 'none'; }
  if (!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function') {
    hasNoCam();
    return;
  }
  function doCheck() {
    navigator.mediaDevices.enumerateDevices().then(function(devices) {
      if (devices.some(function(d) { return d.kind === 'videoinput'; })) hasCam(); else hasNoCam();
    }).catch(function() { hasNoCam(); });
  }
  doCheck();
  setTimeout(doCheck, 1000);
})();

} // end if (page === 'scan')

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
        var d = await apiAction(this.dataset.upc, 'take');
        if (d.success) loadInvPage();
      });
      add.addEventListener('click', async function() {
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
      var displayName = e.name || 'Unknown';
      var name = dom('span', {'class':'lg-name'}, displayName);
      if (e.user) name.appendChild(dom('span', {'style':'color:#007aff;font-size:12px'}, ' (' + esc(e.user) + ')'));
      var actionClass = e.action;
      var actionText = e.action.toUpperCase();
      if (['config_change','user_add','user_delete','user_update'].indexOf(e.action) >= 0) {
        actionClass = 'admin';
        actionText = e.action.replace(/_/g, ' ').toUpperCase();
      }
      var action = dom('span', {'class':'lg-action lg-' + actionClass}, actionText);
      var time = dom('span', {'class':'lg-time'}, e.created_at);
      list.appendChild(dom('div', {'class':'lg-entry'}, name, action, time));
    });
  } catch (e) {}
}

loadLedger();

} else if (page === 'meal-planner') {

var mealTags = ['Protein','Main','Sauce','Side','Snack','Dessert','Use Soon','Staple'];
var selectedMealTags = [];
var mealSuggestions = [];

function renderMealTagToggles() {
  var container = $('mealTagList');
  while (container.firstChild) container.removeChild(container.firstChild);
  mealTags.forEach(function(tag) {
    var btn = dom('button', {'class':'tag-btn', 'data-tag':tag}, tag);
    if (selectedMealTags.includes(tag)) btn.classList.add('active');
    btn.addEventListener('click', function() {
      if (selectedMealTags.includes(tag)) {
        selectedMealTags = selectedMealTags.filter(function(t) { return t !== tag; });
        btn.classList.remove('active');
      } else {
        selectedMealTags.push(tag);
        btn.classList.add('active');
      }
      onMealTagsChanged();
    });
    container.appendChild(btn);
  });
}

function onMealTagsChanged() {
  if (selectedMealTags.length === 0) {
    $('mealSuggestions').style.display = 'none';
    return;
  }
  fetchMealSuggestions();
}

async function fetchMealSuggestions() {
  try {
    var res = await fetch('/api/meal-plan?tags=' + encodeURIComponent(selectedMealTags.join(',')));
    var data = await res.json();
    renderMealSuggestions(data);
  } catch (e) { showError('Could not get suggestions.'); }
}

function renderMealSuggestions(data) {
  mealSuggestions = data.suggestions || [];
  var list = $('mealSuggestionList');
  while (list.firstChild) list.removeChild(list.firstChild);
  var unavail = $('mealUnavailable');
  unavail.style.display = 'none';
  while (unavail.firstChild) unavail.removeChild(unavail.firstChild);
  mealSuggestions.forEach(function(s) {
    var row = dom('div', {'class':'invp-item'});
    var cb = dom('input', {'type':'checkbox', 'data-upc':s.upc, 'checked':'checked', 'style':'width:18px;height:18px;accent-color:#34c759;cursor:pointer'});
    row.appendChild(cb);
    var nameEl = dom('span', {'class':'invp-name'}, esc(s.name));
    if (s.brand) nameEl.appendChild(dom('span', {'style':'font-size:12px;color:#888;margin-left:6px'}, esc(s.brand)));
    if (s.useSoon) nameEl.appendChild(dom('span', {'style':'margin-left:6px;padding:2px 6px;background:#ff950033;color:#ff9500;border-radius:4px;font-size:11px;font-weight:600'}, 'Use soon!'));
    row.appendChild(nameEl);
    var meta = dom('span', {'style':'font-size:13px;color:#888'}, 'x' + s.qty);
    if (s.qty === 1) meta.appendChild(dom('span', {'style':'font-size:11px;color:#ff3b30;margin-left:4px'}, '(last one!)'));
    row.appendChild(meta);
    list.appendChild(row);
  });
  if (data.unavailable && data.unavailable.length > 0) {
    unavail.textContent = "You don't have anything tagged: " + data.unavailable.join(', ') + '. Add some items or pick different tags.';
    unavail.style.display = 'block';
  }
  $('mealSuggestions').style.display = mealSuggestions.length > 0 || (data.unavailable && data.unavailable.length > 0) ? 'block' : 'none';
}

$('mealReshuffle').addEventListener('click', function() {
  if (selectedMealTags.length > 0) fetchMealSuggestions();
});

$('mealConfirm').addEventListener('click', async function() {
  if (!currentUser) { showError('Log in first.'); return; }
  if (mealSuggestions.length === 0) return;
  var checkboxes = document.querySelectorAll('#mealSuggestionList input[type=checkbox]:checked');
  if (checkboxes.length === 0) { showError('Select at least one item.'); return; }
  var taken = 0;
  for (var i = 0; i < checkboxes.length; i++) {
    var upc = checkboxes[i].dataset.upc;
    var item = mealSuggestions.find(function(s) { return s.upc === upc; });
    if (!item) continue;
    try {
      var d = await apiAction(upc, 'take', item.name, item.brand || null);
      if (d.success) taken++;
    } catch (e) {}
  }
  var f = $('flash');
  f.textContent = 'Removed ' + taken + ' items from inventory. Enjoy your meal!';
  f.className = 'show add';
  setTimeout(function() { f.className = ''; window.location.href = '/'; }, 2000);
});

renderMealTagToggles();

} else if (page === 'settings') {

(async function() {
  try {
    var res = await fetch('/api/config');
    var data = await res.json();
    $('cfg_timezone').value = data.timezone || 'UTC';
    $('cfg_session_timeout_days').value = data.session_timeout_days || 30;
    $('cfg_pin_max_attempts').value = data.pin_max_attempts || 3;
    $('cfg_pin_lockout_hours').value = data.pin_lockout_hours || 1;
    $('cfg_default_qty').value = data.default_qty || 1;
    $('cfg_debug').checked = !!data.debug;
    $('cfg_turnstile_site_key').value = data.turnstile_site_key || '';
    $('cfg_turnstile_secret_key').value = data.turnstile_secret_key || '';
    $('cfg_upcitemdb_key').value = data.upcitemdb_key || '';
  } catch (e) { console.error('loadSettings error:', e); }

  $('btnSaveSettings').addEventListener('click', async function() {
    var tz = $('cfg_timezone').value.trim();
    var sd = parseInt($('cfg_session_timeout_days').value, 10);
    var pa = parseInt($('cfg_pin_max_attempts').value, 10);
    var lh = parseInt($('cfg_pin_lockout_hours').value, 10);
    var dq = parseInt($('cfg_default_qty').value, 10);

    var payload = {
      timezone: tz,
      session_timeout_days: sd,
      pin_max_attempts: pa,
      pin_lockout_hours: lh,
      default_qty: dq,
      debug: $('cfg_debug').checked,
      turnstile_site_key: $('cfg_turnstile_site_key').value.trim().substring(0, 512),
      turnstile_secret_key: $('cfg_turnstile_secret_key').value.trim().substring(0, 512),
      upcitemdb_key: $('cfg_upcitemdb_key').value.trim().substring(0, 512)
    };
    payload.user_id = currentUser ? currentUser.id : null;
    try {
      var r = await fetch('/api/config', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
      var d = await r.json();
      if (d.success) {
        var f = $('flash');
        f.textContent = 'Settings saved.';
        f.className = 'show add';
        setTimeout(function() { f.className = ''; }, 2000);
      }
      else showError(d.error || 'Save failed');
    } catch (e) { showError('Network error'); }
  });
})();

} else if (page === 'users') {

async function loadUsers() {
  try {
    var res = await fetch('/api/users');
    var data = await res.json();
    var list = $('usersList');
    while (list.firstChild) list.removeChild(list.firstChild);
    if (!data.users || data.users.length === 0) {
      list.appendChild(dom('p', {'style':'color:#888'}, 'No users found.'));
      return;
    }
    data.users.forEach(function(u) {
      var row = dom('div', {'class':'usr-row'});
      row.appendChild(dom('span', {'class':'usr-name'}, esc(u.name)));
      if (data.users.length > 1) {
        var del = dom('button', {'class':'usr-del'}, 'Delete');
        del.addEventListener('click', async function() {
          if (!confirm('Delete user "' + u.name + '"?')) return;
          try {
            var r = await fetch('/api/user/' + u.id, {method:'DELETE',headers:{'Content-Type':'application/json'},body:JSON.stringify({user_id:currentUser?currentUser.id:null})});
            var d = await r.json();
            if (d.success) loadUsers(); else showError(d.error||'Delete failed');
          } catch (e) { showError('Network error'); }
        });
        row.appendChild(del);
      }
      list.appendChild(row);
    });
  } catch (e) {}
}

$('btnAddUser').addEventListener('click', async function() {
  var name = $('newUserName').value.trim();
  var pin = $('newUserPin').value.trim();
  if (!name || !/^\d{4,8}$/.test(pin)) { showError('Name and 4-8 digit PIN required'); return; }
  try {
    var r = await fetch('/api/user', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:name,pin:pin,user_id:currentUser?currentUser.id:null})});
    var d = await r.json();
    if (d.success) { $('newUserName').value=''; $('newUserPin').value=''; loadUsers(); }
    else showError(d.error||'Add failed');
  } catch (e) { showError('Network error'); }
});

$('btnChangeMyPin').addEventListener('click', async function() {
  var newPin = $('selfNewPin').value.trim();
  var confirmPin = $('selfConfirmPin').value.trim();
  if (!/^\d{4,8}$/.test(newPin)) { showError('PIN must be 4-8 digits'); return; }
  if (newPin !== confirmPin) { showError('PINs do not match'); return; }
  if (!currentUser) { showError('Not logged in'); return; }
  try {
    var r = await fetch('/api/user/change-pin', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:currentUser.id,new_pin:newPin})});
    var d = await r.json();
    if (d.success) { $('selfNewPin').value=''; $('selfConfirmPin').value=''; showError('PIN changed.'); }
    else showError(d.error||'Change failed');
  } catch (e) { showError('Network error'); }
});

loadUsers();

}
