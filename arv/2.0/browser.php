<?php
/*
  Voice search for the phone-cam viewer.

  Make a "telescope" with one hand (thumb + index form a ring, the other fingers curled) and hold it to your eye:
  a floating Google search window opens and starts listening. Say what you want, it is turned into text and searched.

  This ONE file does two jobs so viewer.php can stay small:
    browser.php?js=1     -> the loader script (telescope detection + the floating window). viewer.php includes it.
    browser.php?embed=1  -> the page that lives inside the floating window (search bar, mic, results, hand buttons).

  SETUP
  1. Optional but recommended: results INSIDE the window.
     Google does not allow google.com to be shown inside another page, so the reliable way to show results in the
     window is a free Programmable Search Engine:
       programmablesearchengine.google.com -> Add -> "Search the entire web" -> Create -> copy the "Search engine ID".
     Paste it into GOOGLE_CSE_ID below. Results AND the pages you open from them stay inside this window (hand-scrollable).
     Without it, the window tries google.com/search?igu=1 in a frame: it can work, but the hands cannot scroll inside it.
  2. Speech recognition needs Chrome or Edge (Firefox has no speech recognition) and an https:// page (or localhost).
*/
const GOOGLE_CSE_ID = '';     // e.g. '0123456789abcdef0'  ('' = try google.com/search?igu=1 inside the window instead)
const SPEECH_LANG   = '';     // e.g. 'en-PH' or 'fil-PH'  ('' = your browser's language)
const USE_PHONE_MIC = true;   // try to listen through the phone's mic (only newer Chrome versions can); otherwise the computer's mic is used

if (isset($_GET['js'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store');
?>
(() => {
'use strict';
// ====== tunables ======
const SCOPE_HOLD = 600;          // ms the telescope has to be held at your eye
const SCOPE_CURL = 1.45;         // a finger counts as curled when (tip to wrist) < this x (knuckle to wrist). Raise if the pose is not recognised
const SCOPE_RING = 0.55;         // thumb tip to index tip distance (relative to hand size) that counts as a closed ring
const SCOPE_EYE = 1.3;           // the ring must be within this many "eye gaps" of one of your eyes
const SCOPE_STILL = 0.35;        // max hand speed (px per ms) while holding
const SCOPE_FALLBACK_Y = 0.5;    // without face detection: the hand just has to be in the top half of the screen
const SCOPE_FALLBACK_MS = 1600;  // face model loaded but no face found (hand covering it): fall back to the top-half rule after this long
const FACE_EVERY = 250;
const THUMB_HOLD = 250;          // ms a thumbs up / down has to be held before the window scrolls          // ms between face checks (only runs while the pose is held, so it costs nothing otherwise)

const d2 = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
const ld = k => { try { return localStorage.getItem(k); } catch (e) { return null; } };
const sv = (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} };
const note = t => { try { if (typeof toast === 'function') toast(t); } catch (e) {} };

let detectOn = ld('pc_scope') !== '0';
let win = null, frame = null, barEl = null, xEl = null, mEl = null, rzEl = null;
let isOpen = false, hd = null, md = null, sentSome = false, xPrev = false, mPrev = false, placed = false;
const W = { x: 0, y: 0, w: 720, h: 540 };
const ps = { since: 0, eyeSince: 0, c: null, t: 0, v: 0, absent: 0, need: false, lock: 0 };
let faceDet = null, faceBusy = false, faceAt = 0, face = null;
const th = { cand: 0, since: 0, dir: 0, last: 0 };
const SRC = new URL('browser.php?embed=1', location.href).href;

// ====== floating window ======
const scopeStyle = document.createElement('style');
scopeStyle.textContent = `
  #scopeWin{position:fixed;z-index:7;display:none;flex-direction:column;box-sizing:border-box;border:1.5px solid #2A3758;border-radius:18px;background:#0B1020;box-shadow:0 10px 30px rgba(2,6,23,.6);overflow:hidden;font-family:Inter,system-ui,sans-serif}
  #scopeWin.open{display:flex}
  #scopeWin .sbar{flex:none;display:flex;align-items:center;justify-content:space-between;padding:4px 6px 4px 12px;background:#121A2E;border-bottom:1px solid #2A3758;cursor:grab;touch-action:none;user-select:none;color:#E8ECF8;font:600 13px Inter,system-ui,sans-serif}
  #scopeWin .sbar b{display:inline-flex;align-items:center;gap:8px;font-weight:600}
  #scopeWin .sbar svg{width:16px;height:16px;color:#93C5FD}
  #scopeWin .sbtn{display:flex;gap:4px}
  #scopeWin .sbtn button{width:36px;height:30px;min-height:0;padding:0;border-radius:8px;background:#1F2B49;color:#E8ECF8;border:1px solid #2A3758;display:inline-flex;align-items:center;justify-content:center;font-size:16px;line-height:1;cursor:pointer}
  #scopeWin .sbtn button:hover{background:#29395F}
  #scopeWin .sbtn button:focus-visible{outline:2px solid #93C5FD;outline-offset:1px}
  #scopeWin iframe{flex:1;min-height:0;width:100%;border:0;background:transparent}
  #scopeWin.dragging iframe{pointer-events:none}
  #scopeWin .srz{position:absolute;right:0;bottom:0;width:26px;height:26px;z-index:2;cursor:nwse-resize;touch-action:none;
    background:linear-gradient(135deg,transparent 52%,rgba(255,255,255,.6) 52%,rgba(255,255,255,.6) 60%,transparent 60%,transparent 72%,rgba(255,255,255,.6) 72%,rgba(255,255,255,.6) 80%,transparent 80%)}
  .bubble.voice{background:linear-gradient(135deg,#059669,#0891B2)}
`;
document.head.appendChild(scopeStyle);

function build() {
  if (win) return;
  win = document.createElement('div');
  win.id = 'scopeWin';
  win.innerHTML = '<div class="sbar"><b><svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.13-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.13a.5.5 0 0 1 .96 0L14.06 8.5a2 2 0 0 0 1.44 1.44l6.13 1.58a.5.5 0 0 1 0 .96l-6.13 1.58a2 2 0 0 0-1.44 1.44l-1.58 6.13a.5.5 0 0 1-.96 0z"/></svg> Voice assistant</b><span class="sbtn"><button type="button" class="sm" title="Minimize (frame sign with both hands, or B)" aria-label="Minimize"><svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="M5 12h14"/></svg></button><button type="button" class="sx" title="Close (Esc)" aria-label="Close"><svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="M18 6 6 18M6 6l12 12"/></svg></button></span></div>' +
    '<iframe allow="microphone; autoplay" title="Voice assistant"></iframe><div class="srz" title="Drag to resize"></div>';
  document.body.appendChild(win);
  frame = win.querySelector('iframe'); barEl = win.querySelector('.sbar'); xEl = win.querySelector('.sx'); mEl = win.querySelector('.sm'); rzEl = win.querySelector('.srz');
  mEl.addEventListener('click', e => { e.stopPropagation(); minimizeWin(); });

  xEl.addEventListener('click', e => { e.stopPropagation(); closeWin(); });
  // mouse / touch: drag by the title bar, resize by the corner
  barEl.addEventListener('pointerdown', e => {
    if (e.target === xEl || e.target === mEl) return;
    md = { mode: 'move', dx: e.clientX - W.x, dy: e.clientY - W.y };
    barEl.setPointerCapture(e.pointerId); win.classList.add('dragging'); e.stopPropagation();
  });
  rzEl.addEventListener('pointerdown', e => {
    md = { mode: 'size', sx: e.clientX, sy: e.clientY, w: W.w, h: W.h };
    rzEl.setPointerCapture(e.pointerId); win.classList.add('dragging'); e.stopPropagation();
  });
  const mv = e => {
    if (!md) return;
    if (md.mode === 'move') { W.x = e.clientX - md.dx; W.y = e.clientY - md.dy; }
    else { W.w = md.w + e.clientX - md.sx; W.h = md.h + e.clientY - md.sy; }
    place();
  };
  const up = () => { md = null; if (!hd) win.classList.remove('dragging'); };
  [barEl, rzEl].forEach(el => { el.addEventListener('pointermove', mv); el.addEventListener('pointerup', up); el.addEventListener('pointercancel', up); });
  addEventListener('resize', () => { if (win) place(); });
}

function place() {
  W.w = Math.max(300, Math.min(innerWidth, W.w));
  W.h = Math.max(240, Math.min(innerHeight, W.h));
  W.x = Math.max(0, Math.min(innerWidth - W.w, W.x));
  W.y = Math.max(0, Math.min(innerHeight - W.h, W.y));
  const s = win.style;
  s.left = W.x + 'px'; s.top = W.y + 'px'; s.width = W.w + 'px'; s.height = W.h + 'px';
}

function showWin(listen) {
  build(); place();
  win.classList.add('open'); isOpen = true;
  if (listen) {
    if (!frame.getAttribute('src')) frame.src = SRC;            // first time: the page announces itself with "ready", then starts listening
    else frame.contentWindow.postMessage({ pc: 'listen' }, location.origin);
  }
}
function openWin() {
  build();
  const vb = bubs.voice;
  if (vb && vb.on) { vb.on = false; vb.hdrag = vb.mdrag = null; vb.el.style.display = 'none'; vb.el.classList.remove('drag'); bubLayout(); }   // it was a circle: bring the window back
  if (!placed) {
    W.w = Math.min(440, innerWidth * 0.9); W.h = Math.min(330, innerHeight * 0.6);
    W.x = (innerWidth - W.w) / 2; W.y = innerHeight * 0.1; placed = true;
  }
  showWin(true);
  note('Listening. Say what you want to look up');
}
function hideWin() {
  try { frame.contentWindow.postMessage({ pc: 'stop' }, location.origin); } catch (e) {}
  win.classList.remove('open', 'dragging');
  isOpen = false; hd = null; md = null; sentSome = false; xPrev = false; mPrev = false;
  ps.lock = performance.now() + 1500; ps.need = true; ps.since = ps.eyeSince = 0;
}
function closeWin() { if (win && isOpen) hideWin(); }
function minimizeWin() { if (isOpen && typeof bubCollapse === 'function') bubCollapse('voice'); }

// join the viewer's minimize-to-circle system (frame sign with both hands = minimize, reverse clap = open, B key)
window.scopeIsOpen = () => isOpen;
if (typeof bubs !== 'undefined' && typeof bubMake === 'function') {
  bubs.voice = {
    id: 'voice', icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>', title: 'Voice search: click to open (or reverse-clap)', on: false, side: 't', order: 0, at: 0, x: 0, y: 0, hdrag: null, mdrag: null, el: null,
    ext: {
      collapse() { if (!isOpen) return null; const c = { x: W.x + W.w / 2, y: W.y + W.h / 2 }; hideWin(); return c; },
      open(rect) {
        if (rect) { W.w = Math.max(300, rect.w); W.h = Math.max(240, rect.h); W.x = rect.x + rect.w / 2 - W.w / 2; W.y = rect.y + rect.h / 2 - W.h / 2; placed = true; }
        showWin(false);
      },
      el() { build(); return win; },
    },
  };
  bubMake(bubs.voice);
}

addEventListener('message', e => {
  if (e.origin !== location.origin) return;
  const m = e.data;
  if (!m || !m.pc) return;
  if (m.pc === 'ready' && isOpen) frame.contentWindow.postMessage({ pc: 'listen' }, location.origin);
  else if (m.pc === 'close') closeWin();
});

// hands over the window: forward them to the page inside, and let a pinch move / resize / close the window
function windowInteract(hs, now) {
  const inR = (p, q, m) => p.x >= q.left - m && p.x <= q.right + m && p.y >= q.top - m && p.y <= q.bottom + m;
  const r = win.getBoundingClientRect(), ir = frame.getBoundingClientRect();

  const out = [];
  hs.forEach(h => {
    if (inR(h.tip, ir, 0) || (h.pinching && inR(h.pp, ir, 0)))
      out.push({ x: h.tip.x - ir.left, y: h.tip.y - ir.top, px: h.pp.x - ir.left, py: h.pp.y - ir.top, pinch: !!h.pinching });
  });
  const tdir = thumbStep(hs, now);
  if (out.length || sentSome || tdir || th.last) {
    try { frame.contentWindow.postMessage({ pc: 'hand', hands: out, thumb: tdir }, location.origin); } catch (e) {}
    sentSome = out.length > 0; th.last = tdir;
  }

  const pinchers = hs.filter(h => h.pinching);
  if (hd || pinchers.some(h => inR(h.pp, r, 10))) { try { lastHeld = { id: 'voice', t: now }; } catch (e) {} }   // so the frame sign minimizes THIS tool
  const mr = mEl.getBoundingClientRect();
  const onM = pinchers.some(h => inR(h.pp, mr, 10));
  if (onM && !mPrev) { mPrev = true; minimizeWin(); return; }
  mPrev = onM;
  const xr = xEl.getBoundingClientRect();
  const onX = pinchers.some(h => inR(h.pp, xr, 10));
  if (onX && !xPrev) { xPrev = true; closeWin(); return; }
  xPrev = onX;

  if (hd) {
    let best = null, bd = 450;
    pinchers.forEach(h => { const k = d2(h.pp, hd.last); if (k < bd) { bd = k; best = h; } });
    if (best) {
      hd.last = best.pp; hd.lost = 0;
      if (hd.mode === 'move') { W.x = best.pp.x - hd.dx; W.y = best.pp.y - hd.dy; }
      else { W.w = hd.w + best.pp.x - hd.sx; W.h = hd.h + best.pp.y - hd.sy; }
      place(); win.classList.add('dragging');
    } else {
      if (!hd.lost) hd.lost = now;
      if (now - hd.lost > 180) { hd = null; if (!md) win.classList.remove('dragging'); }
    }
  } else if (!md) {
    const bar = barEl.getBoundingClientRect(), rz = rzEl.getBoundingClientRect();
    const rh = pinchers.find(h => inR(h.pp, rz, 14));
    const mh = !rh && pinchers.find(h => inR(h.pp, bar, 0));
    if (rh) hd = { mode: 'size', sx: rh.pp.x, sy: rh.pp.y, w: W.w, h: W.h, last: rh.pp, lost: 0 };
    else if (mh) hd = { mode: 'move', dx: mh.pp.x - W.x, dy: mh.pp.y - W.y, last: mh.pp, lost: 0 };
  }

  // a pinch on the window must not grab the boxes / circles underneath it
  hs.forEach(h => { if (h.pinching && (inR(h.pp, r, 10) || (hd && d2(h.pp, hd.last) < 160))) h.pinching = false; });
}

// ====== thumbs up / down (while the window is open) ======
// fist with the thumb sticking out and pointing up (-1: scroll up) or down (+1: scroll down)
function thumbOf(h) {
  const p = h.pts, size = (d2(p[0], p[9]) + d2(p[5], p[17])) / 2 || 1;
  if (size < 25) return 0;
  const curled = [[8, 5], [12, 9], [16, 13], [20, 17]].every(([t, m]) => d2(p[t], p[0]) < SCOPE_CURL * d2(p[m], p[0]));
  if (!curled) return 0;
  if (d2(p[4], p[5]) < 0.8 * size) return 0;                   // thumb has to stick out, not lie against the fist
  const dx = p[4].x - p[2].x, dy = p[4].y - p[2].y;
  if (Math.hypot(dx, dy) < 0.6 * size || Math.abs(dy) < 1.2 * Math.abs(dx)) return 0;   // and point (mostly) straight up or down
  return dy < 0 ? -1 : 1;
}
function thumbStep(hs, now) {
  let dir = 0;
  for (const h of hs) { const t = thumbOf(h); if (t) { dir = t; break; } }
  if (dir !== th.cand) { th.cand = dir; th.since = now; }
  th.dir = dir && now - th.since > THUMB_HOLD ? dir : 0;
  return th.dir;
}

// ====== telescope detection ======
// thumb tip touching the index tip (a ring), the other three fingers curled, index bent round into the ring
function pose(h) {
  const p = h.pts, size = (d2(p[0], p[9]) + d2(p[5], p[17])) / 2 || 1;
  if (size < 25) return null;
  const curled = [[12, 9], [16, 13], [20, 17]].every(([t, m]) => d2(p[t], p[0]) < SCOPE_CURL * d2(p[m], p[0]));
  if (!curled) return null;
  if (d2(p[4], p[8]) / size > SCOPE_RING) return null;
  if (d2(p[8], p[0]) > 1.7 * d2(p[5], p[0])) return null;      // index must be bent into the ring, not pointing
  const c = { x: (p[3].x + p[4].x + p[7].x + p[8].x) / 4, y: (p[3].y + p[4].y + p[7].y + p[8].y) / 4 };
  return { c, size };
}

// same frame -> screen mapping the hand tracker uses
function frameMap() {
  const fw = off.width, fh = off.height, W_ = innerWidth, H_ = innerHeight;
  const fill = video.classList.contains('fill');
  const s = fill ? Math.max(W_ / fw, H_ / fh) : Math.min(W_ / fw, H_ / fh);
  const cw = fw * s, ch = fh * s, ox = (W_ - cw) / 2, oy = (H_ - ch) / 2;
  return p => ({ x: ox + p.x * cw, y: oy + p.y * ch });
}

// face detection: loaded in the background, and only asked while a telescope pose is being held, to find where your eyes are
function loadFace() {
  const init = () => {
    try {
      const fd = new FaceDetection({ locateFile: f => 'https://cdn.jsdelivr.net/npm/@mediapipe/face_detection@0.4.1646425229/' + f });
      fd.setOptions({ model: 'short', minDetectionConfidence: 0.4 });
      fd.onResults(r => {
        faceBusy = false;
        const d = r.detections && r.detections[0], lm = d && (d.landmarks || d.keypoints);
        if (lm && lm.length >= 2) { const m = frameMap(); face = { t: performance.now(), eyes: [m(lm[0]), m(lm[1])] }; }
        else face = null;
      });
      fd.initialize().then(() => { faceDet = fd; }).catch(() => {});
    } catch (e) {}
  };
  if (window.FaceDetection) return init();
  const s = document.createElement('script');
  s.src = 'https://cdn.jsdelivr.net/npm/@mediapipe/face_detection@0.4.1646425229/face_detection.js';
  s.crossOrigin = 'anonymous'; s.onload = init;
  document.head.appendChild(s);
}
setTimeout(loadFace, 5000);

function askFace(now) {
  if (!faceDet) return;
  if (faceBusy && now - faceAt < 1500) return;
  if (now - faceAt < FACE_EVERY) return;
  faceBusy = true; faceAt = now;
  faceDet.send({ image: off }).catch(() => { faceBusy = false; });
}

function step(hs, now) {
  if (isOpen) { windowInteract(hs, now); ps.since = ps.eyeSince = 0; return; }
  if (!detectOn) { ps.since = ps.eyeSince = 0; return; }

  const busy = grab || mouseDrag || ytBusy() || Object.values(bubs).some(b => b.hdrag || b.mdrag);
  let cand = null;
  if (!busy) {
    for (const h of hs) {
      if (typeof bubHeld === 'function' && bubHeld(h)) continue;
      const p = pose(h);
      if (p) { cand = { h, p }; break; }
    }
  }
  if (!cand) {
    ps.since = ps.eyeSince = 0; ps.c = null;
    if (!ps.absent) ps.absent = now;
    if (now - ps.absent > 500) ps.need = false;               // the pose has to be dropped once before it can trigger again
    return;
  }
  ps.absent = 0;
  if (ps.need || now < ps.lock) return;

  const c = cand.p.c;
  if (!ps.since || !ps.c) { ps.since = now; ps.v = 0; }
  else {
    const dt = Math.max(1, now - ps.t);
    ps.v = ps.v * 0.7 + (d2(c, ps.c) / dt) * 0.3;
    if (ps.v > SCOPE_STILL) { ps.since = now; ps.eyeSince = 0; }
  }
  ps.c = c; ps.t = now;
  const held = now - ps.since;
  if (held > 200) cand.h.pinching = false;                    // a telescope ring is not a pinch: never grabs boxes

  askFace(now);
  const f = face && now - face.t < 700 ? face : null;
  let eyeOk;
  if (f) eyeOk = Math.min(d2(c, f.eyes[0]), d2(c, f.eyes[1])) < SCOPE_EYE * d2(f.eyes[0], f.eyes[1]);
  else eyeOk = c.y < innerHeight * SCOPE_FALLBACK_Y && (!faceDet || held > SCOPE_FALLBACK_MS);
  if (eyeOk) { if (!ps.eyeSince) ps.eyeSince = now; } else ps.eyeSince = 0;

  const prog = ps.eyeSince ? Math.min(1, (now - ps.eyeSince) / SCOPE_HOLD) : 0;
  if (held > 150) {
    octx.save();
    octx.lineWidth = 5; octx.lineCap = 'round';
    octx.strokeStyle = eyeOk ? '#3ecf8e' : 'rgba(255,255,255,.35)';
    octx.beginPath();
    octx.arc(c.x, c.y, Math.max(30, cand.p.size * 0.9), -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * (eyeOk ? Math.max(prog, 0.02) : 1));
    octx.stroke();
    octx.restore();
  }
  if (prog >= 1) { openWin(); ps.need = true; ps.since = ps.eyeSince = 0; }
}

// called by viewer.php on every hand-tracking frame
window.scopeStep = (hs, now) => { try { step(hs, now); } catch (e) { console.warn('voice search', e); } };

// ====== buttons + keys ======
(function addUi() {
  const bar = document.getElementById('bar');
  if (!bar) return;
  const b1 = document.createElement('button');
  b1.className = 'ghost'; b1.textContent = 'Voice assistant (S)';
  b1.onclick = e => { e.stopPropagation(); isOpen ? closeWin() : openWin(); };
  const b2 = document.createElement('button');
  b2.className = 'ghost';
  const lab = () => { b2.textContent = 'Telescope: ' + (detectOn ? 'on' : 'off') + ' (V)'; };
  b2.onclick = e => { e.stopPropagation(); detectOn = !detectOn; sv('pc_scope', detectOn ? '1' : '0'); lab(); };
  lab(); bar.append(b1, b2);
  window.__scopeToggleDetect = () => b2.click();
})();
document.addEventListener('keydown', e => {
  if (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
  const k = e.key.toLowerCase();
  if (k === 's') { isOpen ? closeWin() : openWin(); }
  else if (k === 'v') { window.__scopeToggleDetect && window.__scopeToggleDetect(); }
  else if (k === 'escape' && isOpen) closeWin();
});
})();
<?php
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Voice assistant</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { color-scheme: dark; --bg:#0B1020; --surface:#121A2E; --surface-3:#1F2B49; --border:#2A3758; --text:#E8ECF8; --muted:#A3AFCC; --primary:#2563EB; --primary-hi:#3B7BFF; --ring:#93C5FD; --ok:#34D399; --danger:#F87171; }
  html, body { margin: 0; height: 100%; background: var(--bg); color: var(--text); font: 14px/1.4 Inter, system-ui, sans-serif; overflow: hidden; }
  body { display: flex; flex-direction: column; }
  svg { width: 1em; height: 1em; flex: none; }
  .top { flex: none; padding: 10px 10px 2px; }
  .row { display: flex; align-items: center; gap: 10px; }
  .avatar { flex: none; width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: 18px; background: linear-gradient(135deg,#2563EB,#0891B2); }
  form { flex: 1; min-width: 0; display: flex; align-items: center; gap: 6px; background: var(--surface); border: 1px solid var(--border); border-radius: 999px; padding: 3px 4px 3px 16px; }
  form:focus-within { border-color: var(--primary-hi); }
  #q { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; color: var(--text); font: 15px Inter, system-ui, sans-serif; padding: 7px 2px; }
  #q::placeholder { color: var(--muted); }
  #st { margin: 4px 6px 0; min-height: 16px; font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  #res { flex: 1; min-height: 0; overflow: auto; background: #fff; color: #202124; margin: 4px 10px 0; border-radius: 12px; }
  #card { padding: 22px 16px; text-align: center; color: #5f6368; font-size: 13px; }
  #card b { color: #202124; font-size: 16px; display: block; margin-bottom: 6px; word-break: break-word; }
  #cse { display: none; }
  #gf { display: none; width: 100%; height: 4000px; border: 0; background: #fff; }   /* tall on purpose: this window scrolls, so thumbs / pinch-drag can scroll it */
  .rip { position: fixed; z-index: 49; width: 30px; height: 30px; margin: -15px 0 0 -15px; border-radius: 50%; border: 3px solid var(--ok); pointer-events: none; animation: rip .45s ease-out forwards; }
  @keyframes rip { from { scale: .3; opacity: 1; } to { scale: 1.6; opacity: 0; } }
  .tools { flex: none; display: flex; gap: 6px; padding: 8px 10px 10px; flex-wrap: wrap; justify-content: center; }

  .hb { position: relative; overflow: hidden; min-width: 40px; height: 36px; padding: 0 12px; border-radius: 12px; font: inherit; font-weight: 500; font-size: 13px; color: var(--text); cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; background: var(--surface-3); border: 1px solid var(--border); transition: background .15s; }
  .hb:hover { background: #29395F; }
  .hb:focus-visible { outline: 2px solid var(--ring); outline-offset: 2px; }
  .hb::before { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: calc(var(--p, 0) * 100%); background: rgba(52,211,153,.55); }
  .hb > span { position: relative; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
  .hb svg { font-size: 16px; }
  #go { background: var(--primary); border-color: var(--primary); color: #fff; border-radius: 999px; height: 34px; }
  #go:hover { background: var(--primary-hi); }
  #mic { width: 38px; height: 38px; min-width: 0; padding: 0; border-radius: 50%; }
  #mic svg { font-size: 18px; }
  #mic.live { background: #DC2626; border-color: #FCA5A5; color: #fff; animation: pulse 1.1s infinite; }
  @keyframes pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(220,38,38,.6); } 50% { box-shadow: 0 0 0 9px rgba(220,38,38,0); } }

  .cur { position: fixed; z-index: 50; width: 18px; height: 18px; margin: -9px 0 0 -9px; border-radius: 50%; border: 3px solid #fff; background: rgba(59,123,255,.6);
    pointer-events: none; display: none; }
  .cur.pinch { background: rgba(52,211,153,.9); border-color: var(--ok); scale: .8; }
  #toast { position: fixed; left: 50%; bottom: 56px; transform: translateX(-50%); z-index: 60; background: rgba(18,26,46,.97); border: 1px solid var(--border); color: var(--text); padding: 7px 14px; border-radius: 999px; font-size: 12px;
    opacity: 0; transition: opacity .25s; pointer-events: none; max-width: 90%; text-align: center; }
  #toast.show { opacity: 1; }
  @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
</style>
</head>
<body>
<div class="top">
  <div class="row">
  <div class="avatar"><svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.13-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.13a.5.5 0 0 1 .96 0L14.06 8.5a2 2 0 0 0 1.44 1.44l6.13 1.58a.5.5 0 0 1 0 .96l-6.13 1.58a2 2 0 0 0-1.44 1.44l-1.58 6.13a.5.5 0 0 1-.96 0z"/></svg></div>
  <form id="f" autocomplete="off">
    <input id="q" type="text" placeholder="Say or type what to look up" aria-label="Search" spellcheck="false">
    <button type="button" id="mic" class="hb" data-act="mic" title="Speak / stop" aria-label="Speak or stop"><span><svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v3"/></svg></span></button>
    <button type="submit" id="go" class="hb" data-act="go"><span>Search</span></button>
  </form>
  </div>
  <div id="st"></div>
</div>

<div id="res">
  <div id="card"><b>I&rsquo;m listening…</b>Your words appear in the bar above, then I search for you.</div>
  <div id="cse"><div class="gcse-searchresults-only" data-gname="r" data-autoSearchOnLoad="false" data-linkTarget="_blank"></div></div>
  <iframe id="gf" title="Page" referrerpolicy="no-referrer"></iframe>
</div>

<div class="tools">
  <button type="button" class="hb" data-act="up" data-rep="1" title="Scroll up" aria-label="Scroll up"><span><svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="m18 15-6-6-6 6"/></svg></span></button>
  <button type="button" class="hb" data-act="down" data-rep="1" title="Scroll down" aria-label="Scroll down"><span><svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="m6 9 6 6 6-6"/></svg></span></button>
  <button type="button" class="hb" data-act="clear" title="Clear"><span>Clear</span></button>
  <button type="button" class="hb" data-act="back" title="Back to the results" aria-label="Back to results"><span><svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="m15 18-6-6 6-6"/></svg></span></button>
  <button type="button" class="hb" data-act="close" title="Close" aria-label="Close"><span><svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="M18 6 6 18M6 6l12 12"/></svg></span></button>
</div>
<i class="cur"></i><i class="cur"></i>
<div id="toast"></div>

<script>
const $ = s => document.querySelector(s);
const CSE_ID = <?= json_encode(GOOGLE_CSE_ID) ?>;
const LANG = <?= json_encode(SPEECH_LANG) ?> || navigator.language || 'en-US';
const USE_PHONE_MIC = <?= json_encode(USE_PHONE_MIC) ?>;
const inFrame = window.parent !== window;
const q = $('#q'), st = $('#st'), res = $('#res'), card = $('#card'), micBtn = $('#mic');
const say = t => { st.textContent = t || ''; };
let lastQ = '', listening = false, rec = null, gotError = '', heard = '';

let toastT;
function toast(t) { const el = $('#toast'); el.textContent = t; el.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(() => el.classList.remove('show'), 3500); }

// ---------- results ----------
let cseReady = false, pending = null;
if (CSE_ID) {
  $('#cse').style.display = 'block';
  window.__gcse = { parsetags: 'onload', callback: () => { cseReady = true; if (pending) { const t = pending; pending = null; runCse(t); } } };
  const s = document.createElement('script');
  s.async = true; s.src = 'https://cse.google.com/cse.js?cx=' + encodeURIComponent(CSE_ID);
  document.head.appendChild(s);
}
function runCse(t) {
  try { google.search.cse.element.getElement('r').execute(t); }
  catch (e) { say('Could not run the search: ' + e.message); }
}
const gf = $('#gf');
let onPage = false;
function showPage(url) {                       // everything opens inside this window, never in a new tab
  gf.src = url; gf.style.display = 'block';
  $('#cse').style.display = 'none'; card.style.display = 'none';
  res.classList.add('page'); res.scrollTop = 0; onPage = true;
}
function back() {
  if (!onPage) return;
  gf.src = 'about:blank'; gf.style.display = 'none'; res.classList.remove('page'); onPage = false;
  if (CSE_ID) $('#cse').style.display = 'block'; else card.style.display = 'block';
}
// links in the results open in the window too
res.addEventListener('click', e => {
  const a = e.target.closest && e.target.closest('#cse a[href]');
  if (a) { e.preventDefault(); showPage(a.href); }
}, true);
function search(t) {
  t = (t || '').trim();
  if (!t) return;
  lastQ = t; q.value = t;
  back(); res.scrollTop = 0;
  if (CSE_ID) {
    card.style.display = 'none';
    say('Searching: ' + t);
    if (cseReady) runCse(t); else pending = t;
  } else {
    showPage('https://www.google.com/search?igu=1&hl=' + encodeURIComponent(LANG.split('-')[0]) + '&q=' + encodeURIComponent(t));
    say('Searching: ' + t);
  }
}

// ---------- speech to text ----------
const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
function phoneTrack() {
  try {
    if (!USE_PHONE_MIC || !inFrame) return null;
    const v = parent.document.getElementById('video');
    const t = v && v.srcObject && v.srcObject.getAudioTracks()[0];
    return t && t.readyState === 'live' ? t : null;
  } catch (e) { return null; }
}
function makeRec() {
  const r = new SR();
  r.lang = LANG; r.interimResults = true; r.continuous = false; r.maxAlternatives = 1;
  r.onstart = () => { listening = true; micBtn.classList.add('live'); say('Listening… go ahead'); };
  r.onresult = e => {
    let fin = '', interim = '';
    for (let i = e.resultIndex; i < e.results.length; i++) {
      const x = e.results[i];
      if (x.isFinal) fin += x[0].transcript; else interim += x[0].transcript;
    }
    heard += fin;
    q.value = (heard + interim).trim();
  };
  r.onerror = e => {
    gotError = e.error || 'error';
    const m = {
      'not-allowed': 'The microphone is blocked. Allow it for this page (needs https:// or localhost).',
      'service-not-allowed': 'Speech recognition is not allowed in this browser.',
      'audio-capture': 'No microphone found.',
      'network': 'Speech recognition needs an internet connection.',
      'no-speech': "I didn't hear anything. Tap the mic and try again.",
    }[gotError];
    if (gotError !== 'aborted') say(m || 'Speech error: ' + gotError);
  };
  r.onend = () => {
    listening = false; micBtn.classList.remove('live');
    const t = q.value.trim();
    if (t && !gotError) search(t);
    else if (!gotError) say("I didn't catch that. Tap the mic and try again.");
  };
  return r;
}
function startListen() {
  if (!SR) { say('Speech recognition needs Chrome or Edge (Firefox does not have it). You can still type.'); return; }
  if (listening) return;
  gotError = ''; heard = ''; q.value = '';
  rec = makeRec();
  try {
    const t = phoneTrack();
    if (t) { try { rec.start(t); } catch (e) { rec = makeRec(); rec.start(); } } else rec.start();
  } catch (e) { say('Could not start listening: ' + e.message); }
}
function stopListen() { try { rec && rec.stop(); } catch (e) {} }
function toggleListen() { listening ? stopListen() : startListen(); }

// ---------- buttons ----------
const act = {
  mic: toggleListen,
  go: () => { stopListen(); search(q.value); },
  up: () => res.scrollBy({ top: -240, behavior: 'smooth' }),
  down: () => res.scrollBy({ top: 240, behavior: 'smooth' }),
  clear: () => { stopListen(); back(); q.value = ''; lastQ = ''; res.scrollTop = 0; card.style.display = 'block'; card.innerHTML = '<b>I&rsquo;m listening…</b>Your words appear in the bar above, then I search for you.'; say(''); },
  back,
  close: () => { stopListen(); if (inFrame) parent.postMessage({ pc: 'close' }, location.origin); },
};
document.querySelectorAll('.hb').forEach(b => {
  if (b.id === 'go') return;                       // the form submit handles Search
  b.addEventListener('click', () => act[b.dataset.act] && act[b.dataset.act]());
});
$('#f').addEventListener('submit', e => { e.preventDefault(); act.go(); });
q.addEventListener('keydown', e => { if (e.key === 'Escape') act.close(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') act.close(); });

// ---------- hands (sent by viewer.php) ----------
const DWELL = 700;
const curEls = [...document.querySelectorAll('.cur')];
const dw = new Map(), latch = new Map(), pinchPrev = new Map();
let lastT = 0, scrollY = null, tap = null;
const setP = (el, v) => { v = Math.round(v * 50) / 50; if (el._p !== v) { el._p = v; el.style.setProperty('--p', v); } };

let warnedFrame = false;
function clickAt(x, y) {                          // a quick pinch = a click at the fingertip
  const r = document.createElement('i');
  r.className = 'rip'; r.style.left = x + 'px'; r.style.top = y + 'px';
  document.body.appendChild(r); setTimeout(() => r.remove(), 500);
  const el = document.elementFromPoint(x, y);
  if (!el) return;
  if (el === gf) {
    if (!warnedFrame) { warnedFrame = true; toast("Clicks inside Google's own page need a real mouse. Set GOOGLE_CSE_ID in browser.php to click results by hand."); }
    return;
  }
  try { el.focus && el.focus(); } catch (e) {}
  el.click();
}

function onHands(list, thumb) {
  const t = performance.now(), dt = Math.min(100, t - (lastT || t)); lastT = t;
  if (thumb) res.scrollTop += thumb * dt * 0.8;   // thumbs up: up, thumbs down: down
  curEls.forEach((el, i) => {
    const h = list[i];
    if (!h) { el.style.display = 'none'; return; }
    el.style.display = 'block'; el.style.left = h.x + 'px'; el.style.top = h.y + 'px';
    el.classList.toggle('pinch', !!h.pinch);
  });

  // buttons: hold a fingertip on one (dwell) or pinch on it
  const btns = [...document.querySelectorAll('.hb')];
  const rects = btns.map(b => b.getBoundingClientRect());
  const inR = (r, x, y, m) => x >= r.left - m && x <= r.right + m && y >= r.top - m && y <= r.bottom + m;
  let overBtn = false;
  btns.forEach((b, i) => {
    const r = rects[i], rep = b.dataset.rep === '1';
    const hit = list.some(h => inR(r, h.x, h.y, 8));
    const pin = list.some(h => h.pinch && inR(r, h.px, h.py, 8));
    if (hit || pin) overBtn = true;
    if (!hit) latch.set(b, false);
    let d = dw.get(b) || 0;
    d = hit && !latch.get(b) ? d + dt / DWELL : Math.max(0, d - dt / DWELL * 2);
    const fire = d >= 1 || (pin && !pinchPrev.get(b));
    pinchPrev.set(b, pin);
    if (fire) { d = rep ? 0.55 : 0; latch.set(b, !rep && hit); (b.id === 'go' ? act.go : act[b.dataset.act])(); }
    dw.set(b, d); setP(b, Math.min(1, d));
  });

  // pinch + drag on the results scrolls them; a quick pinch (tap) clicks what is under the fingertip
  const rr = res.getBoundingClientRect();
  const p = list.find(h => h.pinch && inR(rr, h.px, h.py, 0));
  if (p && !overBtn) {
    if (scrollY === null) { scrollY = p.py; tap = { x: p.px, y: p.py, t, moved: false }; }
    else {
      res.scrollTop -= p.py - scrollY; scrollY = p.py;
      if (tap && Math.hypot(p.px - tap.x, p.py - tap.y) > 14) tap.moved = true;
    }
  } else if (scrollY !== null) {
    scrollY = null;
    if (tap && !tap.moved && t - tap.t < 900) clickAt(tap.x, tap.y);
    tap = null;
  }
}

addEventListener('message', e => {
  if (e.origin !== location.origin || e.source !== parent) return;
  const m = e.data;
  if (!m || !m.pc) return;
  if (m.pc === 'hand') onHands(m.hands || [], m.thumb || 0);
  else if (m.pc === 'listen') startListen();
  else if (m.pc === 'stop') { stopListen(); onHands([], 0); }
});
if (inFrame) parent.postMessage({ pc: 'ready' }, location.origin);
</script>
</body>
</html>
