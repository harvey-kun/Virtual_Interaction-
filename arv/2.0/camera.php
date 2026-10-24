<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Phone Camera</title>
<meta name="theme-color" content="#0B1020">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root { color-scheme: dark; --bg:#0B1020; --surface:#121A2E; --surface-3:#1F2B49; --border:#2A3758; --text:#E8ECF8; --muted:#A3AFCC; --primary:#2563EB; --primary-hi:#3B7BFF; --ring:#93C5FD; --ok:#34D399; --danger:#DC2626; --warn:#FBBF24; }
  html, body { margin: 0; min-height: 100%; background: radial-gradient(700px 360px at 50% -10%, #18295A 0%, transparent 65%), var(--bg); color: var(--text); font: 16px/1.5 Inter, system-ui, sans-serif; }
  .wrap { max-width: 480px; margin: 0 auto; padding: 16px 16px max(24px, env(safe-area-inset-bottom)); }
  .head { display: flex; align-items: center; gap: 12px; margin: 4px 0 16px; }
  .avatar { flex: none; width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center; color: #fff; background: linear-gradient(135deg,#2563EB,#0891B2); box-shadow: 0 4px 14px rgba(37,99,235,.4); }
  .avatar svg { width: 24px; height: 24px; }
  h1 { font-size: 18px; font-weight: 600; margin: 0; line-height: 1.3; }
  .sub { font-size: 13px; color: var(--muted); }
  .bub { background: var(--surface); border: 1px solid var(--border); border-radius: 4px 18px 18px 18px; padding: 12px 14px; margin-bottom: 12px; font-size: 14px; }
  video { width: 100%; background: #000; border: 1px solid var(--border); border-radius: 18px; aspect-ratio: 3/4; object-fit: cover; display: block; }
  .btns { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; }
  button { font: inherit; font-weight: 500; border: 1px solid var(--border); border-radius: 12px; min-height: 48px; padding: 12px; background: var(--surface-3); color: var(--text); cursor: pointer; transition: background .15s, transform .15s; }
  button:hover:not(:disabled) { background: #29395F; }
  button:active:not(:disabled) { transform: scale(.97); }
  button:focus-visible, input:focus-visible { outline: 2px solid var(--ring); outline-offset: 2px; }
  button.primary { background: var(--primary); border-color: var(--primary); color: #fff; grid-column: 1 / -1; font-weight: 600; }
  button.primary:hover { background: var(--primary-hi) !important; }
  button.stop { background: var(--danger); border-color: var(--danger); color: #fff; grid-column: 1 / -1; font-weight: 600; }
  button:disabled { opacity: .45; cursor: not-allowed; }
  label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
  input { font: inherit; width: 100%; box-sizing: border-box; min-height: 48px; padding: 12px; border-radius: 12px; border: 1px solid var(--border); background: var(--bg); color: var(--text); margin-bottom: 12px; }
  input:focus { border-color: var(--primary-hi); }
  .stat { margin-top: 14px; display: flex; align-items: center; gap: 10px; background: var(--surface); border: 1px solid var(--border); border-radius: 999px; padding: 8px 16px; font-size: 14px; color: var(--muted); }
  .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--warn); flex: none; }
  .stat.ok .dot { background: var(--ok); } .stat.ok { color: var(--text); }
  #warn { background: rgba(127,29,29,.45); border: 1px solid #B45353; border-radius: 12px; padding: 12px 14px; margin-bottom: 12px; display: none; font-size: 14px; }
  @media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; } }
</style>
</head>
<body>
<main class="wrap">
  <header class="head"><div class="avatar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 13 5.22 3.48a.5.5 0 0 0 .78-.42V7.87a.5.5 0 0 0-.75-.43L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg></div><div><h1>Phone camera</h1><div class="sub">Send your camera to the assistant on your computer</div></div></header>
  <div id="warn" role="alert"></div>
  <div id="roomBox" class="bub" style="display:none">
    <label for="roomInput">Room code from the computer screen</label>
    <input id="roomInput" placeholder="Paste the room code" autocapitalize="off" autocomplete="off">
  </div>
  <video id="preview" autoplay playsinline muted aria-label="Camera preview"></video>
  <div class="btns">
    <button id="bStart" class="primary">Start camera</button>
    <button id="bFlip" disabled>Switch camera</button>
    <button id="bMic" disabled>Mic: on</button>
    <button id="bRotate" disabled style="grid-column:1 / -1">Rotate video (90&deg;)</button>
    <button id="bStop" class="stop" style="display:none">Stop</button>
  </div>
  <div id="statBox" class="stat" role="status"><span class="dot"></span><span id="status">Not started.</span></div>
</main>

<script>
const $ = s => document.querySelector(s);
const RTC_CFG = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }, { urls: 'stun:stun1.l.google.com:19302' }] };

let room = new URLSearchParams(location.search).get('room') || '';
if (!room) $('#roomBox').style.display = 'block';

if (!window.isSecureContext || !navigator.mediaDevices) {
  const w = $('#warn');
  w.style.display = 'block';
  w.innerHTML = 'Browsers only allow camera/mic on <b>HTTPS</b> pages. Open this page with <b>https://</b> (see setup notes) and accept the certificate warning if you use a self-signed certificate.';
}

let stream = null, pc = null, sid = null, since = 0, facing = 'user', running = false, wakeLock = null;

// Extra rotation applied on the desktop viewer (0/90/180/270). The viewer already shows portrait video horizontally.
let rotOffset = 0;
try { rotOffset = parseInt(localStorage.getItem('pc_rot') || '0', 10) || 0; } catch (e) {}
function sendRotate() { if (room) api('send', 'viewer', { type: 'rotate', offset: rotOffset }).catch(() => {}); }
function rotate() {
  rotOffset = (rotOffset + 90) % 360;
  try { localStorage.setItem('pc_rot', rotOffset); } catch (e) {}
  sendRotate();
}
const status = t => { $('#status').textContent = t; $('#statBox').classList.toggle('ok', /Streaming/.test(t)); };
const api = (action, to, body) =>
  fetch(`signal.php?action=${action}&room=${room}&to=${to}` + (action === 'poll' ? `&since=${since}` : ''),
        body ? { method: 'POST', body: JSON.stringify(body) } : undefined).then(r => r.json());

async function getStream() {
  return navigator.mediaDevices.getUserMedia({
    video: { facingMode: facing, width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 60 } }, // 720p60: twice the frames for fast hand movement
    audio: { echoCancellation: true, noiseSuppression: false, autoGainControl: false } // noise suppression would mute claps
  });
}

async function start() {
  if (!room) room = $('#roomInput').value.trim().replace(/[^A-Za-z0-9]/g, '');
  if (room.length < 8) { status('Enter the room code from the desktop page.'); return; }
  try { stream = await getStream(); } catch (e) { status('Camera/mic blocked or unavailable: ' + e.message); return; }
  $('#preview').srcObject = stream;
  running = true;
  $('#bStart').style.display = 'none';
  $('#bStop').style.display = 'block';
  $('#bFlip').disabled = false; $('#bMic').disabled = false; $('#bRotate').disabled = false;
  try { wakeLock = await navigator.wakeLock.request('screen'); } catch (e) {}
  since = 0;
  try { await api('reset', 'camera'); } catch (e) {}
  connect();
  poll();
}

async function connect() {
  if (!running) return;
  if (pc) { pc.onconnectionstatechange = pc.onicecandidate = null; pc.close(); }
  sid = Math.random().toString(36).slice(2);
  const mySid = sid;
  pc = new RTCPeerConnection(RTC_CFG);
  stream.getTracks().forEach(t => pc.addTrack(t, stream));
  pc.onicecandidate = e => { if (e.candidate && sid === mySid) api('send', 'viewer', { type: 'ice', sid: mySid, candidate: e.candidate }); };
  pc.onconnectionstatechange = () => {
    if (sid !== mySid) return;
    const s = pc.connectionState;
    if (s === 'connected') status('Streaming to your computer');
    else if (s === 'connecting') status('Connecting…');
    else if (s === 'failed' || s === 'disconnected') { status('Connection lost, retrying…'); setTimeout(() => sid === mySid && connect(), 2000); }
  };
  const offer = await pc.createOffer();
  await pc.setLocalDescription(offer);
  status('Waiting for the desktop page…');
  await api('send', 'viewer', { type: 'offer', sid: mySid, sdp: offer.sdp });
  sendRotate();
}

async function handle(msg) {
  if (!pc) return;
  if (msg.type === 'answer' && msg.sid === sid) {
    await pc.setRemoteDescription({ type: 'answer', sdp: msg.sdp });
    try {
      const sender = pc.getSenders().find(s => s.track && s.track.kind === 'video');
      const p = sender.getParameters();
      if (!p.encodings || !p.encodings.length) p.encodings = [{}];
      p.encodings[0].maxBitrate = 4000000;
      p.encodings[0].maxFramerate = 60;
      p.degradationPreference = 'maintain-framerate'; // drop resolution before frames when the network is tight
      await sender.setParameters(p);
    } catch (e) {}
  } else if (msg.type === 'ice' && msg.sid === sid) {
    try { await pc.addIceCandidate(msg.candidate); } catch (e) {}
  } else if (msg.type === 'ready') {
    connect(); // desktop page was (re)opened
  }
}

async function poll() {
  if (!running) return;
  try {
    const j = await api('poll', 'camera');
    for (const m of j.msgs || []) { since = m.id; await handle(m.msg); }
  } catch (e) {}
  setTimeout(poll, 400);
}

async function flip() {
  facing = facing === 'user' ? 'environment' : 'user';
  try {
    const s = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing, width: { ideal: 1920 }, height: { ideal: 1080 } } });
    const newTrack = s.getVideoTracks()[0];
    const old = stream.getVideoTracks()[0];
    const sender = pc && pc.getSenders().find(x => x.track && x.track.kind === 'video');
    if (sender) await sender.replaceTrack(newTrack);
    stream.removeTrack(old); old.stop(); stream.addTrack(newTrack);
    $('#preview').srcObject = stream;
  } catch (e) { facing = facing === 'user' ? 'environment' : 'user'; status('Could not switch camera: ' + e.message); }
}

function toggleMic() {
  const t = stream && stream.getAudioTracks()[0];
  if (!t) return;
  t.enabled = !t.enabled;
  $('#bMic').textContent = 'Mic: ' + (t.enabled ? 'on' : 'off');
}

async function stop() {
  running = false;
  try { await api('send', 'viewer', { type: 'bye', sid }); } catch (e) {}
  if (pc) pc.close(); pc = null;
  if (stream) stream.getTracks().forEach(t => t.stop()); stream = null;
  if (wakeLock) { wakeLock.release().catch(() => {}); wakeLock = null; }
  $('#preview').srcObject = null;
  $('#bStart').style.display = 'block'; $('#bStop').style.display = 'none';
  $('#bFlip').disabled = true; $('#bMic').disabled = true; $('#bRotate').disabled = true;
  status('Stopped.');
}

$('#bStart').onclick = start;
$('#bStop').onclick = stop;
$('#bFlip').onclick = flip;
$('#bMic').onclick = toggleMic;
$('#bRotate').onclick = rotate;
document.addEventListener('visibilitychange', async () => {
  if (running && document.visibilityState === 'visible') { try { wakeLock = await navigator.wakeLock.request('screen'); } catch (e) {} }
});
</script>
</body>
</html>
