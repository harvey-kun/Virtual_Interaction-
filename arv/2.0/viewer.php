<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Camera Assistant</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  /* ---------- design tokens ---------- */
  :root {
    color-scheme: dark;
    --bg: #0B1020; --surface: #121A2E; --surface-2: #18223A; --surface-3: #1F2B49;
    --border: #2A3758; --text: #E8ECF8; --muted: #A3AFCC;
    --primary: #2563EB; --primary-hi: #3B7BFF; --ring: #93C5FD;
    --ok: #34D399; --warn: #FBBF24; --danger: #F87171; --yt: #F87171;
    --r-sm: 8px; --r-md: 12px; --r-lg: 18px; --r-pill: 999px;
    --shadow: 0 10px 30px rgba(2,6,23,.55);
    --font: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    --mono: ui-monospace, 'SF Mono', Consolas, monospace;
  }
  html, body { margin: 0; height: 100%; background: #000; color: var(--text); font: 400 16px/1.5 var(--font); overflow: hidden; }
  body.idle { cursor: none; }
  svg.i { width: 1em; height: 1em; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

  :where(button) { font: inherit; font-weight: 500; background: var(--primary); color: #fff; border: 0; border-radius: var(--r-md); padding: 10px 16px; min-height: 40px; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: background .15s, transform .15s, border-color .15s; }
  :where(button:hover) { background: var(--primary-hi); }
  :where(button:active) { transform: scale(.97); }
  button.ghost { background: var(--surface-3); color: var(--text); border: 1px solid var(--border); }
  button.ghost:hover { background: #29395F; }
  :focus-visible { outline: 2px solid var(--ring); outline-offset: 2px; }
  input, select { font-family: var(--font); }

  /* The video is centered and rotated with CSS; width/height are swapped by JS for 90/270 degrees. */
  #video { position: fixed; left: 50%; top: 50%; width: 100vw; height: 100vh; transform: translate(-50%, -50%); object-fit: contain; background: #000; }
  #video.fill { object-fit: cover; }
  #overlay { position: fixed; inset: 0; width: 100%; height: 100%; z-index: 3; pointer-events: none; }

  /* ---------- hand box ---------- */
  #handBox { position: fixed; z-index: 4; width: 220px; height: 150px; box-sizing: border-box; border: 1.5px solid var(--primary-hi); border-radius: var(--r-lg);
    background: rgba(11,16,32,.8); color: var(--text); box-shadow: var(--shadow); display: none; flex-direction: column; align-items: center; justify-content: center;
    text-align: center; font-size: 14px; line-height: 1.4; padding: 12px; touch-action: none; cursor: grab; user-select: none;
    transition: background .15s, border-color .15s, transform .12s; }
  #handBox b { font-size: 15px; font-weight: 600; }
  #handBox span { color: var(--muted); }
  #handBox.hover { border-color: var(--ok); background: rgba(6,44,34,.82); }
  #handBox.grab { border-color: var(--warn); background: rgba(56,40,6,.86); transform: scale(1.04); cursor: grabbing; }
  #handBox.grab span, #handBox.hover span { color: var(--text); }
  #handMsg { position: fixed; left: 16px; bottom: 16px; z-index: 3; font-size: 13px; color: var(--text); background: rgba(18,26,46,.94); border: 1px solid var(--border); padding: 6px 14px; border-radius: var(--r-pill); display: none; }

  /* ---------- connect screen (assistant chat) ---------- */
  #setup { position: fixed; inset: 0; z-index: 5; overflow-y: auto; box-sizing: border-box; padding: 28px 16px 48px;
    background: radial-gradient(900px 480px at 50% -12%, #18295A 0%, transparent 62%), var(--bg); }
  #setup.hidden { display: none; }
  .chat { max-width: 720px; margin: 0 auto; display: flex; flex-direction: column; gap: 18px; }
  .ahead { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  .aid { flex: 1; min-width: 180px; }
  .aname { font-weight: 600; font-size: 17px; line-height: 1.3; }
  .asub { font-size: 13px; color: var(--muted); }
  .avatar { flex: none; width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: 24px;
    background: linear-gradient(135deg, #2563EB, #0891B2); box-shadow: 0 4px 14px rgba(37,99,235,.4); }
  .avatar.sm { width: 34px; height: 34px; font-size: 18px; box-shadow: none; }
  .chip { display: inline-flex; align-items: center; gap: 8px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-pill); padding: 6px 14px; font-size: 13px; color: var(--muted); }
  .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--warn); flex: none; }
  .dot.ok { background: var(--ok); }
  .msg { display: flex; gap: 12px; align-items: flex-start; }
  .abub { flex: 1; min-width: 0; box-sizing: border-box; background: var(--surface); border: 1px solid var(--border); border-radius: 4px var(--r-lg) var(--r-lg) var(--r-lg); padding: 16px 18px; box-shadow: 0 2px 10px rgba(2,6,23,.25); }
  .abub p { margin: 0 0 10px; }
  .abub p:last-child { margin-bottom: 0; }
  .lead { font-size: 16px; }
  .steps { margin: 0 0 16px; padding-left: 20px; color: var(--muted); font-size: 14px; }
  .steps li { margin: 4px 0; }
  .steps b { color: var(--text); font-weight: 600; }
  .qrRow { display: flex; gap: 18px; align-items: center; flex-wrap: wrap; }
  .qrSide { flex: 1; min-width: 220px; }
  .qrBtn { position: relative; display: block; padding: 10px; background: #fff; border-radius: var(--r-md); line-height: 0; cursor: zoom-in; border: 2px solid transparent; flex: none; }
  .qrBtn:hover { background: #fff; border-color: var(--primary-hi); }
  .qrBtn:active { transform: scale(.98); }
  #qr { display: block; min-width: 160px; min-height: 160px; }
  #qr img, #qr canvas { display: block; }
  .qrBtn .zoom { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 10px; background: #E0E7FF; color: #0B1020; font: 600 13px/1 var(--font); padding: 9px 10px; border-radius: var(--r-pill); }
  .link { word-break: break-all; background: var(--bg); border: 1px solid var(--border); border-radius: var(--r-sm); padding: 10px 12px; font: 13px/1.45 var(--mono); margin-bottom: 10px; user-select: all; color: var(--text); }
  .btnRow { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .btnRow select { flex: 1; min-width: 0; }
  .note { font-size: 13px; color: var(--muted); margin: 10px 0 0 !important; }
  .warnNote { font-size: 13px; color: var(--warn); margin: 10px 0 0 !important; }
  .lab { display: block; font-size: 13px; color: var(--muted); margin: 10px 0 6px; }
  .abub input[type=text], .abub select { width: 100%; box-sizing: border-box; min-height: 44px; border: 1px solid var(--border); border-radius: var(--r-md); background: var(--bg); color: var(--text); font-size: 14px; padding: 10px 12px; outline: 0; }
  .abub input[type=text]:focus, .abub select:focus { border-color: var(--primary-hi); }
  .abub input#baseIn { font-family: var(--mono); }
  details.adv summary { cursor: pointer; font-weight: 500; font-size: 14px; color: var(--text); list-style: none; display: flex; align-items: center; gap: 8px; min-height: 28px; }
  details.adv summary::-webkit-details-marker { display: none; }
  details.adv summary::after { content: ''; width: 8px; height: 8px; border-right: 2px solid var(--muted); border-bottom: 2px solid var(--muted); transform: rotate(45deg); margin-left: auto; transition: transform .2s; }
  details.adv[open] summary::after { transform: rotate(-135deg); }
  .chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
  .chips .chip { color: var(--text); background: var(--surface-2); }
  .chips .chip svg { color: var(--ring); font-size: 16px; }

  /* ---------- QR lightbox ---------- */
  #qrModal { position: fixed; inset: 0; z-index: 20; display: none; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; background: rgba(5,8,18,.84); backdrop-filter: blur(6px); }
  #qrModal.open { display: flex; }
  .qrPanel { position: relative; width: 100%; max-width: 540px; max-height: 96vh; overflow: auto; box-sizing: border-box; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); padding: 22px 20px 20px; text-align: center; box-shadow: var(--shadow); animation: qrIn .2s ease; }
  @keyframes qrIn { from { opacity: 0; transform: scale(.96); } to { opacity: 1; transform: none; } }
  .qrPanel h2 { margin: 0 40px 4px; font-size: 18px; font-weight: 600; }
  .qrPanel p { margin: 0 0 14px; font-size: 14px; color: var(--muted); }
  #qrClose { position: absolute; right: 10px; top: 10px; width: 44px; height: 44px; min-height: 0; padding: 0; font-size: 20px; }
  #qrBig { display: inline-block; background: #fff; padding: 24px; border-radius: var(--r-md); line-height: 0; }
  #qrBig img, #qrBig canvas { display: block; }
  #qrUrl { margin: 14px 0 0; font: 12px/1.45 var(--mono); color: var(--muted); word-break: break-all; }

  /* ---------- toolbar + hint ---------- */
  #bar { position: fixed; top: 0; left: 0; right: 0; z-index: 4; display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; padding: 14px 12px 24px; background: linear-gradient(rgba(5,8,18,.82), transparent); opacity: 0; transition: opacity .25s; }
  #bar.show { opacity: 1; }
  #bar button { min-height: 38px; padding: 8px 14px; font-size: 13px; border-radius: var(--r-pill); }
  #hint { position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); z-index: 3; background: rgba(18,26,46,.95); border: 1px solid var(--border); padding: 8px 18px; border-radius: var(--r-pill); font-size: 14px; opacity: 0; transition: opacity .3s; pointer-events: none; }
  #hint.show { opacity: 1; }
  #clapFlash { position: fixed; left: 16px; bottom: 56px; z-index: 3; display: inline-flex; align-items: center; gap: 8px; background: rgba(18,26,46,.95); border: 1px solid var(--border); padding: 6px 14px; border-radius: var(--r-pill); font-size: 13px; opacity: 0; transition: opacity .25s; pointer-events: none; }
  #clapFlash.show { opacity: 1; }

  /* ---------- left drawer + hot corner (hand controlled) ---------- */
  #hotCorner { position: fixed; left: 0; top: 0; width: 110px; height: 110px; z-index: 2; box-sizing: border-box; overflow: hidden; cursor: pointer;
    border: 1.5px dashed rgba(147,197,253,.75); border-left: 0; border-top: 0; border-bottom-right-radius: 28px; background: rgba(18,26,46,.6);
    color: #fff; font-size: 28px; padding: 14px 18px; display: flex; flex-direction: column; gap: 4px; transition: opacity .25s; }
  #hotCorner::before { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: calc(var(--p, 0) * 100%); background: rgba(52,211,153,.45); }
  #hotCorner span { position: relative; display: flex; }
  #hotCorner small { position: relative; font: 600 11px/1 var(--font); letter-spacing: .04em; color: var(--muted); }
  #hotCorner.hide { opacity: 0; pointer-events: none; }
  #drawer { position: fixed; left: 0; top: 0; bottom: 0; width: 260px; z-index: 2; box-sizing: border-box; padding: 16px 20px;
    background: rgba(11,16,32,.84); border-right: 1px solid var(--border); transform: translateX(-102%); transition: transform .3s ease; }
  #drawer.open { transform: none; }
  #drawer .dHead { display: flex; align-items: center; justify-content: space-between; height: 56px; }
  #drawer .dHead b { font-size: 17px; font-weight: 600; }
  #dClose { position: relative; overflow: hidden; width: 56px; height: 56px; padding: 0; font-size: 22px; border-radius: var(--r-md); background: rgba(31,43,73,.7); border: 1.5px solid var(--border); }
  #dClose::before { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: calc(var(--p, 0) * 100%); background: rgba(52,211,153,.5); }
  #dClose span { position: relative; }
  #slot { position: absolute; left: 20px; top: 112px; width: 220px; height: 150px; box-sizing: border-box; border: 1.5px dashed rgba(163,175,204,.5); border-radius: var(--r-lg); transition: border-color .15s, background .15s; }
  #drawer.drop #slot, #drawer.drop2 #slot2 { border-color: var(--ok); background: rgba(52,211,153,.12); }
  #slot2 { top: 276px; }
  #drawer .dTip { position: absolute; left: 20px; right: 20px; top: 440px; font-size: 13px; color: var(--muted); }
  .handoff #hotCorner, .handoff #drawer { display: none; }
  #handBox.docked { transition: background .15s, border-color .15s, transform .3s ease, opacity .3s ease, left .2s, top .2s; }
  #handBox.docked.away { transform: translateX(-300px); opacity: 0; pointer-events: none; }

  /* ---------- YouTube box ---------- */
  #ytBox { position: fixed; z-index: 2; box-sizing: border-box; border: 1.5px solid var(--yt); border-radius: var(--r-lg); background: rgba(11,16,32,.96); color: #fff;
    overflow: hidden; touch-action: none; user-select: none; box-shadow: var(--shadow); transition: transform .3s ease, opacity .3s ease, border-color .15s; }
  #ytBox.gone { visibility: hidden; pointer-events: none; }
  #ytBox.docked { background: rgba(11,16,32,.8); cursor: grab; transition: transform .3s ease, opacity .3s ease, border-color .15s, left .2s, top .2s; }
  #ytBox.away { transform: translateX(-300px); opacity: 0; pointer-events: none; }
  #ytBox.hover { border-color: var(--ok); }
  #ytBox.grab, #ytBox.resizing { border-color: var(--warn); }
  #ytBox .ytTile { display: none; position: absolute; inset: 0; flex-direction: column; align-items: center; justify-content: center; text-align: center; font-size: 14px; line-height: 1.4; padding: 10px; }
  #ytBox .ytTile b { font-size: 15px; font-weight: 600; }
  #ytBox .ytTile span { color: var(--muted); }
  #ytBox.docked .ytTile { display: flex; }
  #ytBox .ytFull { position: absolute; inset: 0; display: flex; flex-direction: column; }
  #ytBox.docked .ytFull { visibility: hidden; pointer-events: none; }
  .ytBar { flex: none; display: flex; gap: 8px; padding: 8px; background: rgba(18,26,46,.95); cursor: grab; }
  #ytIn { flex: 1; min-width: 0; box-sizing: border-box; border: 1px solid var(--border); border-radius: var(--r-sm); background: var(--bg); color: #fff; font-size: 14px; padding: 8px 12px; user-select: text; outline: 0; }
  #ytIn:focus { border-color: var(--primary-hi); }
  #ytGo { padding: 8px 16px; }
  .ytStage { position: relative; flex: 1; min-height: 0; background: #000; }
  #ytPlayer { width: 100%; height: 100%; display: block; border: 0; }
  .ytShield { position: absolute; inset: 0; z-index: 1; cursor: grab; }
  #ytMsg { position: absolute; inset: 0; z-index: 2; display: none; align-items: center; justify-content: center; text-align: center; padding: 16px; pointer-events: none; color: var(--text); font-size: 14px; text-shadow: 0 1px 6px #000; }
  .ytCtl { flex: none; background: rgba(18,26,46,.97); padding-bottom: 8px; }
  #ytSeek { position: relative; height: 12px; margin: 12px 18px 0; background: rgba(255,255,255,.25); border-radius: 6px; cursor: pointer; }
  #ytSeek::after { content: ''; position: absolute; left: 0; right: 0; top: -12px; bottom: -12px; }
  #ytFill { position: relative; height: 100%; width: 0; background: var(--yt); border-radius: 6px; }
  #ytFill::after { content: ''; position: absolute; right: -9px; top: -3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 6px #000a; }
  .ytRow { display: flex; align-items: center; gap: 8px; padding: 12px 20px 0 12px; }
  .ytb { position: relative; overflow: hidden; min-width: 52px; height: 46px; padding: 0 12px; font-size: 14px; border-radius: var(--r-md); background: var(--surface-3); border: 1.5px solid var(--border); }
  .ytb::before { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: calc(var(--p, 0) * 100%); background: rgba(52,211,153,.55); }
  .ytb span { position: relative; }
  #ytTime { flex: 1; text-align: center; font-size: 13px; color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; }
  .ytRz { position: absolute; right: 0; bottom: 0; width: 16px; height: 16px; z-index: 6; cursor: nwse-resize; }
  .ytc { position: absolute; z-index: 5; width: 12px; height: 12px; border-radius: 50%; background: rgba(251,191,36,.9); pointer-events: none; display: none; }
  #ytBox:not(.docked) .ytc { display: block; }
  .ytc.tl { left: 3px; top: 3px; } .ytc.tr { right: 3px; top: 3px; } .ytc.bl { left: 3px; bottom: 3px; } .ytc.br { right: 3px; bottom: 3px; }

  /* ---------- clap-clap search (assistant command bar) ---------- */
  #search { position: fixed; z-index: 6; left: 50%; top: 16%; width: min(640px, 92vw); text-align: center;
    transform: translate(-50%, -14px); opacity: 0; pointer-events: none; transition: opacity .2s, transform .2s; }
  #search.show { opacity: 1; pointer-events: auto; transform: translate(-50%, 0); }
  #search .sHead { display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 14px; }
  #search .sTitle { font-size: 24px; font-weight: 600; text-shadow: 0 2px 14px #000b; }
  #search form { display: flex; align-items: center; gap: 6px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-pill); box-shadow: var(--shadow); padding: 6px 8px 6px 22px; }
  #sq { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; color: var(--text); font-size: 18px; padding: 12px 8px; }
  #sq::placeholder { color: var(--muted); }
  #search form:focus-within { border-color: var(--primary-hi); }
  #sGo { border-radius: var(--r-pill); padding: 10px 22px; font-weight: 600; }
  #sClose { background: transparent; color: var(--muted); font-size: 24px; line-height: 1; padding: 6px 12px; }
  #sClose:hover { background: var(--surface-3); color: var(--text); }
  #search .tip { margin-top: 12px; font-size: 13px; color: var(--text); text-shadow: 0 1px 6px #000; }

  /* ---------- minimized tools: circles that snap to the right corners ---------- */
  .bubble { position: fixed; z-index: 4; width: 64px; height: 64px; border-radius: 50%; box-sizing: border-box; display: none; align-items: center; justify-content: center;
    color: #fff; cursor: grab; touch-action: none; user-select: none; font-size: 28px;
    background: linear-gradient(135deg, #2563EB, #0891B2); border: 2px solid rgba(255,255,255,.55);
    box-shadow: var(--shadow); left: 0; top: 0; will-change: transform; transition: transform .32s cubic-bezier(.2,1.4,.4,1); animation: bubIn .3s ease; }
  .bubble svg { width: 28px; height: 28px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; pointer-events: none; }
  .bubble.yt { background: linear-gradient(135deg, #DC2626, #F97316); }
  .bubble.noanim { transition: none; animation: none; }
  .bubble.drag { transition: none; cursor: grabbing; border-color: var(--warn); }
  @keyframes bubIn { from { scale: .3; opacity: 0; } to { scale: 1; opacity: 1; } }
  @keyframes popIn { from { scale: .3; opacity: 0; } to { scale: 1; opacity: 1; } }
  .popin { animation: popIn .35s ease; }

  @media (max-width: 520px) {
    .qrRow { justify-content: center; }
    .qrSide { min-width: 100%; }
    .abub { padding: 14px; }
  }
  @media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; }
  }
</style>
</head>
<body>

<video id="video" autoplay playsinline muted></video>
<canvas id="overlay"></canvas>

<button type="button" id="hotCorner" aria-label="Open tools drawer (D)" title="Hold your hand here (or click / press D)"><span><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></span><small>TOOLS</small></button>
<aside id="drawer" aria-label="Tools">
  <div class="dHead"><b>Tools</b><button type="button" id="dClose" title="Close (D)" aria-label="Close tools"><span><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></span></button></div>
  <div id="slot"></div>
  <div id="slot2"></div>
  <div class="dTip">Pinch a box and drag it out. Drag it back here to put it away. Push your hand to the left (or hold a fingertip on the close button) to dismiss this drawer.</div>
</aside>

<div id="handBox">
  <b id="boxTitle">Hand box</b>
  <span id="boxState">Pinch thumb + index over me, then move to drag</span>
</div>
<div id="handMsg"></div>

<div id="ytBox" class="docked gone">
  <div class="ytTile"><b><svg class="i" style="vertical-align:-2px" viewBox="0 0 24 24" aria-hidden="true"><polygon points="7 4 20 12 7 20 7 4"/></svg> YouTube</b><span>Pinch me and drag me out</span></div>
  <div class="ytFull">
    <div class="ytBar" title="Drag to move">
      <input id="ytIn" type="text" placeholder="Paste a YouTube link, press Enter" aria-label="YouTube link" spellcheck="false" autocomplete="off">
      <button type="button" id="ytGo">Play</button>
    </div>
    <div class="ytStage">
      <div id="ytPlayer"></div>
      <div class="ytShield" title="Click: play / pause · drag: move"></div>
      <div id="ytMsg"></div>
    </div>
    <div class="ytCtl">
      <div id="ytSeek"><div id="ytFill"></div></div>
      <div class="ytRow">
        <button type="button" class="ytb" id="ytBack" title="Back 10 s"><span>&minus;10s</span></button>
        <button type="button" class="ytb" id="ytToggle" title="Play / pause"><span><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><polygon points="7 4 20 12 7 20 7 4"/></svg></span></button>
        <button type="button" class="ytb" id="ytFwd" title="Forward 10 s"><span>+10s</span></button>
        <span id="ytTime">0:00</span>
        <button type="button" class="ytb" id="ytMute" title="Mute / unmute"><span><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg></span></button>
        <button type="button" class="ytb" id="ytAway" title="Pause and put away in the drawer"><span><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 6 5 5 5-5M7 13l5 5 5-5"/></svg></span></button>
      </div>
    </div>
    <div class="ytRz" title="Drag to resize"></div>
  </div>
  <i class="ytc tl"></i><i class="ytc tr"></i><i class="ytc bl"></i><i class="ytc br"></i>
</div>

<div id="bar">
  <button id="bFull" type="button">Fullscreen (F)</button>
  <button id="bFit" class="ghost">Fit / Fill (C)</button>
  <button id="bRot" class="ghost">Rotate (R)</button>
  <button id="bMirror" class="ghost">Mirror: on (X)</button>
  <button id="bClap" class="ghost">Clap search: on (K)</button>
  <button id="bHand" class="ghost">Hand box: on (H)</button>
  <button id="bTrack" class="ghost">Hand overlay: on (T)</button>
  <button id="bCam" class="ghost">Computer camera (W)</button>
  <button id="bYt" class="ghost">YouTube box (Y)</button>
  <button id="bMute" class="ghost">Unmute (M)</button>
  <button id="bSetup" class="ghost">Connection info (I)</button>
</div>
<div id="hint">Click anywhere to unmute and go fullscreen</div>
<div id="clapFlash" role="status"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v3"/></svg> Clap heard, clap again to search</div>

<div id="search" role="dialog" aria-label="Search">
  <div class="sHead"><div class="avatar sm"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.13-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.13a.5.5 0 0 1 .96 0L14.06 8.5a2 2 0 0 0 1.44 1.44l6.13 1.58a.5.5 0 0 1 0 .96l-6.13 1.58a2 2 0 0 0-1.44 1.44l-1.58 6.13a.5.5 0 0 1-.96 0z"/><path d="M20 3v4M22 5h-4"/></svg></div><div class="sTitle">What can I look up?</div></div>
  <form id="sForm" autocomplete="off">
    <input id="sq" type="text" placeholder="Ask Google anything…" aria-label="Search Google" spellcheck="false">
    <button type="button" id="sClose" title="Close (Esc)" aria-label="Close search">&times;</button>
    <button type="submit" id="sGo">Search</button>
  </form>
  <div class="tip">Enter to search on Google &middot; Esc to close</div>
</div>

<div id="setup">
  <main class="chat">
    <header class="ahead">
      <div class="avatar"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.13-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.13a.5.5 0 0 1 .96 0L14.06 8.5a2 2 0 0 0 1.44 1.44l6.13 1.58a.5.5 0 0 1 0 .96l-6.13 1.58a2 2 0 0 0-1.44 1.44l-1.58 6.13a.5.5 0 0 1-.96 0z"/><path d="M20 3v4M22 5h-4"/></svg></div>
      <div class="aid"><h1 class="aname" style="margin:0">Camera Assistant</h1><div class="asub">Your phone camera, hands and voice, together on this screen</div></div>
      <div class="chip" role="status"><span class="dot" id="dot"></span><span id="status">Waiting for phone…</span></div>
    </header>

    <section class="msg">
      <div class="avatar sm"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.13-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.13a.5.5 0 0 1 .96 0L14.06 8.5a2 2 0 0 0 1.44 1.44l6.13 1.58a.5.5 0 0 1 0 .96l-6.13 1.58a2 2 0 0 0-1.44 1.44l-1.58 6.13a.5.5 0 0 1-.96 0z"/><path d="M20 3v4M22 5h-4"/></svg></div>
      <div class="abub">
        <p class="lead">Hi! Let&rsquo;s connect your phone camera.</p>
        <ol class="steps">
          <li><b>Scan</b> the code with your phone (tap it to make it bigger), or open the link.</li>
          <li>On the phone, tap <b>Start camera</b>.</li>
        </ol>
        <div class="qrRow">
          <button type="button" class="qrBtn" id="qrBtn" aria-label="Enlarge QR code to scan it more easily" title="Click to enlarge">
            <div id="qr"></div><span class="zoom"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg> Enlarge</span>
          </button>
          <div class="qrSide">
            <div class="link" id="link"></div>
            <div class="btnRow">
              <button id="bCopy" type="button" class="ghost">Copy link</button>
              <button id="bNew" type="button" class="ghost">New room code</button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="msg">
      <div class="avatar sm"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg></div>
      <div class="abub">
        <details class="adv">
          <summary>Phone can&rsquo;t reach the link? Change the address</summary>
          <label for="baseIn" class="lab">Phone address: detected automatically. Type here only to override; clear it to go back to automatic.</label>
          <input id="baseIn" type="text" placeholder="https://your-wan-address/phonecam/" spellcheck="false" autocomplete="off">
          <p class="warnNote" id="baseWarn" hidden>You opened this page through localhost, so a phone can&rsquo;t use this address. Enter your public address above.</p>
        </details>
      </div>
    </section>

    <section class="msg">
      <div class="avatar sm"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="m16 13 5.22 3.48a.5.5 0 0 0 .78-.42V7.87a.5.5 0 0 0-.75-.43L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg></div>
      <div class="abub">
        <p>No phone handy? I can use a camera on this computer instead.</p>
        <label for="camSel" class="lab">Webcam / USB camera</label>
        <div class="btnRow"><select id="camSel"></select><button type="button" id="bLocal">Use camera</button></div>
      </div>
    </section>

    <section class="msg">
      <div class="avatar sm"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 11V6a2 2 0 0 0-4 0v5M14 10V4a2 2 0 0 0-4 0v6M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-6-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/></svg></div>
      <div class="abub">
        <p>Once you&rsquo;re connected, you can control things hands-free:</p>
        <div class="chips">
          <span class="chip"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 11V6a2 2 0 0 0-4 0v5M14 10V4a2 2 0 0 0-4 0v6M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-6-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/></svg> Pinch to grab a box</span>
          <span class="chip"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2M12 19v3"/></svg> Clap twice to search</span>
          <span class="chip"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg> Telescope hand for voice search</span>
        </div>
      </div>
    </section>
  </main>
</div>

<div id="qrModal" role="dialog" aria-modal="true" aria-labelledby="qrTitle">
  <div class="qrPanel">
    <button type="button" id="qrClose" class="ghost" aria-label="Close enlarged QR code"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <h2 id="qrTitle">Scan with your phone</h2>
    <p>Point your phone camera at the code and hold it steady.</p>
    <div id="qrBig"></div>
    <div id="qrUrl"></div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/hands@0.4.1675469240/hands.js" crossorigin="anonymous"></script>
<script>
const $ = s => document.querySelector(s);
const video = $('#video');
const RTC_CFG = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }, { urls: 'stun:stun1.l.google.com:19302' }] };
const sleep = ms => new Promise(r => setTimeout(r, ms));
const ICONS = {
  play: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><polygon points="7 4 20 12 7 20 7 4"/></svg>', pause: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>', vol: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg>', mute: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="m22 9-6 6M16 9l6 6"/></svg>',
  hand: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 11V6a2 2 0 0 0-4 0v5M14 10V4a2 2 0 0 0-4 0v6M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-6-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/></svg>', yt: '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><polygon points="7 4 20 12 7 20 7 4"/></svg>',
};

function newRoom() {
  const a = new Uint8Array(12); crypto.getRandomValues(a);
  const c = 'abcdefghjkmnpqrstuvwxyz23456789';
  return [...a].map(x => c[x % c.length]).join('');
}
function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
function save(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

let room = new URLSearchParams(location.search).get('room') || load('pc_room') || newRoom();
let pc = null, sid = null, since = 0, connected = false;
let mirror = load('pc_mirror') !== '0'; // mirrored by default, like a selfie view
let offset = 0; // extra rotation in degrees, set by the phone's Rotate button or the R key

const api = (action, to, body) =>
  fetch(`signal.php?action=${action}&room=${room}&to=${to}` + (action === 'poll' ? `&since=${since}` : ''),
        body ? { method: 'POST', body: JSON.stringify(body) } : undefined).then(r => r.json());

function setStatus(text, ok) {
  $('#status').textContent = text;
  $('#dot').classList.toggle('ok', !!ok);
}

// Base address the PHONE should use. Defaults to this page's own folder, but if you opened the viewer through
// localhost the phone can't reach that, so you can type your WAN/public address here (it is remembered).
// You can also open viewer.php?base=https://your-wan-address/phonecam/ once.
let serverBase = '', baseInfo = {};
async function loadBase() {
  try { baseInfo = await fetch('address.php?action=get&_=' + Date.now()).then(r => r.json()); serverBase = baseInfo.base || ''; } catch (e) {}
  renderSetup();
}
function baseUrl() {
  let b = (new URLSearchParams(location.search).get('base') || serverBase || '').trim();
  if (!b) return new URL('./', location.href).href;
  if (!/^https?:\/\//i.test(b)) b = 'https://' + b;
  b = b.replace(/\/(?:viewer|camera)\.php.*$/i, '/'); // allow pasting a full page URL
  return b.endsWith('/') ? b : b + '/';
}
function qrSize() { return Math.max(200, Math.min(400, innerWidth - 150, innerHeight - 330)); }
function renderBigQr() {
  const url = baseUrl() + 'camera.php?room=' + room, big = $('#qrBig');
  big.innerHTML = '';
  if (window.QRCode) { const n = qrSize(); new QRCode(big, { text: url, width: n, height: n, correctLevel: QRCode.CorrectLevel.M }); }
  $('#qrUrl').textContent = url;
}
let qrOpen = false, qrFocus = null;
function openQr() { qrFocus = document.activeElement; qrOpen = true; renderBigQr(); $('#qrModal').classList.add('open'); $('#qrClose').focus(); }
function closeQr() { qrOpen = false; $('#qrModal').classList.remove('open'); if (qrFocus && qrFocus.focus) qrFocus.focus(); }
function renderSetup() {
  const baseIn = $('#baseIn');
  if (document.activeElement !== baseIn) baseIn.value = baseInfo.custom || '';
  baseIn.placeholder = serverBase ? 'Automatic: ' + serverBase : 'https://your-wan-address/phonecam/';
  const local = /^(localhost|127\.|\[?::1)/.test(location.hostname) && !serverBase && !baseInfo.custom;
  baseIn.style.borderColor = local ? '#FBBF24' : '';
  $('#baseWarn').hidden = !local;
  if (local) $('#baseIn').closest('details').open = true;
  const url = baseUrl() + 'camera.php?room=' + room;
  $('#link').textContent = url;
  $('#qr').innerHTML = '';
  if (window.QRCode) new QRCode($('#qr'), { text: url, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M });
  else $('#qrBtn').style.display = 'none';
  if (qrOpen) renderBigQr();
}
$('#qrBtn').addEventListener('click', openQr);
$('#qrClose').addEventListener('click', closeQr);
$('#qrModal').addEventListener('click', e => { if (e.target.id === 'qrModal') closeQr(); });
addEventListener('resize', () => { if (qrOpen) renderBigQr(); });

async function useRoom(r) {
  room = r; save('pc_room', room);
  since = 0;
  closePc();
  renderSetup();
  try { await api('reset', 'viewer'); await api('send', 'camera', { type: 'ready' }); } catch (e) {}
}

function closePc() {
  stopLocal();
  stopClap();
  if (pc) { pc.onconnectionstatechange = pc.ontrack = pc.onicecandidate = null; pc.close(); }
  pc = null; sid = null; video.srcObject = null; setConnected(false);
}

function setConnected(v) {
  connected = v;
  $('#setup').classList.toggle('hidden', v);
  if (v) { flashHint(); setStatus('Phone connected', true); }
  else setStatus('Waiting for phone…', false);
}

// ---------- computer camera (webcam / USB) ----------
// Uses the browser's own camera on this computer instead of the phone. Everything else (hand box, clap search,
// YouTube box, rotate, mirror) works the same. A phone connecting later takes over automatically.
let localStream = null;
function stopLocal() {
  if (!localStream) return;
  const s = localStream; localStream = null;
  s.getTracks().forEach(t => { t.onended = null; t.stop(); });
}
async function listCams() {
  const sel = $('#camSel');
  try {
    const cams = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'videoinput');
    const cur = sel.value || load('pc_cam') || '';
    sel.innerHTML = '';
    cams.forEach((d, i) => { const o = document.createElement('option'); o.value = d.deviceId; o.textContent = d.label || 'Camera ' + (i + 1); sel.appendChild(o); });
    if (!cams.length) { const o = document.createElement('option'); o.textContent = 'No camera found'; o.value = ''; sel.appendChild(o); }
    if ([...sel.options].some(o => o.value === cur)) sel.value = cur;
  } catch (e) {}
}
async function startLocal(id) {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    setStatus('Camera access needs https:// (or localhost) on this page.'); showSetup(); return;
  }
  id = id || $('#camSel').value || load('pc_cam') || '';
  closePc();
  const vid = { width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30 } };
  const withId = id ? { ...vid, deviceId: { exact: id } } : vid;
  const audio = { echoCancellation: true, noiseSuppression: false, autoGainControl: false }; // keep claps audible
  let s = null, err = null;
  for (const c of [{ video: withId, audio }, { video: withId }, { video: vid }]) { // mic is optional; stale device id falls back to default
    try { s = await navigator.mediaDevices.getUserMedia(c); break; } catch (e) { err = e; }
  }
  if (!s) { setStatus('Could not open the camera: ' + (err && err.message || 'blocked or in use')); showSetup(); return; }
  localStream = s;
  const vt = s.getVideoTracks()[0];
  vt.onended = () => { if (localStream === s) { stopLocal(); video.srcObject = null; setConnected(false); setStatus('Camera disconnected: waiting…'); showSetup(); } };
  const did = vt.getSettings().deviceId; if (did) save('pc_cam', did);
  video.srcObject = s; video.muted = true; video.play().catch(() => {});
  setConnected(true);
  $('#hint').classList.remove('show');
  setStatus('Using computer camera: ' + (vt.label || 'webcam'), true);
  if (s.getAudioTracks().length) startClap(s);
  await listCams(); if (did) $('#camSel').value = did;
}
$('#bLocal').onclick = () => startLocal($('#camSel').value);
$('#bCam').onclick = e => { e.stopPropagation(); startLocal(); };
$('#camSel').addEventListener('keydown', e => e.stopPropagation());
if (navigator.mediaDevices) {
  listCams();
  navigator.mediaDevices.addEventListener && navigator.mediaDevices.addEventListener('devicechange', listCams);
}

// ---------- rotation ----------
// Portrait video is turned 90 degrees so it shows horizontally by default; `offset` adds more.
function effRot() {
  const portrait = video.videoHeight > video.videoWidth;
  return ((portrait ? 90 : 0) + offset) % 360;
}
function applyRot() {
  const d = effRot();
  const swap = d % 180 !== 0;
  video.style.width = swap ? '100vh' : '100vw';
  video.style.height = swap ? '100vw' : '100vh';
  video.style.transform = `translate(-50%, -50%) ${mirror ? 'scaleX(-1) ' : ''}rotate(${d}deg)`;
}
['loadedmetadata', 'resize', 'playing'].forEach(ev => video.addEventListener(ev, applyRot));
function toggleMirror() {
  mirror = !mirror; save('pc_mirror', mirror ? '1' : '0');
  $('#bMirror').textContent = 'Mirror: ' + (mirror ? 'on' : 'off') + ' (X)';
  applyRot();
}
function rotateMore() { offset = (offset + 90) % 360; applyRot(); }

// ---------- signaling ----------
async function handle(msg) {
  if (msg.type === 'rotate') {
    offset = (((msg.offset | 0) % 360) + 360) % 360;
    applyRot();
  } else if (msg.type === 'offer') {
    closePc();
    sid = msg.sid;
    const mySid = sid;
    pc = new RTCPeerConnection(RTC_CFG);
    pc.ontrack = e => { video.srcObject = e.streams[0]; video.play().catch(() => {}); if (e.track.kind === 'audio') startClap(e.streams[0]); };
    pc.onicecandidate = e => { if (e.candidate && sid === mySid) api('send', 'camera', { type: 'ice', sid: mySid, candidate: e.candidate }); };
    pc.onconnectionstatechange = () => {
      if (!pc || sid !== mySid) return;
      if (pc.connectionState === 'connected') setConnected(true);
      else if (['failed', 'disconnected', 'closed'].includes(pc.connectionState)) { setConnected(false); setStatus('Connection lost — waiting for phone…'); }
    };
    await pc.setRemoteDescription({ type: 'offer', sdp: msg.sdp });
    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);
    await api('send', 'camera', { type: 'answer', sid: mySid, sdp: answer.sdp });
  } else if (msg.type === 'ice' && pc && msg.sid === sid) {
    try { await pc.addIceCandidate(msg.candidate); } catch (e) {}
  } else if (msg.type === 'bye' && msg.sid === sid) {
    closePc();
  }
}

async function poll() {
  const myRoom = room;
  try {
    const j = await api('poll', 'viewer');
    if (myRoom === room) for (const m of j.msgs || []) { since = m.id; await handle(m.msg); }
  } catch (e) {}
  setTimeout(poll, 400);
}

// ---------- UI controls ----------
function toggleFullscreen() {
  if (document.fullscreenElement) document.exitFullscreen();
  else document.documentElement.requestFullscreen().catch(() => {});
}
function toggleMute() { video.muted = !video.muted; $('#bMute').textContent = (video.muted ? 'Unmute' : 'Mute') + ' (M)'; }
function toggleFit() { video.classList.toggle('fill'); }
function showSetup() { $('#setup').classList.remove('hidden'); }

let hintTimer;
function flashHint() {
  if (!video.muted) return;
  $('#hint').classList.add('show'); clearTimeout(hintTimer);
  hintTimer = setTimeout(() => $('#hint').classList.remove('show'), 6000);
}

$('#bFull').onclick = e => { e.stopPropagation(); toggleFullscreen(); };
$('#bFit').onclick = e => { e.stopPropagation(); toggleFit(); };
$('#bRot').onclick = e => { e.stopPropagation(); rotateMore(); };
$('#bMirror').onclick = e => { e.stopPropagation(); toggleMirror(); };
$('#bClap').onclick = e => { e.stopPropagation(); toggleClap(); };
$('#hotCorner').onclick = e => { e.stopPropagation(); setDrawer(true); };
$('#dClose').onclick = e => { e.stopPropagation(); setDrawer(false); };
$('#bMirror').textContent = 'Mirror: ' + (mirror ? 'on' : 'off') + ' (X)';
$('#bHand').onclick = e => { e.stopPropagation(); setHand(!handOn); };
$('#bTrack').onclick = e => { e.stopPropagation(); setTrack(!showTrack); };
$('#bMute').onclick = e => { e.stopPropagation(); toggleMute(); };
$('#bSetup').onclick = e => { e.stopPropagation(); showSetup(); };
$('#bNew').onclick = () => useRoom(newRoom());
let baseTimer;
$('#baseIn').addEventListener('input', e => {
  const v = e.target.value.trim();
  clearTimeout(baseTimer);
  baseTimer = setTimeout(async () => { // saved in the database; empty = automatic
    try { baseInfo = await fetch('address.php?action=set&host=' + encodeURIComponent(v)).then(r => r.json()); serverBase = baseInfo.base || ''; } catch (err) {}
    renderSetup();
  }, 500);
});
$('#bCopy').onclick = () => { navigator.clipboard && navigator.clipboard.writeText($('#link').textContent); $('#bCopy').textContent = 'Copied'; setTimeout(() => $('#bCopy').textContent = 'Copy link', 1500); };

// First click on the video unmutes (browser autoplay rules need a gesture) and goes fullscreen.
video.addEventListener('click', () => {
  if (video.muted && !localStream) { toggleMute(); $('#hint').classList.remove('show'); } // never unmute a local webcam: it would echo your own mic
  if (!document.fullscreenElement) toggleFullscreen();
});
video.addEventListener('dblclick', toggleFullscreen);

document.addEventListener('keydown', e => {
  if (e.target.tagName === 'INPUT') return;
  const k = e.key.toLowerCase();
  if (qrOpen) { if (k === 'escape') closeQr(); return; }
  if (k === 'f') toggleFullscreen();
  else if (k === 'm') toggleMute();
  else if (k === 'c') toggleFit();
  else if (k === 'r') rotateMore();
  else if (k === 'x') toggleMirror();
  else if (k === 'k') toggleClap();
  else if (k === 'd') setDrawer(!drawerOpen);
  else if (k === 'h') setHand(!handOn);
  else if (k === 't') setTrack(!showTrack);
  else if (k === 'w') startLocal();
  else if (k === 'y') ytToggleBox();
  else if (k === 'b') bubToggle();
  else if (k === 'i') showSetup();
  else if (k === 'escape' && searchOpen) closeSearch();
  else if (k === 'escape' && connected) $('#setup').classList.add('hidden');
});

let idleTimer;
document.addEventListener('mousemove', () => {
  $('#bar').classList.add('show'); document.body.classList.remove('idle');
  clearTimeout(idleTimer);
  idleTimer = setTimeout(() => { $('#bar').classList.remove('show'); document.body.classList.add('idle'); }, 2500);
});

// ---------- clap-clap search ----------
// Listens to the phone's mic (the audio track of the incoming stream). Two sharp claps within ~0.9s open the search bar.
const CLAP_MIN = 0.03; // minimum loudness (0..1) that counts as a clap: raise if it triggers too easily, lower if it misses claps
let clapOn = load('pc_clap') !== '0';
let actx = null, clapNode = null, clapAnalyser = null, clapTimer = null;
const searchBox = $('#search'), sq = $('#sq');
let searchOpen = false;

function resumeAudio() { if (actx && actx.state === 'suspended') actx.resume().catch(() => {}); }
['pointerdown', 'keydown'].forEach(ev => document.addEventListener(ev, resumeAudio, true));

function stopClap() {
  clearInterval(clapTimer); clapTimer = null;
  try { clapNode && clapNode.disconnect(); } catch (e) {}
  clapNode = clapAnalyser = null;
}

function startClap(stream) {
  stopClap();
  if (!clapOn || !stream || !stream.getAudioTracks().length) return;
  try {
    actx = actx || new (window.AudioContext || window.webkitAudioContext)();
    clapNode = actx.createMediaStreamSource(stream);
    clapAnalyser = actx.createAnalyser();
    clapAnalyser.fftSize = 1024;
    clapNode.connect(clapAnalyser); // analysis only: not routed to the speakers
  } catch (e) { console.warn('clap detection unavailable', e); return; }
  resumeAudio();

  const an = clapAnalyser, buf = new Float32Array(an.fftSize);
  let bg = 0.005, prev = 0, lastClap = 0, lastTrig = 0;
  clapTimer = setInterval(() => {
    an.getFloatTimeDomainData(buf);
    let sum = 0;
    for (let i = 0; i < buf.length; i++) sum += buf[i] * buf[i];
    const rms = Math.sqrt(sum / buf.length);
    const thr = Math.max(CLAP_MIN, bg * 6); // must stand out from the room noise
    const now = performance.now();
    if (rms > thr && prev < thr) { // rising edge = a sudden loud sound
      if (now - lastTrig > 1500) {
        const gap = now - lastClap;
        if (gap < 130) { /* echo / tail of the same clap */ }
        else if (gap < 900) { lastClap = 0; lastTrig = now; openSearch(); }
        else { lastClap = now; flashClap(); }
      }
    } else if (rms < thr) {
      bg = bg * 0.98 + rms * 0.02; // slowly learn the background level
    }
    prev = rms;
  }, 10);
}

function toggleClap() {
  clapOn = !clapOn; save('pc_clap', clapOn ? '1' : '0');
  $('#bClap').textContent = 'Clap search: ' + (clapOn ? 'on' : 'off') + ' (K)';
  if (clapOn) startClap(video.srcObject); else stopClap();
}
$('#bClap').textContent = 'Clap search: ' + (clapOn ? 'on' : 'off') + ' (K)';

let flashTimer;
function flashClap() {
  $('#clapFlash').classList.add('show'); clearTimeout(flashTimer);
  flashTimer = setTimeout(() => $('#clapFlash').classList.remove('show'), 700);
}

function openSearch() {
  searchOpen = true;
  document.body.classList.remove('idle');
  searchBox.classList.add('show');
  setTimeout(() => { sq.focus(); sq.select(); }, 30);
}
function closeSearch() {
  searchOpen = false;
  searchBox.classList.remove('show');
  sq.blur();
}
$('#sForm').addEventListener('submit', e => {
  e.preventDefault();
  const q = sq.value.trim();
  if (!q) return;
  window.open('https://www.google.com/search?q=' + encodeURIComponent(q), '_blank', 'noopener');
  sq.value = ''; closeSearch();
});
$('#sClose').addEventListener('click', closeSearch);
sq.addEventListener('keydown', e => { if (e.key === 'Escape') closeSearch(); });

// ---------- hand box ----------
const overlay = $('#overlay'), octx = overlay.getContext('2d');
const box = $('#handBox'), boxState = $('#boxState'), handMsg = $('#handMsg');
const off = document.createElement('canvas'), offCtx = off.getContext('2d');
const CONNECTIONS = [[0,1],[1,2],[2,3],[3,4],[0,5],[5,6],[6,7],[7,8],[5,9],[9,10],[10,11],[11,12],[9,13],[13,14],[14,15],[15,16],[13,17],[17,18],[18,19],[19,20],[0,17]];

let handOn = load('pc_hand') !== '0';
// showTrack only controls whether the skeleton/pinch ring is DRAWN. Tracking and all gestures keep working when it is off.
let showTrack = load('pc_track') !== '0';
function setTrack(v) {
  showTrack = v; save('pc_track', v ? '1' : '0');
  $('#bTrack').textContent = 'Hand overlay: ' + (v ? 'on' : 'off') + ' (T)';
  if (!v) octx.clearRect(0, 0, overlay.width, overlay.height);
}
let hands = null, loopRunning = false, gotResult = false;
// per-hand smoothing slots (a hand keeps its slot between frames so pinch state doesn't swap hands)
const slots = [null, null];
const GRACE = 180; // ms a pinch/grab survives a dropped detection frame
const HAND_MODEL = 0;  // 0 = lite (fastest, keeps up with quick movement), 1 = full (more accurate, slower). Try 1 if the hand looks unstable when still.
let procMs = 25;       // measured time per detection frame; used to predict where a fast hand will be
let boxX = 0, boxY = 0, grab = null, mouseDrag = null;
const pinchState = [false, false];

// drawer state
const DRAWER_W = 260, DOCK_X = 20, DOCK_Y = 112, DWELL = 700;
const HAND_PTS = [0, 4, 8, 12, 16, 20]; // wrist + fingertips: any of them in the corner counts as "hand on the corner"
let drawerOpen = false, docked = true, drawerAt = 0, lastFrame = 0;
const drawer = $('#drawer'), hotCorner = $('#hotCorner');
const SWIPE_WIN = 400, SWIPE_FRAC = 0.22; // push left by 22% of the screen width within 0.4s to close the drawer
const trails = [[], []];
const dwell = {}, pinchPrev = {}, latch = {};

function sizeOverlay() {
  overlay.width = innerWidth; overlay.height = innerHeight;
  if (docked) dockPos(); else { clampBox(); placeBox(); }
  ytLayout();
}
function clampBox() {
  boxX = Math.max(0, Math.min(innerWidth - box.offsetWidth, boxX));
  boxY = Math.max(0, Math.min(innerHeight - box.offsetHeight, boxY));
}
function placeBox() { box.style.left = boxX + 'px'; box.style.top = boxY + 'px'; updateDrop(); }
function dockPos() { boxX = DOCK_X; boxY = DOCK_Y; placeBox(); }
function updateDrop() {
  drawer.classList.toggle('drop', !docked && drawerOpen && (!!grab || !!mouseDrag) && boxX + box.offsetWidth / 2 < DRAWER_W);
  drawer.classList.toggle('drop2', drawerOpen && !yt.docked && !!yt.ptr && yt.ptr.x < DRAWER_W);
}
function updateBox() {
  box.style.display = handOn && !bubs.hand.on ? 'flex' : 'none';
  box.classList.toggle('docked', docked);
  box.classList.toggle('away', docked && !drawerOpen);
  ytUpdate();
}
function maybeDock() { // box released over the open drawer -> put it back in its slot
  if (!docked && drawerOpen && boxX + box.offsetWidth / 2 < DRAWER_W) { docked = true; updateBox(); dockPos(); }
  updateDrop();
}
function setDrawer(v) {
  drawerOpen = v; drawerAt = performance.now();
  drawer.classList.toggle('open', v);
  hotCorner.classList.toggle('hide', v);
  updateBox(); updateDrop();
}

// Push an open hand quickly to the left -> close the drawer.
function detectSwipe(hs) {
  const now = performance.now();
  const ok = drawerOpen && !grab && !mouseDrag && !ytBusy() && now - drawerAt > 900;
  for (let i = 0; i < trails.length; i++) {
    const h = hs[i], tr = trails[i];
    if (!h || !ok || h.pinching) { tr.length = 0; continue; }
    const c = { x: 0, y: 0 };
    [0, 5, 9, 13, 17].forEach(k => { c.x += h.pts[k].x / 5; c.y += h.pts[k].y / 5; }); // palm center
    tr.push({ t: now, x: c.x, y: c.y });
    while (tr.length && now - tr[0].t > SWIPE_WIN) tr.shift();
    const o = tr[0], dx = c.x - o.x, dy = c.y - o.y;
    if (now - o.t > 100 && dx < -innerWidth * SWIPE_FRAC && Math.abs(dy) < Math.abs(dx) * 0.7) {
      trails.forEach(t => { t.length = 0; });
      setDrawer(false);
      drawerAt = performance.now() + 600; // extra lockout so the hot corner can't instantly reopen it
      return;
    }
  }
}

// Hand "buttons": hold a hand/fingertip on the element (dwell) or pinch on it to activate.
function setP(el, v) { // only touch the DOM when the progress value really changed
  v = Math.round(v * 50) / 50;
  if (el._p === v) return;
  el._p = v; el.style.setProperty('--p', v);
}
function runTargets(hs, dt) {
  const free = !grab && !mouseDrag && !ytBusy();
  const idle = free && performance.now() - drawerAt > 900;
  const ytOn = free && !yt.docked && !bubs.yt.on;
  const defs = [
    { id: 'hotCorner', el: hotCorner, on: !drawerOpen && idle, hand: true, act: () => setDrawer(true) },
    { id: 'dClose', el: $('#dClose'), on: drawerOpen && idle, hand: false, act: () => setDrawer(false) },
    // YouTube box buttons: fingertip dwell or pinch
    { id: 'ytBack', el: $('#ytBack'), on: ytOn, hand: false, repeat: true, act: () => ytAct.back() },
    { id: 'ytToggle', el: $('#ytToggle'), on: ytOn, hand: false, act: () => ytAct.toggle() },
    { id: 'ytFwd', el: $('#ytFwd'), on: ytOn, hand: false, repeat: true, act: () => ytAct.fwd() },
    { id: 'ytMute', el: $('#ytMute'), on: ytOn, hand: false, act: () => ytAct.mute() },
    { id: 'ytAway', el: $('#ytAway'), on: ytOn, hand: false, pinchOnly: true, act: () => ytAct.away() },
  ];
  const rects = defs.map(t => t.on ? t.el.getBoundingClientRect() : null); // read everything first, write afterwards
  for (let di = 0; di < defs.length; di++) {
    const t = defs[di], k = t.id;
    let hit = false, pinch = false;
    if (t.on) {
      const r = rects[di], m = 10;
      const inR = p => p.x >= r.left - m && p.x <= r.right + m && p.y >= r.top - m && p.y <= r.bottom + m;
      hit = hs.some(h => t.hand ? HAND_PTS.some(i => inR(h.pts[i])) : inR(h.tip));
      pinch = hs.some(h => h.pinching && inR(h.pp));
    }
    if (!hit) latch[k] = false; // must leave the button before it can dwell-fire again
    const d0 = dwell[k] || 0;
    dwell[k] = hit && !t.pinchOnly && !latch[k] ? d0 + dt / DWELL : Math.max(0, d0 - dt / DWELL * 2);
    setP(t.el, Math.min(1, dwell[k]));
    const fire = t.on && (dwell[k] >= 1 || (pinch && !pinchPrev[k]));
    pinchPrev[k] = pinch;
    if (fire) { dwell[k] = t.repeat ? 0.55 : 0; latch[k] = !t.repeat && hit; setP(t.el, 0); t.act(); }
  }
}
addEventListener('resize', sizeOverlay);

function msg(t) { handMsg.textContent = t; handMsg.style.display = t ? 'block' : 'none'; }

function initHands() {
  if (hands) return true;
  if (!window.Hands) { msg('Hand tracking library failed to load (needs internet access)'); return false; }
  hands = new Hands({ locateFile: f => `https://cdn.jsdelivr.net/npm/@mediapipe/hands@0.4.1675469240/${f}` });
  hands.setOptions({ maxNumHands: 2, modelComplexity: HAND_MODEL, minDetectionConfidence: 0.4, minTrackingConfidence: 0.5 });
  hands.onResults(onHands);
  return true;
}

function setHand(on) {
  handOn = on; save('pc_hand', on ? '1' : '0');
  $('#bHand').textContent = 'Hand box: ' + (on ? 'on' : 'off') + ' (H)';
  document.body.classList.toggle('handoff', !on);
  updateBox();
  if (on) {
    if (!gotResult) msg('Loading hand tracking…');
    if (initHands()) handLoop();
  } else {
    msg(''); grab = null; ytReset(); octx.clearRect(0, 0, overlay.width, overlay.height);
  }
}

function nextVideoFrame() {
  return new Promise(res => {
    let done = false;
    const fin = () => { if (!done) { done = true; clearTimeout(to); res(); } };
    const to = setTimeout(fin, 80); // safety: never stall if frame callbacks stop
    if (video.requestVideoFrameCallback) video.requestVideoFrameCallback(fin);
    else setTimeout(fin, 0);
  });
}

async function handLoop() {
  if (loopRunning) return;
  loopRunning = true;
  while (handOn) {
    if (connected && video.videoWidth && video.readyState >= 2) {
      const d = effRot(), vw = video.videoWidth, vh = video.videoHeight;
      const sc = Math.min(1, 384 / Math.max(vw, vh)); // the model works at ~256px anyway; smaller copy = faster frames
      const dw = vw * sc, dh = vh * sc, swap = d % 180 !== 0;
      off.width = Math.round(swap ? dh : dw);
      off.height = Math.round(swap ? dw : dh);
      offCtx.save();
      offCtx.translate(off.width / 2, off.height / 2);
      if (mirror) offCtx.scale(-1, 1);
      offCtx.rotate(d * Math.PI / 180);
      offCtx.drawImage(video, -dw / 2, -dh / 2, dw, dh);
      offCtx.restore();
      const t0 = performance.now();
      try { await hands.send({ image: off }); procMs = procMs * 0.8 + (performance.now() - t0) * 0.2; } catch (e) { console.warn(e); await sleep(500); }
    } else {
      octx.clearRect(0, 0, overlay.width, overlay.height);
      await sleep(200);
    }
    await nextVideoFrame(); // start the next detection as soon as a NEW camera frame exists (no fixed 16ms wait)
  }
  loopRunning = false;
}

const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

function onHands(results) {
  if (!gotResult) { gotResult = true; msg(''); }
  octx.clearRect(0, 0, overlay.width, overlay.height);

  // Map normalized landmarks (in the rotated frame) to screen pixels, matching object-fit.
  const fw = off.width, fh = off.height, W = innerWidth, H = innerHeight;
  const fill = video.classList.contains('fill');
  const s = fill ? Math.max(W / fw, H / fh) : Math.min(W / fw, H / fh);
  const cw = fw * s, ch = fh * s, ox = (W - cw) / 2, oy = (H - ch) / 2;
  const map = p => ({ x: ox + p.x * cw, y: oy + p.y * ch });

  const tNow = performance.now();
  const list = (results.multiHandLandmarks || []).map(lm => lm.map(map));
  const hs = [];
  const used = new Set();
  // match each detected hand to the nearest previous slot (by wrist), else a free slot
  list.forEach(raw => {
    let si = -1, bd = 450;
    slots.forEach((s, k) => {
      if (!s || used.has(k)) return;
      const dtm = Math.min(300, tNow - s.t);
      const guess = { x: s.pts[0].x + s.vx * dtm, y: s.pts[0].y + s.vy * dtm }; // where a fast hand should be by now
      const d = Math.min(dist(s.pts[0], raw[0]), dist(guess, raw[0]));
      if (d < bd) { bd = d; si = k; }
    });
    if (si < 0) si = slots.findIndex((s, k) => !used.has(k) && (!s || tNow - s.t > GRACE));
    if (si < 0) return;
    used.add(si);
    let s = slots[si];
    if (!s || tNow - s.t > GRACE) s = slots[si] = { pts: raw.map(p => ({ x: p.x, y: p.y })), pinch: false, offFrames: 0, t: tNow, vx: 0, vy: 0 };
    else {
      // adaptive smoothing: steady hand -> heavy smoothing (no jitter), fast hand -> none (no lag)
      const dtm = Math.max(1, tNow - s.t);
      const sp = dist(s.pts[0], raw[0]);
      const a = sp < 4 ? 0.25 : sp < 30 ? 0.55 : sp < 70 ? 0.85 : 1;
      s.vx = s.vx * 0.4 + (raw[0].x - s.pts[0].x) / dtm * 0.6; // wrist velocity, px per ms
      s.vy = s.vy * 0.4 + (raw[0].y - s.pts[0].y) / dtm * 0.6;
      raw.forEach((p, j) => { s.pts[j].x += (p.x - s.pts[j].x) * a; s.pts[j].y += (p.y - s.pts[j].y) * a; });
      s.t = tNow;
    }
    // latency compensation: while the hand is moving fast, show it where it will be by the time this frame is on screen
    const lead = Math.min(procMs, 70), fast = Math.hypot(s.vx, s.vy) > 0.3;
    const ox = fast ? s.vx * lead : 0, oy = fast ? s.vy * lead : 0;
    const pts = s.pts.map(p => ({ x: p.x + ox, y: p.y + oy }));

    // pinch = thumb tip (4) close to index tip (8), relative to hand size (palm length + width)
    const size = (dist(pts[0], pts[9]) + dist(pts[5], pts[17])) / 2 || 1;
    const ratio = dist(pts[4], pts[8]) / size;
    if (!s.pinch) { if (ratio < 0.36) { s.pinch = true; s.offFrames = 0; } }
    else if (ratio > 0.6) { if (++s.offFrames >= 2) s.pinch = false; } // need 2 open frames to release: no accidental drops
    else s.offFrames = 0;
    const pinching = s.pinch;
    const pp = { x: (pts[4].x + pts[8].x) / 2, y: (pts[4].y + pts[8].y) / 2 };

    if (showTrack) {
      octx.lineWidth = 3; octx.strokeStyle = 'rgba(90,160,255,.9)';
      CONNECTIONS.forEach(([a, b]) => { octx.beginPath(); octx.moveTo(pts[a].x, pts[a].y); octx.lineTo(pts[b].x, pts[b].y); octx.stroke(); });
      octx.fillStyle = '#fff';
      pts.forEach(p => { octx.beginPath(); octx.arc(p.x, p.y, 3.5, 0, 6.3); octx.fill(); });
      octx.beginPath(); octx.arc(pp.x, pp.y, pinching ? 14 : 8, 0, 6.3);
      octx.strokeStyle = pinching ? '#3ecf8e' : 'rgba(255,255,255,.6)'; octx.lineWidth = 3; octx.stroke();
    }
    hs.push({ tip: pts[8], pp, pinching, pts });
  });
  // a pinching hand that vanished for a moment (motion blur) is kept alive, drifting along its last velocity
  slots.forEach((s, k) => {
    if (!s || used.has(k)) return;
    const gap = tNow - s.t;
    if (gap > GRACE) { slots[k] = null; return; }
    if (!s.pinch) return;
    const pts = s.pts.map(p => ({ x: p.x + s.vx * gap, y: p.y + s.vy * gap }));
    hs.push({ tip: pts[8], pp: { x: (pts[4].x + pts[8].x) / 2, y: (pts[4].y + pts[8].y) / 2 }, pinching: true, pts });
  });

  const nowT = performance.now(), dt = Math.min(100, nowT - lastFrame); lastFrame = nowT;
  if (handOn && prayerStep(hs, nowT)) hs.forEach(h => { h.pinching = false; }); // palms together: nothing is being pinched
  if (window.scopeStep) scopeStep(hs, nowT); // telescope gesture + voice-search window (see browser.php)
  detectSwipe(hs);
  runTargets(hs, dt);
  bubInteract(hs);

  // interaction with the box (only while it is visible: floating, or docked in an open drawer)
  const boxLive = !(docked && !drawerOpen) && !bubs.hand.on;
  const r = box.getBoundingClientRect();
  const inBox = (p, m) => p.x >= r.left - m && p.x <= r.right + m && p.y >= r.top - m && p.y <= r.bottom + m;
  const hover = boxLive && hs.some(h => inBox(h.tip, 10));
  const pinchers = hs.filter(h => h.pinching && !bubHeld(h));

  if (!mouseDrag) {
    if (grab) {
      let best = null, bd = 1e9;
      pinchers.forEach(h => { const d = dist(h.pp, grab.last); if (d < bd && d < 450) { bd = d; best = h; } });
      if (best) {
        grab.sx += (best.pp.x - grab.sx) * 0.85;
        grab.sy += (best.pp.y - grab.sy) * 0.85;
        boxX = grab.sx - grab.dx; boxY = grab.sy - grab.dy;
        clampBox(); placeBox();
        grab.last = best.pp;
        grab.lost = 0;
      } else {
        // tolerate a brief dropped detection so the box doesn't fall out of the hand
        if (!grab.lost) grab.lost = performance.now();
        if (performance.now() - grab.lost > GRACE) { grab = null; maybeDock(); }
      }
    } else {
      const h = boxLive && pinchers.find(h => inBox(h.pp, 15));
      if (h) {
        docked = false; updateBox();
        grab = { dx: h.pp.x - boxX, dy: h.pp.y - boxY, sx: h.pp.x, sy: h.pp.y, last: h.pp };
      }
    }
  }
  box.classList.toggle('hover', (hover || !!grab) && !mouseDrag);
  box.classList.toggle('grab', !!grab || !!mouseDrag);
  boxState.textContent = grab || mouseDrag ? 'Holding — release to drop'
    : hover ? 'Hand detected — pinch to grab'
    : 'Pinch thumb + index over me, then move to drag';
  ytInteract(hs);
  const heldNow = (grab || mouseDrag) ? 'hand' : ytBusy() ? 'yt' : null; // remembers which tool was last in your hands
  if (heldNow && !bubs[heldNow].on) lastHeld = { id: heldNow, t: performance.now() };
}

// mouse / touch fallback: drag the box directly too
box.addEventListener('pointerdown', e => {
  docked = false; updateBox();
  mouseDrag = { dx: e.clientX - boxX, dy: e.clientY - boxY };
  box.setPointerCapture(e.pointerId); box.classList.add('grab'); e.stopPropagation();
});
box.addEventListener('pointermove', e => {
  if (!mouseDrag) return;
  boxX = e.clientX - mouseDrag.dx; boxY = e.clientY - mouseDrag.dy; clampBox(); placeBox();
});
const endDrag = () => { mouseDrag = null; if (!grab) box.classList.remove('grab'); maybeDock(); };
box.addEventListener('pointerup', endDrag);
box.addEventListener('pointercancel', endDrag);

// ---------- YouTube box (second drawer tool) ----------
// Pinch it out of the drawer. Pinch + drag = move. Two hands pinching at opposite corners = resize.
// Two hands pinching away from the box = "sign" a rectangle: the player appears in it. YouTube's own buttons live in a
// cross-origin iframe and cannot be pressed by script, so the player runs with controls off and these big buttons drive it
// through the YouTube IFrame API (fingertip dwell or pinch, or the mouse).
const YT_MIN_W = 340, YT_MIN_H = 250, YT_NEAR = 90, DOCK2_Y = 276;
const yt = { x: DOCK_X, y: DOCK2_Y, w: 220, h: 150, fw: 520, fh: 360, docked: true,
  grab: null, resize: null, draw: null, scrub: null, mouse: null, ptr: null, lock: 0, pairSince: 0, scrubbing: false };
const ytBox = $('#ytBox'), ytIn = $('#ytIn'), ytSeek = $('#ytSeek'), ytFill = $('#ytFill'), ytTimeEl = $('#ytTime'), ytMsg = $('#ytMsg');
const ytCtlEls = [...document.querySelectorAll('#ytBox .ytb, #ytIn, #ytGo')];
let ytPlayer = null, ytReady = false, ytApiP = null, ytGuard = null, ytMsgTimer = null;
const ytBusy = () => !!(yt.grab || yt.resize || yt.draw || yt.scrub || yt.mouse);
const YT_ERR = { 2: 'That link has an invalid video ID.', 5: 'The player hit an HTML5 error.', 100: 'Video not found or private.',
  101: 'The owner does not allow this video to be embedded.', 150: 'The owner does not allow this video to be embedded.',
  153: 'YouTube could not verify this page (embedding needs https and a referrer).' };

ytIn.value = load('pc_yt_url') || '';

function ytSecs(t) {
  if (!t) return 0;
  if (/^\d+$/.test(t)) return +t;
  const m = t.match(/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/);
  return m ? (+m[1] || 0) * 3600 + (+m[2] || 0) * 60 + (+m[3] || 0) : 0;
}
// Accepts watch?v=, youtu.be/, shorts/, embed/, live/, music.youtube.com, playlist?list=, a bare 11-char ID, and ?t=90 / ?t=1m30s.
function ytParse(s) {
  s = (s || '').trim();
  if (!s) return null;
  if (/^[\w-]{11}$/.test(s)) return { v: s, list: null, start: 0 };
  let u;
  try { u = new URL(/^https?:\/\//i.test(s) ? s : 'https://' + s); } catch (e) { return null; }
  const host = u.hostname.toLowerCase().replace(/^(www|m|music)\./, '');
  const id = x => (x && /^[\w-]{11}/.test(x)) ? x.slice(0, 11) : null;
  let v = null;
  if (host === 'youtu.be') v = id(u.pathname.slice(1));
  else if (host === 'youtube.com' || host === 'youtube-nocookie.com') {
    if (u.pathname === '/watch') v = id(u.searchParams.get('v'));
    else { const m = u.pathname.match(/^\/(?:embed|shorts|live|v)\/([\w-]{11})/); if (m) v = m[1]; }
  } else return null;
  const l = u.searchParams.get('list') || '';
  const list = !v && /^[\w-]{10,}$/.test(l) ? l : null; // a playlist only plays when the link has no single video
  if (!v && !list) return null;
  return { v, list, start: ytSecs(u.searchParams.get('t') || u.searchParams.get('start')) };
}

function ytShowMsg(t, ms) {
  clearTimeout(ytMsgTimer); ytMsgTimer = null;
  ytMsg.textContent = t || ''; ytMsg.style.display = t ? 'flex' : 'none';
  if (t && ms) ytMsgTimer = setTimeout(() => { ytMsgTimer = null; ytMsg.style.display = 'none'; }, ms);
}
function ytLoadApi() {
  if (window.YT && YT.Player) return Promise.resolve();
  if (ytApiP) return ytApiP;
  ytApiP = new Promise((res, rej) => {
    const prev = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = () => { if (prev) prev(); res(); };
    const s = document.createElement('script');
    s.src = 'https://www.youtube.com/iframe_api';
    s.onerror = () => { ytApiP = null; rej(new Error('load')); };
    document.head.appendChild(s);
  });
  return ytApiP;
}
async function ytPlay(p) {
  ytShowMsg('Loading…');
  try { await ytLoadApi(); } catch (e) { ytShowMsg('Could not load the YouTube player (needs internet access).'); return; }
  if (!ytPlayer) {
    await new Promise(res => {
      const vars = { controls: 0, rel: 0, playsinline: 1, disablekb: 1, modestbranding: 1, fs: 0, iv_load_policy: 3, autoplay: 1 };
      if (p.list) { vars.listType = 'playlist'; vars.list = p.list; } else if (p.start) vars.start = p.start;
      ytPlayer = new YT.Player('ytPlayer', {
        width: '100%', height: '100%', videoId: p.list ? undefined : p.v, playerVars: vars,
        events: {
          onReady: () => { ytReady = true; res(); try { ytPlayer.playVideo(); } catch (e) {} },
          onStateChange: e => { if (e.data === 1 && !ytMsgTimer) ytShowMsg(''); },
          onError: e => ytShowMsg(YT_ERR[e.data] || 'The video could not be played (error ' + e.data + ').'),
        },
      });
    });
  } else if (p.list) ytPlayer.loadPlaylist({ listType: 'playlist', list: p.list, index: 0 });
  else ytPlayer.loadVideoById({ videoId: p.v, startSeconds: p.start || 0 });
  // Browsers can block autoplay with sound: if nothing started, fall back to muted autoplay.
  clearTimeout(ytGuard);
  ytGuard = setTimeout(() => {
    try {
      const s = ytPlayer.getPlayerState();
      if (s === -1 || s === 5) { ytPlayer.mute(); ytPlayer.playVideo(); ytShowMsg('Autoplay with sound was blocked, so it started muted. Press the speaker button to unmute.', 4500); }
    } catch (e) {}
  }, 2000);
}
function ytSubmit() {
  const p = ytParse(ytIn.value);
  if (!p) { ytShowMsg('That does not look like a YouTube link.', 3000); return; }
  save('pc_yt_url', ytIn.value.trim());
  ytIn.blur();
  ytPlay(p);
}

// buttons
const ytIcon = (id, t) => { $('#' + id).firstElementChild.innerHTML = ICONS[t] || t; };
const ytAct = {
  back() { if (ytReady) ytPlayer.seekTo(Math.max(0, ytPlayer.getCurrentTime() - 10), true); },
  fwd() { if (ytReady) ytPlayer.seekTo(ytPlayer.getCurrentTime() + 10, true); },
  toggle() {
    if (!ytReady) { if (ytIn.value.trim()) ytSubmit(); return; }
    const s = ytPlayer.getPlayerState();
    if (s === 1 || s === 3) ytPlayer.pauseVideo(); else ytPlayer.playVideo();
  },
  mute() {
    if (!ytReady) return;
    if (ytPlayer.isMuted()) ytPlayer.unMute(); else ytPlayer.mute();
    ytIcon('ytMute', ytPlayer.isMuted() ? 'mute' : 'vol');
  },
  away() { ytDock(); },
};
[['ytBack', 'back'], ['ytToggle', 'toggle'], ['ytFwd', 'fwd'], ['ytMute', 'mute'], ['ytAway', 'away']]
  .forEach(([id, a]) => { $('#' + id).addEventListener('click', e => { e.stopPropagation(); ytAct[a](); }); });
$('#ytGo').addEventListener('click', e => { e.stopPropagation(); ytSubmit(); });
ytIn.addEventListener('keydown', e => { if (e.key === 'Enter') ytSubmit(); else if (e.key === 'Escape') ytIn.blur(); e.stopPropagation(); });

const ytFmt = s => { s = Math.floor(s); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
  return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(x).padStart(2, '0'); };
setInterval(() => {
  if (!ytReady || yt.docked) return;
  try {
    const cur = ytPlayer.getCurrentTime() || 0, dur = ytPlayer.getDuration() || 0, s = ytPlayer.getPlayerState();
    if (!yt.scrubbing) ytFill.style.width = (dur ? Math.min(100, cur / dur * 100) : 0) + '%';
    ytTimeEl.textContent = ytFmt(cur) + (dur ? ' / ' + ytFmt(dur) : '');
    ytIcon('ytToggle', s === 1 || s === 3 ? 'pause' : 'play');
    ytIcon('ytMute', ytPlayer.isMuted() ? 'mute' : 'vol');
  } catch (e) {}
}, 250);

function ytScrubTo(x, commit) {
  const r = ytSeek.getBoundingClientRect();
  const f = Math.max(0, Math.min(1, (x - r.left) / r.width));
  yt.scrubbing = !commit;
  ytFill.style.width = f * 100 + '%';
  if (commit && ytReady) { try { const d = ytPlayer.getDuration(); if (d) ytPlayer.seekTo(f * d, true); } catch (e) {} }
}
ytSeek.addEventListener('pointerdown', e => { e.stopPropagation(); ytSeek.setPointerCapture(e.pointerId); ytScrubTo(e.clientX, false); });
ytSeek.addEventListener('pointermove', e => { if (ytSeek.hasPointerCapture(e.pointerId)) ytScrubTo(e.clientX, false); });
ytSeek.addEventListener('pointerup', e => { if (ytSeek.hasPointerCapture(e.pointerId)) ytScrubTo(e.clientX, true); });

// geometry / docking
function ytClamp() {
  yt.w = Math.max(YT_MIN_W, Math.min(innerWidth, yt.w));
  yt.h = Math.max(YT_MIN_H, Math.min(innerHeight, yt.h));
  yt.x = Math.max(0, Math.min(innerWidth - yt.w, yt.x));
  yt.y = Math.max(0, Math.min(innerHeight - yt.h, yt.y));
}
function ytLayout() {
  if (yt.docked) { yt.x = DOCK_X; yt.y = DOCK2_Y; yt.w = 220; yt.h = 150; }
  else { ytClamp(); yt.fw = yt.w; yt.fh = yt.h; }
  const s = ytBox.style;
  s.left = yt.x + 'px'; s.top = yt.y + 'px';
  if (yt._w !== yt.w || yt._h !== yt.h) { yt._w = yt.w; yt._h = yt.h; s.width = yt.w + 'px'; s.height = yt.h + 'px'; }
  updateDrop();
}
function ytUpdate() {
  ytBox.classList.toggle('docked', yt.docked);
  ytBox.classList.toggle('away', yt.docked && !drawerOpen);
  ytBox.classList.toggle('gone', (yt.docked && !handOn) || bubs.yt.on);
  ytLayout();
}
function ytUndock() { if (yt.docked) { yt.docked = false; yt.w = yt.fw; yt.h = yt.fh; ytUpdate(); } }
function ytReset() {
  yt.grab = yt.resize = yt.draw = yt.scrub = yt.mouse = null; yt.ptr = null; yt.pairSince = 0; yt.scrubbing = false;
  ytBox.classList.remove('hover', 'grab', 'resizing');
  updateDrop();
}
function ytDock() {
  if (ytReady) { try { ytPlayer.pauseVideo(); } catch (e) {} }
  yt.docked = true; ytReset(); ytUpdate();
}
function ytRelease() { // released over the open drawer -> put it away
  const over = !yt.docked && drawerOpen && yt.ptr && yt.ptr.x < DRAWER_W;
  yt.ptr = null; ytBox.classList.remove('grab');
  if (over) ytDock(); else updateDrop();
}
function ytSummon() {
  if (!yt.docked) return;
  ytUndock();
  yt.x = Math.round((innerWidth - yt.w) / 2); yt.y = Math.round((innerHeight - yt.h) / 2);
  ytLayout();
  setTimeout(() => ytIn.focus(), 50);
}
function ytToggleBox() { if (yt.docked) ytSummon(); else ytDock(); }
$('#bYt').onclick = e => { e.stopPropagation(); ytToggleBox(); };

// pasting a YouTube link anywhere (outside a text field) opens the box and plays it
document.addEventListener('paste', e => {
  if (e.target.tagName === 'INPUT') return;
  const t = (e.clipboardData && e.clipboardData.getData('text')) || '';
  if (!ytParse(t)) return;
  ytSummon(); ytIn.value = t.trim(); ytSubmit();
});

// two-hand resize / rectangle
function ytStartResize(a, b) {
  const cs = { tl: { x: yt.x, y: yt.y }, tr: { x: yt.x + yt.w, y: yt.y }, bl: { x: yt.x, y: yt.y + yt.h }, br: { x: yt.x + yt.w, y: yt.y + yt.h } };
  let best = null, bs = 1e9;
  [['tl', 'br'], ['br', 'tl'], ['tr', 'bl'], ['bl', 'tr']].forEach(([ca, cb]) => { // either diagonal, either hand on either corner
    const s = dist(a, cs[ca]) + dist(b, cs[cb]);
    if (s < bs) { bs = s; best = [ca, cb]; }
  });
  yt.grab = null; yt.ptr = null;
  yt.resize = { a, b, oa: { x: cs[best[0]].x - a.x, y: cs[best[0]].y - a.y }, ob: { x: cs[best[1]].x - b.x, y: cs[best[1]].y - b.y } };
}
function ytResizeTo(a, b) {
  const R = yt.resize;
  const ax = a.x + R.oa.x, ay = a.y + R.oa.y, bx = b.x + R.ob.x, by = b.y + R.ob.y;
  yt.x = Math.min(ax, bx); yt.y = Math.min(ay, by);
  yt.w = Math.abs(ax - bx); yt.h = Math.abs(ay - by);
  ytLayout();
}
function ytCommitDraw(a, b) {
  const w = Math.abs(a.x - b.x), h = Math.abs(a.y - b.y);
  if (w < 140 || h < 90) return; // too small: ignore
  ytUndock();
  yt.x = Math.min(a.x, b.x); yt.y = Math.min(a.y, b.y); yt.w = w; yt.h = h;
  ytLayout();
}
function ytDrawPreview(a, b) {
  const x = Math.min(a.x, b.x), y0 = Math.min(a.y, b.y), w = Math.abs(a.x - b.x), h = Math.abs(a.y - b.y);
  octx.save(); octx.setLineDash([10, 7]); octx.lineWidth = 3; octx.strokeStyle = '#ffb020'; octx.fillStyle = 'rgba(255,176,32,.14)';
  octx.fillRect(x, y0, w, h); octx.strokeRect(x, y0, w, h);
  octx.font = '14px system-ui, sans-serif'; octx.fillStyle = '#fff'; octx.fillText('Release to place the player here', x + 8, y0 + 22);
  octx.restore();
}
function ytOnSeek(p) {
  const r = ytSeek.getBoundingClientRect();
  return p.x >= r.left - 14 && p.x <= r.right + 14 && p.y >= r.top - 16 && p.y <= r.bottom + 16;
}
function ytOnCtl(p) {
  return ytCtlEls.some(el => { const r = el.getBoundingClientRect(); return p.x >= r.left - 6 && p.x <= r.right + 6 && p.y >= r.top - 6 && p.y <= r.bottom + 6; });
}

// called once per hand-tracking frame (after the hand box logic)
function ytInteract(hs) {
  const now = performance.now();
  if (bubs.yt.on || (yt.docked && !drawerOpen)) { if (ytBusy()) ytReset(); ytBox.classList.remove('hover', 'grab', 'resizing'); return; }
  const r = ytBox.getBoundingClientRect();
  const near = (p, m) => p.x >= r.left - m && p.x <= r.right + m && p.y >= r.top - m && p.y <= r.bottom + m;
  // pinch points, minus the hand that is holding the hand box
  const pts = hs.filter(h => h.pinching && !(grab && h.pp === grab.last) && !bubHeld(h)).map(h => h.pp);
  const track = (last, max = 450, not = null) => {
    let b = null, bd = max;
    pts.forEach(p => { if (p === not) return; const d = dist(p, last); if (d < bd) { bd = d; b = p; } });
    return b;
  };
  const hover = hs.some(h => near(h.tip, 10));

  if (yt.resize) {
    const a = track(yt.resize.a), b = a && track(yt.resize.b, 300, a);
    if (a && b) { yt.resize.a = a; yt.resize.b = b; ytResizeTo(a, b); }
    else { yt.resize = null; yt.lock = now + 500; }
  } else if (yt.draw) {
    const a = track(yt.draw.a), b = a && track(yt.draw.b, 300, a);
    if (a && b) { yt.draw.a = a; yt.draw.b = b; }
    else { ytCommitDraw(yt.draw.a, yt.draw.b); yt.draw = null; yt.lock = now + 500; }
  } else if (yt.scrub) {
    const p = track(yt.scrub.last, 200);
    if (p) { yt.scrub.last = p; ytScrubTo(p.x, false); }
    else { ytScrubTo(yt.scrub.last.x, true); yt.scrub = null; yt.lock = now + 300; }
  } else if (yt.grab) {
    const p = track(yt.grab.last);
    if (p) {
      const nearCorner = o => [[r.left, r.top], [r.right, r.top], [r.left, r.bottom], [r.right, r.bottom]].some(c => Math.hypot(o.x - c[0], o.y - c[1]) < YT_NEAR);
      const q = !yt.docked && pts.find(o => o !== p && nearCorner(o));
      if (!q) yt.rzWant = 0; else if (!yt.rzWant) yt.rzWant = now;
      if (q && now - yt.rzWant > 300) { yt.rzWant = 0; ytStartResize(p, q); } // second hand held on a corner -> resize
      else { // otherwise it is just dragging: the size never changes
        const g = yt.grab;
        g.sx += (p.x - g.sx) * 0.85; g.sy += (p.y - g.sy) * 0.85;
        yt.x = g.sx - g.dx; yt.y = g.sy - g.dy; yt.ptr = { x: p.x, y: p.y };
        ytLayout(); g.last = p;
      }
    } else { yt.grab = null; ytRelease(); }
  } else if (now > yt.lock && !yt.mouse) {
    let started = false, pairing = false;
    if (pts.length >= 2) {
      const a = pts[0], b = pts[1], nA = near(a, YT_NEAR), nB = near(b, YT_NEAR);
      if (!yt.docked && nA && nB) { ytStartResize(a, b); started = true; }
      else if (!nA && !nB && dist(a, b) > 80) { // two hands pinching away from the box: sign a rectangle
        pairing = true;
        if (!yt.pairSince) yt.pairSince = now;
        else if (now - yt.pairSince > 250) { yt.draw = { a, b }; yt.pairSince = 0; started = true; }
      }
    }
    if (!pairing) yt.pairSince = 0;
    if (!started && !pairing) {
      for (const p of pts) {
        if (!yt.docked && ytOnSeek(p)) { yt.scrub = { last: p }; ytScrubTo(p.x, false); break; }
        if (near(p, 15) && (yt.docked || !ytOnCtl(p))) { // pinching a button is handled by runTargets, not a grab
          if (yt.docked) ytUndock();
          yt.grab = { dx: p.x - yt.x, dy: p.y - yt.y, sx: p.x, sy: p.y, last: p };
          yt.ptr = { x: p.x, y: p.y };
          break;
        }
      }
    }
  }
  if (yt.draw) ytDrawPreview(yt.draw.a, yt.draw.b);
  ytBox.classList.toggle('hover', hover && !ytBusy());
  ytBox.classList.toggle('grab', !!yt.grab);
  ytBox.classList.toggle('resizing', !!yt.resize);
}

// mouse / touch fallback: drag by the bar, tile or video; click the video to play/pause; corner handle resizes
ytBox.addEventListener('pointerdown', e => {
  if (e.target.closest('button, input, #ytSeek')) return;
  e.stopPropagation();
  if (e.target.closest('.ytRz')) yt.mouse = { mode: 'rz', sx: e.clientX, sy: e.clientY, w: yt.w, h: yt.h };
  else {
    ytUndock();
    yt.mouse = { mode: 'move', dx: e.clientX - yt.x, dy: e.clientY - yt.y, sx: e.clientX, sy: e.clientY, moved: false, shield: !!e.target.closest('.ytShield') };
    ytBox.classList.add('grab');
  }
  ytBox.setPointerCapture(e.pointerId);
});
ytBox.addEventListener('pointermove', e => {
  const m = yt.mouse; if (!m) return;
  if (m.mode === 'rz') { yt.w = m.w + e.clientX - m.sx; yt.h = m.h + e.clientY - m.sy; ytLayout(); return; }
  if (Math.hypot(e.clientX - m.sx, e.clientY - m.sy) > 5) m.moved = true;
  yt.x = e.clientX - m.dx; yt.y = e.clientY - m.dy; yt.ptr = { x: e.clientX, y: e.clientY };
  ytLayout();
});
const ytEnd = () => {
  const m = yt.mouse; if (!m) return;
  yt.mouse = null;
  if (m.mode === 'move') { if (m.shield && !m.moved) ytAct.toggle(); ytRelease(); }
};
ytBox.addEventListener('pointerup', ytEnd);
ytBox.addEventListener('pointercancel', ytEnd);


// ---------- minimize to circles (prayer hands) / open (reverse clap) ----------
// 1) Hold a tool (pinch it), then bring both open hands together like in a prayer: the tool you were holding shrinks into a circle.
// 2) Circles are draggable (pinch or mouse) and snap to the top-right / bottom-right corner when you let go.
// 3) To open a circle: put your hands together again, then pull them apart like opening a book (reverse clap).
// Mouse fallback: click a circle to open it, press B to minimize / restore.
const BUB_D = 64, BUB_M = 14, BUB_GAP = 10, HELD_WINDOW = 2500, PRAY_HOLD = 200, OPEN_APART = 0.8;
const bubs = {
  hand: { id: 'hand', icon: ICONS.hand, title: 'Hand box: click to open (or reverse-clap)', on: false, side: 't', order: 0, at: 0, x: 0, y: 0, hdrag: null, mdrag: null, el: null },
  yt:   { id: 'yt',   icon: ICONS.yt,  title: 'YouTube box: click to open (or reverse-clap)', on: false, side: 't', order: 0, at: 0, x: 0, y: 0, hdrag: null, mdrag: null, el: null },
};
let bubSeq = 0, lastHeld = null, lastPicked = null, bubLock = 0, toastT = 0;
const PICK_WINDOW = 5000; // ms: a circle you picked up stays "chosen" this long, so the frame sign opens IT (not just the newest circle)
const pray = { since: 0, last: 0, nis: 0, armed: false, min: 0, needRelease: false };

function toast(t) {
  handMsg.textContent = t; handMsg.style.display = 'block';
  clearTimeout(toastT); toastT = setTimeout(() => { handMsg.style.display = 'none'; }, 2200);
}
const bubBusy = b => !!(b.hdrag || b.mdrag);
const bubHeld = h => Object.values(bubs).some(b => b.hdrag && b.hdrag.last === h.pp);
function bubPlace(b) { b.el.style.transform = 'translate3d(' + Math.round(b.x) + 'px,' + Math.round(b.y) + 'px,0)'; }

// circles dock in a column on the right edge: the top-right corner stacks downwards, the bottom-right corner stacks upwards
function bubLayout() {
  ['t', 'b'].forEach(side => {
    Object.values(bubs).filter(b => b.on && !bubBusy(b) && b.side === side).sort((a, b) => a.order - b.order).forEach((b, i) => {
      b.x = innerWidth - BUB_D - BUB_M;
      b.y = side === 't' ? BUB_M + i * (BUB_D + BUB_GAP) : innerHeight - BUB_D - BUB_M - i * (BUB_D + BUB_GAP);
      bubPlace(b);
    });
  });
}
function bubDrop(b) { // let go: magnet to the nearest right corner
  b.hdrag = b.mdrag = null; b.el.classList.remove('drag');
  b.side = b.y + BUB_D / 2 < innerHeight / 2 ? 't' : 'b';
  b.order = ++bubSeq;
  bubLayout();
}

function bubCollapse(id) {
  const b = bubs[id];
  if (b.on) return false;
  let cx, cy;
  if (b.ext) { const c = b.ext.collapse(); if (!c) return false; cx = c.x; cy = c.y; } // external tool (voice search)
  else if (id === 'hand') {
    if (docked || !handOn) return false;
    cx = boxX + box.offsetWidth / 2; cy = boxY + box.offsetHeight / 2;
    grab = null; mouseDrag = null; box.classList.remove('grab', 'hover');
  } else {
    if (yt.docked) return false;
    cx = yt.x + yt.w / 2; cy = yt.y + yt.h / 2;
    ytReset(); // the video keeps playing (audio too) while it is a circle
  }
  b.on = true; b.hdrag = b.mdrag = null; b.at = performance.now();
  b.side = cy < innerHeight / 2 ? 't' : 'b'; b.order = ++bubSeq;
  b.el.classList.add('noanim'); b.el.style.display = 'flex';
  b.x = cx - BUB_D / 2; b.y = cy - BUB_D / 2; bubPlace(b);
  void b.el.offsetWidth; b.el.classList.remove('noanim'); // next layout animates from the tool's old spot into the corner
  updateBox();
  bubLayout();
  return true;
}
function bubOpen(id, rect) { // rect = the frame the hands are making: the tool opens at exactly that size and place
  const b = bubs[id];
  if (!b.on) return false;
  b.on = false; b.hdrag = b.mdrag = null; b.el.style.display = 'none'; b.el.classList.remove('drag');
  const el = b.ext ? b.ext.el() : id === 'hand' ? box : ytBox;
  if (b.ext) b.ext.open(rect);
  else if (id === 'hand') {
    docked = false;
    if (rect) { box.style.width = Math.max(120, Math.round(rect.w)) + 'px'; box.style.height = Math.max(80, Math.round(rect.h)) + 'px'; }
    updateBox();
    const cx = rect ? rect.x + rect.w / 2 : innerWidth / 2, cy = rect ? rect.y + rect.h / 2 : innerHeight / 2;
    boxX = Math.round(cx - box.offsetWidth / 2); boxY = Math.round(cy - box.offsetHeight / 2); clampBox(); placeBox();
  } else {
    yt.docked = false; ytUpdate();
    const cx = rect ? rect.x + rect.w / 2 : innerWidth / 2, cy = rect ? rect.y + rect.h / 2 : innerHeight / 2;
    if (rect) { yt.w = Math.max(YT_MIN_W, rect.w); yt.h = Math.max(YT_MIN_H, rect.h); }
    yt.x = Math.round(cx - yt.w / 2); yt.y = Math.round(cy - yt.h / 2); ytLayout();
  }
  el.classList.remove('popin'); void el.offsetWidth; el.classList.add('popin');
  setTimeout(() => el.classList.remove('popin'), 400);
  bubLayout();
  return true;
}
function bubToggle() { // keyboard B
  const on = Object.values(bubs).filter(b => b.on).sort((a, b) => b.at - a.at)[0];
  if (on) { bubOpen(on.id); return; }
  const order = lastHeld ? [lastHeld.id, lastHeld.id === 'hand' ? 'yt' : 'hand'] : ['hand', 'yt'];
  for (const id of order) if (bubCollapse(id)) return;
}

// build the circle elements + mouse / touch handling
const bubMake = b => {
  const e = document.createElement('div');
  e.className = 'bubble ' + b.id; e.innerHTML = b.icon; e.title = b.title;
  document.body.appendChild(e); b.el = e;
  e.addEventListener('pointerdown', ev => {
    ev.stopPropagation();
    b.mdrag = { dx: ev.clientX - b.x, dy: ev.clientY - b.y, sx: ev.clientX, sy: ev.clientY, moved: false };
    e.classList.add('drag'); e.setPointerCapture(ev.pointerId);
  });
  e.addEventListener('pointermove', ev => {
    const m = b.mdrag; if (!m) return;
    if (Math.hypot(ev.clientX - m.sx, ev.clientY - m.sy) > 5) m.moved = true;
    b.x = Math.max(0, Math.min(innerWidth - BUB_D, ev.clientX - m.dx));
    b.y = Math.max(0, Math.min(innerHeight - BUB_D, ev.clientY - m.dy));
    bubPlace(b);
  });
  const end = () => { const m = b.mdrag; if (!m) return; if (m.moved) bubDrop(b); else { b.mdrag = null; e.classList.remove('drag'); bubOpen(b.id); } };
  e.addEventListener('pointerup', end);
  e.addEventListener('pointercancel', () => { if (b.mdrag) bubDrop(b); });
};
Object.values(bubs).forEach(bubMake);
addEventListener('resize', bubLayout);

// pinch a circle with your fingers to drag it; release to snap to the corner
function bubInteract(hs) {
  const now = performance.now();
  const pinchers = hs.filter(h => h.pinching);
  for (const b of Object.values(bubs)) {
    if (!b.on || b.mdrag) continue;
    if (b.hdrag) {
      const d = b.hdrag; let best = null, bd = 450;
      pinchers.forEach(h => { const k = dist(h.pp, d.last); if (k < bd) { bd = k; best = h; } });
      if (best) {
        d.last = best.pp; d.lost = 0; lastPicked = { id: b.id, t: now };
        b.x = Math.max(0, Math.min(innerWidth - BUB_D, best.pp.x - d.dx));
        b.y = Math.max(0, Math.min(innerHeight - BUB_D, best.pp.y - d.dy));
        bubPlace(b);
      } else {
        if (!d.lost) d.lost = now;
        if (now - d.lost > GRACE) bubDrop(b);
      }
    } else if (now > bubLock && !grab && !mouseDrag && !ytBusy()) {
      const h = pinchers.find(h => !bubHeld(h) && Math.hypot(h.pp.x - (b.x + BUB_D / 2), h.pp.y - (b.y + BUB_D / 2)) < BUB_D / 2 + 16);
      if (h) { b.hdrag = { dx: h.pp.x - b.x, dy: h.pp.y - b.y, last: h.pp, lost: 0 }; b.el.classList.add('drag'); lastPicked = { id: b.id, t: now }; }
    }
  }
}

// frame sign: two L-shaped hands whose thumbs and index fingers meet in a rectangle. Returns true while the pose is held.
function palmC(h) { const c = { x: 0, y: 0 }; [0, 5, 9, 13, 17].forEach(k => { c.x += h.pts[k].x / 5; c.y += h.pts[k].y / 5; }); return c; }
function prayerStep(hs, now) {
  let is = false, metric = 0, ps = 1, two = hs.length >= 2, rect = null;
  if (two) {
    const A = hs[0], B = hs[1];
    const la = dist(A.pts[0], A.pts[9]), lb = dist(B.pts[0], B.pts[9]);
    ps = (la + lb) / 2 || 1;
    const tips = dist(A.pts[12], B.pts[12]), mids = dist(A.pts[9], B.pts[9]), wr = dist(A.pts[0], B.pts[0]);
    const fx = [A.pts[4], A.pts[8], B.pts[4], B.pts[8]].map(q => q.x), fy = [A.pts[4], A.pts[8], B.pts[4], B.pts[8]].map(q => q.y);
    rect = { x: Math.min(...fx), y: Math.min(...fy), w: Math.max(...fx) - Math.min(...fx), h: Math.max(...fy) - Math.min(...fy) }; // box spanned by the 4 L-fingertips
    const cc = dist(palmC(A), palmC(B));
    metric = cc; // distance between the two palms: shrinks when you crumple, grows when you unfold
    // frame sign: both hands make an "L" (thumb + index spread) and the fingertips meet at the corners of a rectangle
    const spread = h => dist(h.pts[4], h.pts[8]) > 0.5 * dist(h.pts[0], h.pts[9]);
    const k = 1.0 * ps;
    const cornersX = dist(A.pts[4], B.pts[8]) < k && dist(A.pts[8], B.pts[4]) < k;   // thumb meets the other hand's index
    const cornersP = dist(A.pts[4], B.pts[4]) < k && dist(A.pts[8], B.pts[8]) < k;   // thumbs meet, indexes meet
    is = ps > 25 && spread(A) && spread(B) && (cornersX || cornersP) && cc > 0.9 * ps && cc < 4 * ps;
  }
  if (is) {
    pray.nis = 0;
    if (!pray.since) { pray.since = now; pray.min = metric; }
    pray.last = now; pray.min = Math.min(pray.min, metric);
    if (!pray.needRelease && !pray.armed && now - pray.since > PRAY_HOLD) {
      pray.armed = true;
      pray.pick = lastPicked && now - lastPicked.t < PICK_WINDOW && bubs[lastPicked.id] && bubs[lastPicked.id].on ? lastPicked.id : null; // the circle in your hand just before the sign
      const fresh = lastHeld && now - lastHeld.t < HELD_WINDOW && !bubs[lastHeld.id].on;
      const anyOn = Object.values(bubs).some(b => b.on);
      let target = fresh ? lastHeld.id : null;
      if (!target && !anyOn) target = (lastHeld && !bubs[lastHeld.id].on) ? lastHeld.id : (!yt.docked ? 'yt' : (!docked ? 'hand' : (window.scopeIsOpen && window.scopeIsOpen() ? 'voice' : null)));
      if (target && bubCollapse(target)) { // the tool you were holding (or the one on screen) becomes a circle
        lastHeld = null; pray.armed = false; pray.needRelease = true; bubLock = now + 600;
        toast('Minimized. To open: make the frame sign again, then pull your hands apart');
      }
    }
    return true;
  }
  pray.since = 0;
  if (!pray.nis) pray.nis = now;
  if (pray.needRelease && now - pray.nis > 400) pray.needRelease = false;
  if (pray.armed) {
    if (now - pray.last > 1200) pray.armed = false;
    else if (two && metric - pray.min > ps * OPEN_APART) { // palms together -> pulled apart = reverse clap
      pray.armed = false; pray.needRelease = true;
      const picked = pray.pick && bubs[pray.pick] && bubs[pray.pick].on ? bubs[pray.pick] : null;   // the circle you picked up wins
      const last = picked || Object.values(bubs).filter(b => b.on).sort((a, b) => b.at - a.at)[0];  // otherwise the newest one
      pray.pick = null; lastPicked = null;
      if (last && bubOpen(last.id, rect)) { bubLock = now + 600; toast('Opened'); }
    }
  }
  return false;
}

// ---------- start ----------
// the box starts docked inside the (closed) drawer
sizeOverlay();
setTrack(showTrack);
setHand(handOn);
renderSetup();
useRoom(room).then(poll);
loadBase();
setInterval(loadBase, 60000); // keeps the link correct if your public IP changes
</script>
<script src="browser.php?js=1"></script>
<script src="chat.php?js=1"></script>
</body>
</html>
