/* AvianVisitors — bird collage frontend.
 *
 * Renders three views over BirdNET-Pi detections:
 *   collage  — mask-packed cluster of species illustrations, sized by
 *              count. Layout normalises so all birds always fit on
 *              every viewport.
 *   stats    — per-species histogram across the selected time window.
 *   atlas    — alphabet of all detected species with detail modal.
 *
 * Reads four JSON endpoints exposed by the Pi (PHP shims in ../api/):
 *   GET /api/recent.json?hours=N   — species + counts in window
 *   GET /api/lifelist.json         — all species ever detected
 *   GET /api/firstseen.json        — earliest detection per species
 *   GET /api/timeseries.json?days=D — daily counts per species
 *
 * Plus two media proxies (also via PHP):
 *   GET /api/img?sci=X[&com=Y]     — bird illustration
 *   GET /api/recording?sci=X       — most-recent mp3
 *   GET /api/spectrogram?sci=X     — matching spectrogram png
 *
 * Local-network deploys ship without auth. The PHP shims accept the
 * X-AvianVisitors-Token header for the optional Cloudflare-forwarded
 * deployment — see ../forwarding/.
 */
(function () {
  'use strict';

  // ---- Config ----
  // API_BASE: where the JSON endpoints live. Default '/' works when
  // Caddy mounts the frontend at the same host as the API. Override
  // with window.AV_API_BASE if you split origins.
  var API_BASE = (window.AV_API_BASE || '').replace(/\/$/, '');
  var IMG_VERSION = '1';   // bump after running scripts/pregen.py

  function api(p) { return API_BASE + p; }
  function readLS(k, d) { try { return localStorage.getItem(k) || d; } catch (e) { return d; } }
  function writeLS(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function fetchJson(u) { return fetch(u, { cache: 'no-store' }).then(function (r) { return r.json(); }); }

  // ---- State ----
  var DATA = {
    recent: null,     // /api/recent.json?hours=N (refetched on picker change)
    lifelist: null,   // /api/lifelist.json
    firstseen: null,  // /api/firstseen.json
    timeseries: null, // /api/timeseries.json
  };
  var MASKS = null, DIMS = null;
  var currentHours = +readLS('av:window', '24') || 24;

  // ---- Boot: load mask/dim registries, then first data pull ----
  Promise.all([
    fetchJson(api('/avian/masks.json')).then(function (j) { MASKS = j; }),
    fetchJson(api('/avian/dims.json')).then(function (j) { DIMS = j; }),
  ]).then(function () {
    bindUI();
    refreshAll();
    setInterval(refreshRecent, 60000);   // poll for new detections every min
  }).catch(function (e) { console.error('boot failed', e); });

  // ---- UI bindings ----
  var slider = document.getElementById('slider');
  var winPick = document.getElementById('winPick');
  var views = document.querySelectorAll('.view');
  function syncPill(container) {
    var pill = container.querySelector('.seg-pill');
    var active = container.querySelector('button[aria-current="true"]');
    if (!pill || !active) return;
    pill.style.width = active.offsetWidth + 'px';
    pill.style.transform = 'translateX(' + active.offsetLeft + 'px)';
  }
  function syncAllPills() {
    [slider, winPick, document.getElementById('atlasSort')].forEach(function (c) { if (c) syncPill(c); });
  }
  function bindUI() {
    // View slider
    [].slice.call(slider.querySelectorAll('button')).forEach(function (b) {
      b.addEventListener('click', function () {
        [].slice.call(slider.querySelectorAll('button')).forEach(function (x) {
          x.setAttribute('aria-current', x === b ? 'true' : 'false');
        });
        var i = +b.dataset.i;
        views.forEach(function (v) { v.hidden = +v.dataset.view !== i; });
        syncPill(slider);
        if (i === 0) renderCollageFromData();
        if (i === 1) drawHistograms();
        if (i === 2) renderAtlas();
      });
    });
    // Window picker
    [].slice.call(winPick.querySelectorAll('button')).forEach(function (b) {
      b.setAttribute('aria-current', (+b.dataset.h === currentHours) ? 'true' : 'false');
      b.addEventListener('click', function () {
        [].slice.call(winPick.querySelectorAll('button')).forEach(function (x) {
          x.setAttribute('aria-current', x === b ? 'true' : 'false');
        });
        currentHours = +b.dataset.h;
        writeLS('av:window', String(currentHours));
        syncPill(winPick);
        refreshRecent();
      });
    });
    // Atlas sort
    var atlasEl = document.getElementById('atlasSort');
    [].slice.call(atlasEl.querySelectorAll('button')).forEach(function (b) {
      b.addEventListener('click', function () {
        [].slice.call(atlasEl.querySelectorAll('button')).forEach(function (x) {
          x.setAttribute('aria-current', x === b ? 'true' : 'false');
        });
        writeLS('av:atlasSort', b.dataset.sort);
        syncPill(atlasEl);
        renderAtlas();
      });
    });
    // About modal
    document.getElementById('aboutLink').addEventListener('click', function () {
      document.getElementById('about-modal').setAttribute('aria-hidden', 'false');
    });
    document.querySelectorAll('[data-close]').forEach(function (el) {
      el.addEventListener('click', function () {
        document.getElementById('about-modal').setAttribute('aria-hidden', 'true');
      });
    });
    // Resize: re-pack collage and re-pill
    var rT;
    window.addEventListener('resize', function () {
      clearTimeout(rT);
      rT = setTimeout(function () {
        syncAllPills();
        renderCollageFromData();
        drawHistograms();
      }, 120);
    });
    setTimeout(syncAllPills, 60);
  }

  // ---- Data fetch ----
  function refreshAll() {
    var h = currentHours;
    return Promise.all([
      fetchJson(api('/api/lifelist.json')).catch(function () { return null; }),
      fetchJson(api('/api/firstseen.json')).catch(function () { return null; }),
      fetchJson(api('/api/timeseries.json?days=30')).catch(function () { return null; }),
      fetchJson(api('/api/recent.json?hours=' + h)).catch(function () { return null; }),
    ]).then(function (parts) {
      DATA.lifelist = parts[0];
      DATA.firstseen = parts[1];
      DATA.timeseries = parts[2];
      if (parts[3] && h === currentHours) DATA.recent = parts[3];
      renderCollageFromData();
      drawHistograms();
    });
  }
  function refreshRecent() {
    var h = currentHours;
    return fetchJson(api('/api/recent.json?hours=' + h)).then(function (j) {
      if (h !== currentHours) return;
      DATA.recent = j;
      renderCollageFromData();
      drawHistograms();
    }).catch(function () {});
  }

  // ---- Mask + slug helpers ----
  var maskCache = {};
  function loadMask(slug) {
    if (maskCache[slug]) return maskCache[slug];
    var rec = MASKS[slug];
    if (!rec) return null;
    var bytes = atob(rec.bits);
    var w = rec.w, h = rec.h;
    var cells = [];
    for (var y = 0; y < h; y++) {
      for (var x = 0; x < w; x++) {
        var i = y * w + x;
        var b = bytes.charCodeAt(i >> 3);
        if ((b >> (7 - (i & 7))) & 1) cells.push([x, y]);
      }
    }
    return (maskCache[slug] = { w: w, h: h, cells: cells });
  }
  function slugify(sci) {
    return sci.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  }
  function aspect(sci) {
    var d = DIMS[slugify(sci)];
    return d ? d[0] / d[1] : 1.4;
  }

  // ---- Collage layout (mask-aware, viewport-budget) ----
  //
  // Each species ships a low-res alpha mask. We maintain an occupancy
  // grid at viewport resolution; for each tile we spiral outward from
  // the cluster center and pick the closest non-overlapping placement.
  // Total area is normalised so the entire cluster fits any viewport,
  // and we iterate shrink+repack until every bird lands on-screen.
  function tuning(n) {
    return {
      packingBudgetFrac: n <= 4 ? 0.46 : n <= 12 ? 0.40 : n <= 24 ? 0.34 : 0.28,
      countExp: 0.65,
      minTileAreaFrac: n <= 8 ? 0.0100 : n <= 20 ? 0.0075 : 0.0055,
      ellipseAspectBias: 2.1,
    };
  }
  var GRID_STRIDE = 4;

  function maskPack(tiles, W, H, ellipseBias) {
    var GW = Math.ceil(W / GRID_STRIDE) + 2;
    var GH = Math.ceil(H / GRID_STRIDE) + 2;
    var grid = new Uint8Array(GW * GH);

    function cellRange(t, tx, ty, c) {
      var sx = t.fullW / t.mask.w, sy = t.fullH / t.mask.h;
      var x0 = (tx + c[0] * sx) / GRID_STRIDE | 0;
      var y0 = (ty + c[1] * sy) / GRID_STRIDE | 0;
      var x1 = (tx + (c[0] + 1) * sx) / GRID_STRIDE | 0;
      var y1 = (ty + (c[1] + 1) * sy) / GRID_STRIDE | 0;
      if (x0 < 0) x0 = 0; if (y0 < 0) y0 = 0;
      if (x1 >= GW) x1 = GW - 1; if (y1 >= GH) y1 = GH - 1;
      return [x0, y0, x1, y1];
    }
    function collides(t, tx, ty) {
      var cs = t.mask.cells;
      for (var i = 0; i < cs.length; i++) {
        var r = cellRange(t, tx, ty, cs[i]);
        for (var gy = r[1]; gy <= r[3]; gy++) {
          var off = gy * GW;
          for (var gx = r[0]; gx <= r[2]; gx++) if (grid[off + gx]) return true;
        }
      }
      return false;
    }
    function stamp(t, tx, ty) {
      var cs = t.mask.cells;
      for (var i = 0; i < cs.length; i++) {
        var r = cellRange(t, tx, ty, cs[i]);
        for (var gy = r[1]; gy <= r[3]; gy++) {
          var off = gy * GW;
          for (var gx = r[0]; gx <= r[2]; gx++) grid[off + gx] = 1;
        }
      }
    }

    var rand = mulberry32(0x9E3779B1);
    var placed = [];
    var comX = W / 2, comY = H / 2;
    tiles.sort(function (a, b) { return (b.fullW * b.fullH) - (a.fullW * a.fullH); });
    for (var ti = 0; ti < tiles.length; ti++) {
      var t = tiles[ti];
      var bestCost = Infinity, best = null;
      var rings = Math.max(W, H);
      for (var r = 0; r < rings; r += GRID_STRIDE * 2) {
        var step = Math.max(1, r * 0.05);
        var samples = r === 0 ? 1 : Math.max(8, Math.floor(r * 0.6));
        for (var s = 0; s < samples; s++) {
          var ang = (s / samples) * Math.PI * 2;
          var px = comX + Math.cos(ang) * r * ellipseBias - t.fullW / 2;
          var py = comY + Math.sin(ang) * r - t.fullH / 2;
          if (collides(t, px, py)) continue;
          var dxx = (px + t.fullW / 2 - comX);
          var dyy = (py + t.fullH / 2 - comY);
          var cost = Math.hypot(dxx / ellipseBias, dyy) + rand() * step * 0.5;
          if (cost < bestCost) { bestCost = cost; best = { x: px, y: py }; }
        }
        if (best && r > 0) break;
      }
      if (best) { t.x = best.x; t.y = best.y; stamp(t, best.x, best.y); placed.push(t); }
      else { t.x = -99999; t.y = -99999; placed.push(t); }
    }
    return placed;
  }
  function mulberry32(a) {
    return function () {
      var t = a += 0x6D2B79F5;
      t = Math.imul(t ^ (t >>> 15), t | 1);
      t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  function renderCollageFromData() {
    var items = (DATA.recent && DATA.recent.species) || [];
    renderCollage(items);
  }
  function renderCollage(items) {
    var collage = document.getElementById('collage');
    collage.innerHTML = '';
    if (!items.length) {
      collage.innerHTML = '<p class="empty">no birds heard in this window.</p>';
      return;
    }
    var W = collage.clientWidth, H = collage.clientHeight;
    if (!W || !H) { setTimeout(function () { renderCollage(items); }, 80); return; }

    var T = tuning(items.length);
    var vpArea = W * H;
    var budget = vpArea * T.packingBudgetFrac;
    var minArea = vpArea * T.minTileAreaFrac;

    var tiles = items.map(function (s) {
      var slug = slugify(s.sci);
      var mask = loadMask(slug);
      if (!mask) return null;
      var n = +s.n; if (!n || isNaN(n)) n = 1;
      return { mask: mask, data: s, ar: aspect(s.sci), score: Math.pow(Math.max(1, n), T.countExp) };
    }).filter(Boolean);

    var sumScore = tiles.reduce(function (a, t) { return a + t.score; }, 0) || 1;
    tiles.forEach(function (t) { t.area = Math.max(minArea, budget * t.score / sumScore); });
    var sumA = tiles.reduce(function (a, t) { return a + t.area; }, 0);
    if (sumA > budget) {
      var fixedSum = tiles.filter(function (t) { return t.area <= minArea + 1e-9; })
        .reduce(function (a, t) { return a + t.area; }, 0);
      var flexSum = sumA - fixedSum;
      var flexBudget = Math.max(0, budget - fixedSum);
      var shrink = flexSum > 0 ? Math.min(1, flexBudget / flexSum) : 1;
      tiles.forEach(function (t) { if (t.area > minArea + 1e-9) t.area *= shrink; });
    }
    tiles.forEach(function (t) {
      t.fullW = Math.sqrt(t.area * t.ar);
      t.fullH = t.fullW / t.ar;
    });

    var placed = maskPack(tiles, W, H, T.ellipseAspectBias);
    function bounds(a) {
      var L = Infinity, R = -Infinity, T2 = Infinity, B = -Infinity;
      a.forEach(function (t) {
        if (t.x < -1000) return;
        if (t.x < L) L = t.x;
        if (t.x + t.fullW > R) R = t.x + t.fullW;
        if (t.y < T2) T2 = t.y;
        if (t.y + t.fullH > B) B = t.y + t.fullH;
      });
      return { L: L, R: R, T: T2, B: B };
    }
    var b = bounds(placed);
    for (var it = 0; it < 10; it++) {
      var miss = placed.some(function (t) { return t.x < -1000; });
      var over = b.L < 0 || b.T < 0 || b.R > W || b.B > H;
      if (!miss && !over) break;
      var scale = 0.93;
      if (over) {
        var clW = b.R - b.L, clH = b.B - b.T;
        var sx = (W * 0.96) / Math.max(clW, W * 0.96);
        var sy = (H * 0.94) / Math.max(clH, H * 0.94);
        scale = Math.min(scale, sx, sy);
      }
      tiles.forEach(function (t) { t.fullW *= scale; t.fullH *= scale; });
      placed = maskPack(tiles, W, H, T.ellipseAspectBias);
      b = bounds(placed);
    }
    var dx = W / 2 - (b.L + b.R) / 2, dy = H / 2 - (b.T + b.B) / 2;
    if (Math.abs(dx) > 1 || Math.abs(dy) > 1) {
      placed.forEach(function (t) { if (t.x > -1000) { t.x += dx; t.y += dy; } });
    }

    placed.forEach(function (t) {
      var s = t.data;
      var img = api('/api/img?sci=' + encodeURIComponent(s.sci) +
                    (s.com ? '&com=' + encodeURIComponent(s.com) : '') +
                    '&v=' + IMG_VERSION);
      var btn = document.createElement('button');
      btn.className = 'gtile';
      btn.type = 'button';
      btn.setAttribute('data-sci', s.sci);
      btn.setAttribute('aria-label', s.com || s.sci);
      btn.title = (s.com || s.sci) + ' · ' + (+s.n || 0) + ' calls';
      btn.style.left = t.x + 'px';
      btn.style.top = t.y + 'px';
      btn.style.width = t.fullW + 'px';
      btn.style.height = t.fullH + 'px';
      btn.innerHTML = '<img loading="lazy" decoding="async" src="' + img + '" alt="' + (s.com || s.sci) + '">';
      btn.addEventListener('click', function () { openDetail(s); });
      collage.appendChild(btn);
    });
  }

  // ---- Stats: per-species histogram with last-seen positioning ----
  function drawHistograms() {
    var tl = document.getElementById('statsTimeline');
    if (!tl) return;
    var sp = ((DATA.recent && DATA.recent.species) || []).slice();
    if (!sp.length) { tl.innerHTML = '<div class="stats-tl-empty">no detections in this window</div>'; return; }
    var now = Date.now();
    var windowStart = currentHours >= 1000000 ? now - 90 * 24 * 3600000 : now - currentHours * 3600000;
    var windowSpan = Math.max(1, now - windowStart);
    sp.sort(function (a, b) { return (+b.n || 0) - (+a.n || 0); });
    var W = tl.clientWidth || window.innerWidth;
    var cap = Math.max(4, Math.floor(W / 28));
    if (sp.length > cap) sp = sp.slice(0, cap);
    var maxN = sp.reduce(function (m, s) { return Math.max(m, +s.n || 0); }, 1);
    var html = '<div class="stats-tl-plot">';
    sp.forEach(function (s) {
      var ts = Date.parse((s.last_seen || '').replace(' ', 'T'));
      var leftPct = isNaN(ts) ? 50 : ((Math.max(windowStart, Math.min(now, ts)) - windowStart) / windowSpan) * 100;
      var n = +s.n || 0;
      var bottomPct = (n / maxN) * 50;
      html += '<div class="stats-tl-mark" style="left:' + leftPct.toFixed(1) + '%;bottom:' + bottomPct.toFixed(1) + '%" data-sci="' + s.sci + '" title="' + (s.com || s.sci) + ' · ' + n + ' calls"></div>';
    });
    html += '</div>';
    tl.innerHTML = html;
  }

  // ---- Atlas: gridded alphabet of all species ----
  function renderAtlas() {
    var list = document.getElementById('atlasList');
    if (!list) return;
    var sp = ((DATA.lifelist && DATA.lifelist.species) || []).slice();
    if (!sp.length) { list.innerHTML = '<p class="empty">no species yet — atlas fills in as the Pi detects birds.</p>'; return; }
    var sort = readLS('av:atlasSort', 'count');
    if (sort === 'count') sp.sort(function (a, b) { return (+b.n || 0) - (+a.n || 0); });
    else if (sort === 'recent') sp.sort(function (a, b) {
      return Date.parse((b.last_seen || '').replace(' ', 'T')) - Date.parse((a.last_seen || '').replace(' ', 'T'));
    });
    else sp.sort(function (a, b) {
      return Date.parse((a.first_seen || '').replace(' ', 'T')) - Date.parse((b.first_seen || '').replace(' ', 'T'));
    });
    list.innerHTML = sp.map(function (s) {
      var img = api('/api/img?sci=' + encodeURIComponent(s.sci) +
                    (s.com ? '&com=' + encodeURIComponent(s.com) : '') +
                    '&v=' + IMG_VERSION);
      return '<button class="atlas-card" data-sci="' + s.sci + '">' +
        '<img loading="lazy" decoding="async" src="' + img + '" alt="' + (s.com || s.sci) + '">' +
        '<span class="atlas-name">' + (s.com || s.sci) + '</span>' +
        '<span class="atlas-count">' + (+s.n || 0) + '</span>' +
        '</button>';
    }).join('');
    [].slice.call(list.querySelectorAll('.atlas-card')).forEach(function (b) {
      b.addEventListener('click', function () { openDetail({ sci: b.dataset.sci }); });
    });
  }

  // ---- Detail modal (basic — opens recording + spectrogram for a species) ----
  function openDetail(s) {
    var sci = s.sci || s;
    // Lookup full record from lifelist if available
    var rec = ((DATA.lifelist && DATA.lifelist.species) || []).find(function (x) { return x.sci === sci; }) || s;
    var img = api('/api/img?sci=' + encodeURIComponent(sci) +
                  (rec.com ? '&com=' + encodeURIComponent(rec.com) : '') +
                  '&v=' + IMG_VERSION);
    var rec_url = api('/api/recording?sci=' + encodeURIComponent(sci));
    var spec_url = api('/api/spectrogram?sci=' + encodeURIComponent(sci));
    var modal = document.getElementById('detail-modal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'detail-modal';
      modal.setAttribute('role', 'dialog');
      document.body.appendChild(modal);
    }
    modal.innerHTML =
      '<div class="modal-backdrop" data-close="1"></div>' +
      '<div class="detail-card">' +
      '  <button type="button" class="modal-close" data-close="1" aria-label="close">×</button>' +
      '  <img class="detail-img" src="' + img + '" alt="' + (rec.com || sci) + '">' +
      '  <h2 class="detail-title">' + (rec.com || sci) + '</h2>' +
      '  <p class="detail-sci"><em>' + sci + '</em></p>' +
      '  <p class="detail-stats">' + (+rec.n || 0) + ' calls · last heard ' + (rec.last_seen || '—') + '</p>' +
      '  <audio class="detail-audio" controls src="' + rec_url + '"></audio>' +
      '  <img class="detail-spec" src="' + spec_url + '" alt="spectrogram">' +
      '</div>';
    modal.setAttribute('aria-hidden', 'false');
    modal.querySelectorAll('[data-close]').forEach(function (el) {
      el.addEventListener('click', function () { modal.setAttribute('aria-hidden', 'true'); });
    });
  }
})();
