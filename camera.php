<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Phone Camera</title>
<style>
  :root { color-scheme: dark; }
  html, body { margin: 0; min-height: 100%; background: #0b0d12; color: #e8eaf0; font: 16px/1.5 system-ui, sans-serif; }
  .wrap { max-width: 480px; margin: 0 auto; padding: 16px; }
  h1 { font-size: 20px; margin: 8px 0 12px; }
  video { width: 100%; background: #000; border-radius: 14px; aspect-ratio: 3/4; object-fit: cover; }
  .btns { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; }
  button { font: inherit; border: 0; border-radius: 10px; padding: 14px; background: #232a3a; color: #fff; }
  button.primary { background: #2b6cff; grid-column: 1 / -1; font-weight: 600; }
  button.stop { background: #d64545; grid-column: 1 / -1; }
  button:disabled { opacity: .4; }
  input { font: inherit; width: 100%; box-sizing: border-box; padding: 12px; border-radius: 10px; border: 1px solid #262c3a; background: #151922; color: #fff; margin-bottom: 10px; }
  #status { margin-top: 12px; color: #8b93a7; font-size: 14px; }
  #warn { background: #4a2020; border: 1px solid #8a3a3a; border-radius: 10px; padding: 12px; margin-bottom: 12px; display: none; font-size: 14px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Phone camera</h1>
  <div id="warn"></div>
  <div id="roomBox" style="display:none">
    <input id="roomInput" placeholder="Room code from the desktop page" autocapitalize="off" autocomplete="off">
  </div>
  <video id="preview" autoplay playsinline muted></video>
  <div class="btns">
    <button id="bStart" class="primary">Start camera</button>
    <button id="bFlip" disabled>Switch camera</button>
    <button id="bMic" disabled>Mic: on</button>
    <button id="bRotate" disabled style="grid-column:1 / -1">Rotate video (90°)</button>
    <button id="bStop" class="stop" style="display:none">Stop</button>
  </div>
  <div id="status">Not started.</div>
</div>

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
const status = t => $('#status').textContent = t;
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
    if (s === 'connected') status('Streaming to desktop ✔');
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
