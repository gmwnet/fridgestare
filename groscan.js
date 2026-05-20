var cfg = JSON.parse(document.getElementById('groscan-config').textContent);
var $ = function(id) { return document.getElementById(id); };
var page = cfg.page;
var turnstileKey = cfg.turnstileKey;
var debug = cfg.debug || false;
var turnstileToken = null;
var currentUser = null;
var lastUpc = null;
var lastUpcCount = 0;
var lastProduct = null;
var scanning = true;
var scannerStarting = false;
var detectorTimer = null;
var cameraStream = null;
var overlayCanvas = null;
var scanCanvas = null;
var scanCtx = null;
var lookupId = 0;
var suggestTimer = null;
var zbarReady = false;
var scanLogCount = 0;
var frameCount = 0;
var zbarScanner = null;
var imageCapture = null;
var photoDecodePending = false;
var lastPhotoAttempt = 0;
var processingPhoto = false;
var photoSeq = 0;

function scanLog(msg) {
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
    scanLog('ZBar WASM loaded, creating scanner');
    return zbarWasm.ZBarScanner.create();
  }).then(function(scanner) {
    scanner.enableCache(true);
    scanner.setConfig(zbarWasm.ZBarSymbolType.ZBAR_NONE, zbarWasm.ZBarConfigType.ZBAR_CFG_ENABLE, 0);
    scanner.setConfig(zbarWasm.ZBarSymbolType.ZBAR_EAN13, zbarWasm.ZBarConfigType.ZBAR_CFG_ENABLE, 1);
    scanner.setConfig(zbarWasm.ZBarSymbolType.ZBAR_UPCA, zbarWasm.ZBarConfigType.ZBAR_CFG_ENABLE, 1);
    scanner.setConfig(zbarWasm.ZBarSymbolType.ZBAR_EAN8, zbarWasm.ZBarConfigType.ZBAR_CFG_ENABLE, 1);
    scanner.setConfig(zbarWasm.ZBarSymbolType.ZBAR_UPCE, zbarWasm.ZBarConfigType.ZBAR_CFG_ENABLE, 1);
    scanner.setConfig(zbarWasm.ZBarSymbolType.ZBAR_CODE128, zbarWasm.ZBarConfigType.ZBAR_CFG_ENABLE, 1);
    scanner.setConfig(zbarWasm.ZBarSymbolType.ZBAR_CODE39, zbarWasm.ZBarConfigType.ZBAR_CFG_ENABLE, 1);
    scanner.setConfig(zbarWasm.ZBarSymbolType.ZBAR_I25, zbarWasm.ZBarConfigType.ZBAR_CFG_ENABLE, 1);
    scanner.setConfig(zbarWasm.ZBarSymbolType.ZBAR_NONE, zbarWasm.ZBarConfigType.ZBAR_CFG_UNCERTAINTY, 0);
    scanner.setConfig(zbarWasm.ZBarSymbolType.ZBAR_NONE, zbarWasm.ZBarConfigType.ZBAR_CFG_X_DENSITY, 5);
    scanner.setConfig(zbarWasm.ZBarSymbolType.ZBAR_NONE, zbarWasm.ZBarConfigType.ZBAR_CFG_Y_DENSITY, 5);
    zbarScanner = scanner;
    zbarReady = true;
    scanLog('ZBar scanner ready');
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
    if (page === 'scan' && !cameraStream) { scanLog('User logged in, starting scanner'); scanning = true; startScanner(); }
  } else {
    $('userBadge').textContent = '';
    $('logoutIcon').style.display = 'none';
    if (cameraStream) { scanLog('User logged out, stopping scanner'); stopScanner(); }
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

// --- Photo snap scanning ---
$('btnSnap').addEventListener('click', function() {
  if (processingPhoto) return;
  $('photoInput').click();
});
$('photoInput').addEventListener('change', function(e) {
  var file = e.target.files[0];
  this.value = '';
  if (!file) return;
  processingPhoto = true;
  stopScanner();
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
  if (scanning) setTimeout(function() { startScanner(); }, 300);
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

    if (zbarReady && zbarScanner) {
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

// --- Scanner functions ---
function startScanner() {
  if (scannerStarting) { scanLog('startScanner: already starting, skip'); return; }
  scannerStarting = true;
  scanLog('startScanner() called');
  $('btnSnap').disabled = false;
  stopScanner();

  if (typeof zbarWasm === 'undefined') {
    scannerStarting = false;
    scanLog('zbarWasm undefined');
    $('reader').style.opacity = '0';
    showError('Scanner library not loaded. Use manual entry below.');
    return;
  }

  var doStart = function() {
    scanLog('Starting camera...');
    $('reader').style.opacity = '1';
    scanCanvas = document.createElement('canvas');
    scanCtx = scanCanvas.getContext('2d', { willReadFrequently: true });

    navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
      audio: false
    }).then(function(stream) {
      scanLog('Camera stream received');
      cameraStream = stream;
      var track = stream.getVideoTracks()[0];
      if (track && window.ImageCapture) {
        imageCapture = new ImageCapture(track);
        scanLog('ImageCapture ready');
      } else {
        scanLog('ImageCapture N/A');
      }
      var video = document.createElement('video');
      video.setAttribute('autoplay', '');
      video.setAttribute('playsinline', '');
      video.setAttribute('muted', '');
      video.srcObject = stream;
      video.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover';

      var r = $('reader');
      while (r.firstChild) r.removeChild(r.firstChild);
      r.appendChild(video);

      overlayCanvas = document.createElement('canvas');
      overlayCanvas.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:2';
      r.appendChild(overlayCanvas);

      video.addEventListener('loadedmetadata', function() {
        scanLog('Video ' + (video.videoWidth||'?') + 'x' + (video.videoHeight||'?'));
        video.play();
        scanCanvas.width = video.videoWidth || 640;
        scanCanvas.height = video.videoHeight || 480;
        scanLog('Detect loop starting');
        scannerStarting = false;
        detectLoop();
      });
    }).catch(function(err) {
      scannerStarting = false;
      scanLog('Camera error: ' + (err.message || err));
      $('reader').style.opacity = '0';
      showError('Camera unavailable. Use manual entry below.');
    });

    if ($('btnPause')) $('btnPause').textContent = '\u23F8';
  };

  if (!zbarReady) {
    scanLog('Waiting for ZBar...');
    zbarWasm.getInstance().then(function() {
      zbarReady = true;
      scanLog('ZBar ready, starting');
      doStart();
    }).catch(function(err) {
      scannerStarting = false;
      scanLog('ZBar failed: ' + (err.message || err));
      showError('Scanner init failed: ' + (err.message || err));
    });
  } else {
    doStart();
  }
}

function detectLoop() {
  if (!scanning) return;

  var video = $('reader').querySelector('video');
  if (!video || video.readyState < 2) {
    detectorTimer = setTimeout(detectLoop, 150);
    return;
  }

  var vw = scanCanvas.width;
  var vh = scanCanvas.height;

  if (!vw || !vh) {
    detectorTimer = setTimeout(detectLoop, 150);
    return;
  }

  try {
    scanCtx.drawImage(video, 0, 0, vw, vh);
  } catch (e) {
    detectorTimer = setTimeout(detectLoop, 150);
    return;
  }

  var imageData = scanCtx.getImageData(0, 0, vw, vh);
  frameCount++;

  if (overlayCanvas) {
    var r = $('reader');
    var rw = r.clientWidth;
    var rh = r.clientHeight;
    if (rw && rh) {
      overlayCanvas.width = rw;
      overlayCanvas.height = rh;
      overlayCanvas.getContext('2d').clearRect(0, 0, rw, rh);
    }
  }

  if (frameCount <= 10) {
    var bright = 0, count = 0;
    for (var p = 0; p < imageData.data.length; p += 16) { bright += imageData.data[p]; count++; }
    scanLog('Frame ' + frameCount + ': ' + vw + 'x' + vh + ' avg=' + (bright/count).toFixed(0) + ' => zbar=' + (!!zbarScanner));
  }

  try {
    var gray = new Uint8Array(vw * vh);
    var rgba = imageData.data;
    for (var g = 0; g < gray.length; g++) {
      var p = g * 4;
      gray[g] = (19595 * rgba[p] + 38469 * rgba[p+1] + 7472 * rgba[p+2]) >> 16;
    }

    var min = 255, max = 0;
    for (var g = 0; g < gray.length; g++) { if (gray[g] < min) min = gray[g]; if (gray[g] > max) max = gray[g]; }
    var range = max - min;
    if (range > 10) { for (var g = 0; g < gray.length; g++) { gray[g] = ((gray[g] - min) * 255 / range) | 0; } }

    if (!zbarScanner) {
      if (scanning) detectorTimer = setTimeout(detectLoop, 200);
      return;
    }

    var fallbackScan = function() {
      zbarWasm.scanGrayBuffer(gray.buffer, vw, vh).then(function(symbols) {
        if (!scanning) return;
        if (symbols.length > 0 && frameCount <= 5) scanLog('scanGrayBuffer found ' + symbols.length);
        processSymbols(symbols);
      }).catch(function(err) {
        if (frameCount <= 5) scanLog('scanGrayBuffer err: ' + (err.message||'').substring(0, 60));
        if (scanning) detectorTimer = setTimeout(detectLoop, 200);
      });
    };

    zbarWasm.ZBarImage.createFromGrayBuffer(vw, vh, gray.buffer).then(function(img) {
      if (!scanning) { img.destroy(); return; }
      var count = zbarScanner.scan(img);
      if (count < 0) { img.destroy(); fallbackScan(); return; }
      var symbols = count > 0 ? img.getSymbols() : [];
      var hasResults = symbols.length > 0;
      if (hasResults && frameCount <= 10) scanLog('Frame ' + frameCount + ': ZBarScanner found ' + symbols.length);
      img.destroy();
      if (!scanning) return;
      if (!hasResults) { fallbackScan(); return; }
      processSymbols(symbols);
    }).catch(function(err) {
      if (frameCount <= 5) scanLog('createFromGrayBuffer err: ' + (err.message||'').substring(0, 60));
      fallbackScan();
    });
  } catch (e) {
    if (frameCount <= 5) scanLog('ZBar exception: ' + (e.message || e));
    if (scanning) detectorTimer = setTimeout(detectLoop, 500);
  }
}

function tryHighResPhoto() {
  if (!imageCapture || photoDecodePending || !scanning) return;
  var now = Date.now();
  if (now - lastPhotoAttempt < 3000) return;
  lastPhotoAttempt = now;
  photoDecodePending = true;
  imageCapture.takePhoto().then(function(blob) {
    var img = new Image();
    img.onload = function() {
      var c = document.createElement('canvas');
      c.width = img.width;
      c.height = img.height;
      var ctx = c.getContext('2d', { willReadFrequently: true });
      ctx.drawImage(img, 0, 0);
      var imageData = ctx.getImageData(0, 0, img.width, img.height);
      var gray = new Uint8Array(img.width * img.height);
      var rgba = imageData.data;
      for (var g = 0; g < gray.length; g++) {
        var p = g * 4;
        gray[g] = (19595 * rgba[p] + 38469 * rgba[p+1] + 7472 * rgba[p+2]) >> 16;
      }
      scanLog('High-res photo: ' + img.width + 'x' + img.height);
      zbarWasm.scanGrayBuffer(gray.buffer, img.width, img.height).then(function(symbols) {
        photoDecodePending = false;
        if (symbols.length > 0) {
          scanLog('PHOTO SCAN found ' + symbols.length + ' symbols!');
          processSymbols(symbols);
        }
      }).catch(function(err) {
        photoDecodePending = false;
      });
    };
    img.src = URL.createObjectURL(blob);
  }).catch(function(err) {
    photoDecodePending = false;
  });
}

function processSymbols(symbols) {
  if (!scanning) return;
  if (symbols.length === 0) {
    if (frameCount > 10 && frameCount % 8 === 0) tryHighResPhoto();
    if (scanning) detectorTimer = setTimeout(detectLoop, 200);
    return;
  }
  for (var j = 0; j < symbols.length; j++) {
    var sym = symbols[j];
    var code = sym.decode();
    scanLog('ZBar found: ' + sym.typeName + ' ' + code + ' (frame ' + frameCount + ')');
    code = code.replace(/[^0-9]/g, '');
    if (code.length >= 8 && code.length <= 14) {
      if (code === lastUpc) {
        lastUpcCount++;
      } else {
        lastUpc = code;
        lastUpcCount = 1;
      }
      if (lastUpcCount >= 2) {
        scanLog('Accepted (confirmed): ' + code + ' (' + sym.typeName + ')');
        detectionFlash();
        beep();
        lookupUpc(code);
        return;
      }
    }
  }
  if (scanning) detectorTimer = setTimeout(detectLoop, 200);
}

function stopScanner() {
  if (detectorTimer) { clearTimeout(detectorTimer); detectorTimer = null; }
  if (cameraStream) {
    cameraStream.getTracks().forEach(function(t) { t.stop(); });
    cameraStream = null;
  }
  scanCanvas = null;
  scanCtx = null;
  overlayCanvas = null;
  var r = $('reader');
  while (r.firstChild) r.removeChild(r.firstChild);
}

async function lookupUpc(upc) {
  stopScanner();
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
  if (scanning) setTimeout(function() { startScanner(); }, 300);
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

document.addEventListener('visibilitychange', function() {
  if (document.hidden) { scanning = false; }
  else if (!processingPhoto) { scanning = true; startScanner(); }
});

$('btnPause').addEventListener('click', function() {
  if (scanning) {
    stopScanner();
    scanning = false;
    this.textContent = '\u25B6';
    $('reader').style.opacity = '0';
  } else {
    scanning = true;
    startScanner();
    this.textContent = '\u23F8';
  }
});

// Start scanner on page load or after login
if (currentUser) startScanner();

}
