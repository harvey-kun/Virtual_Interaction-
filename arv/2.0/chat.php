<?php
/*
  AI CHAT for the phone-cam viewer.

  Hold your hand sideways with the index and middle fingers out and the ring + little finger folded down
  (point left or right, it works both ways). A floating chat window opens. Talk or type to the AI, ask it
  anything, or ask for pictures ("show me red pandas"): they are searched and placed on the screen, where
  you can pinch to move them, pinch with two hands to resize them, and pinch the x to remove them.

  Files (all in the same folder):  viewer.php  browser.php  aikit.php  cacert.pem  chat.php (this file)

  SETUP
  1. viewer.php already loads this file with one line at the end:  <script src="chat.php?js=1"></script>
  2. Open  chat.php?settings=1  (or the "AI chat settings" button on the viewer):
        AI tab        -> add your AI key (this is aikit.php, shown inside the page)
        Pictures tab  -> pick a picture search service and paste its key
        Assistant tab -> extra instructions, speech language, read replies aloud
  3. Speech needs Chrome or Edge and https:// or localhost.

  This file reuses aikit.php for every AI call and for its database (MySQL $conn, AIKIT_DB_*, or SQLite).
  It adds one small table, aikit_chat_settings, created automatically.
*/
require_once __DIR__ . '/aikit.php';

/* ---------- settings (stored in the same database as the AI list) ---------- */

function chatDefaults(): array {
    return [
        'img_provider' => 'none',   // none | brave | serper | serpapi | searxng
        'img_key'      => '',
        'img_base'     => '',       // SearXNG address
        'img_safe'     => '1',
        'img_count'    => '4',
        'sys_prompt'   => '',
        'lang'         => '',       // speech language, e.g. en-PH ('' = the browser's language)
        'speak'        => '0',      // read replies aloud
        'auto_listen'  => '1',      // start listening when the gesture opens the chat
        'phone_mic'    => '1',      // try to listen through the phone's mic
    ];
}

function chatInit(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    aikitExec("CREATE TABLE IF NOT EXISTS aikit_chat_settings (k VARCHAR(60) NOT NULL PRIMARY KEY, v TEXT NOT NULL)");
}

function chatCfg(): array {
    chatInit();
    $cfg = chatDefaults();
    foreach (aikitRows("SELECT k, v FROM aikit_chat_settings") as $r) {
        if (array_key_exists($r['k'], $cfg)) $cfg[$r['k']] = (string)$r['v'];
    }
    return $cfg;
}

function chatSave(array $kv): void {
    chatInit();
    $allowed = chatDefaults();
    foreach ($kv as $k => $v) {
        if (!array_key_exists($k, $allowed)) continue;
        aikitExec("DELETE FROM aikit_chat_settings WHERE k = ?", [$k]);
        aikitExec("INSERT INTO aikit_chat_settings (k, v) VALUES (?, ?)", [$k, (string)$v]);
    }
}

function chatProviders(): array {
    return [
        'none'    => 'Off (no picture search)',
        'brave'   => 'Brave Search API',
        'serper'  => 'Serper.dev (Google images)',
        'serpapi' => 'SerpApi (Google Images)',
        'searxng' => 'SearXNG (self-hosted)',
    ];
}

/* What the browser pages may know: everything except the picture-search key */
function chatPublicCfg(array $cfg): array {
    $out = $cfg;
    unset($out['img_key']);
    $out['has_key'] = trim($cfg['img_key']) !== '';
    $out['key_hint'] = $out['has_key'] ? '••••' . substr(trim($cfg['img_key']), -4) : '';
    $out['img_ready'] = chatImgReady($cfg);
    return $out;
}

function chatImgReady(array $cfg): bool {
    $p = $cfg['img_provider'];
    if ($p === 'none' || !isset(chatProviders()[$p])) return false;
    if ($p === 'searxng') return aiValidBase(trim($cfg['img_base']));
    return trim($cfg['img_key']) !== '';
}

/* ---------- picture search ---------- */

/*
    Returns ["ok"=>true, "images"=>[...], "spare"=>[...]] or ["ok"=>false, "error"=>"...", "setup"=>bool].
    An image = ["thumb","full","title","page","w","h"]. "spare" are extra pictures the screen uses when a link is dead.
*/
function chatImageSearch(string $q, int $n, array $c): array {
    $n    = max(1, min(8, $n));
    $prov = (string)($c['img_provider'] ?? 'none');
    $key  = trim((string)($c['img_key'] ?? ''));
    $safe = (string)($c['img_safe'] ?? '1') === '1';
    $q    = mb_substr(trim($q), 0, 200, 'UTF-8');

    if ($q === '') return ['ok' => false, 'error' => 'Nothing to search for.'];
    if (!isset(chatProviders()[$prov]) || $prov === 'none') return ['ok' => false, 'setup' => true, 'error' => 'Picture search is not set up yet.'];
    if ($prov !== 'searxng' && $key === '') return ['ok' => false, 'setup' => true, 'error' => 'Paste the picture search key first.'];

    $want = min(30, $n * 3);

    if ($prov === 'brave') {
        $url = 'https://api.search.brave.com/res/v1/images/search?q=' . rawurlencode($q) . '&count=' . $want . '&safesearch=' . ($safe ? 'strict' : 'off');
        $h = aiHttp('GET', $url, ['Accept: application/json', 'X-Subscription-Token: ' . $key], null, 20);
    } elseif ($prov === 'serper') {
        $h = aiHttp('POST', 'https://google.serper.dev/images', ['Content-Type: application/json', 'X-API-KEY: ' . $key],
                    json_encode(['q' => $q, 'num' => $want]), 20);
    } elseif ($prov === 'serpapi') {
        $url = 'https://serpapi.com/search.json?engine=google_images&q=' . rawurlencode($q) . '&api_key=' . rawurlencode($key) . '&safe=' . ($safe ? 'active' : 'off');
        $h = aiHttp('GET', $url, ['Accept: application/json'], null, 25);
    } else {
        $base = rtrim(trim((string)($c['img_base'] ?? '')), '/');
        if (!aiValidBase($base)) return ['ok' => false, 'setup' => true, 'error' => 'Enter the SearXNG address first (starting with http:// or https://).'];
        $url = $base . '/search?q=' . rawurlencode($q) . '&format=json&categories=images&safesearch=' . ($safe ? '2' : '0');
        $h = aiHttp('GET', $url, ['Accept: application/json'], null, 25);
    }

    if ($h['code'] < 200 || $h['code'] >= 300) {
        $e = aiHttpFail($h);
        $m = $e['msg'];
        if ($prov === 'searxng' && $h['code'] === 403) $m .= ' (turn on "json" under search > formats in the SearXNG settings.yml)';
        return ['ok' => false, 'error' => 'Picture search failed: ' . $m];
    }
    $d = json_decode($h['body'], true);
    if (!is_array($d)) return ['ok' => false, 'error' => 'Picture search answered with something unreadable.'];

    $out  = [];
    $seen = [];
    $add = function ($full, $thumb, $title, $page, $w, $hh) use (&$out, &$seen) {
        $full = trim((string)$full); $thumb = trim((string)$thumb);
        if ($full === '' && $thumb === '') return;
        if ($full === '') $full = $thumb;
        if ($thumb === '') $thumb = $full;
        if (!preg_match('~^https?://~i', $full) || !preg_match('~^https?://~i', $thumb)) return;
        if (preg_match('~\.svg(\?|$)~i', $full)) return;
        $w = (int)$w; $hh = (int)$hh;
        if (($w > 0 && $w < 120) || ($hh > 0 && $hh < 120)) return;
        if (isset($seen[$full])) return;
        $seen[$full] = true;
        $out[] = ['thumb' => $thumb, 'full' => $full, 'title' => mb_substr(trim(strip_tags((string)$title)), 0, 160, 'UTF-8'),
                  'page' => (string)$page, 'w' => $w, 'h' => $hh];
    };

    if ($prov === 'brave') {
        foreach (($d['results'] ?? []) as $r) {
            $add($r['properties']['url'] ?? '', $r['thumbnail']['src'] ?? '', $r['title'] ?? '', $r['url'] ?? '',
                 $r['properties']['width'] ?? 0, $r['properties']['height'] ?? 0);
        }
    } elseif ($prov === 'serper') {
        foreach (($d['images'] ?? []) as $r) {
            $add($r['imageUrl'] ?? '', $r['thumbnailUrl'] ?? '', $r['title'] ?? '', $r['link'] ?? '',
                 $r['imageWidth'] ?? 0, $r['imageHeight'] ?? 0);
        }
    } elseif ($prov === 'serpapi') {
        foreach (($d['images_results'] ?? []) as $r) {
            $add($r['original'] ?? '', $r['thumbnail'] ?? '', $r['title'] ?? '', $r['link'] ?? '',
                 $r['original_width'] ?? 0, $r['original_height'] ?? 0);
        }
    } else {
        foreach (($d['results'] ?? []) as $r) {
            $w = 0; $hh = 0;
            if (!empty($r['resolution']) && preg_match('/(\d+)\s*[x×]\s*(\d+)/u', (string)$r['resolution'], $m)) { $w = (int)$m[1]; $hh = (int)$m[2]; }
            $add($r['img_src'] ?? '', $r['thumbnail_src'] ?? ($r['thumbnail'] ?? ''), $r['title'] ?? '', $r['url'] ?? '', $w, $hh);
        }
    }

    if (!$out) return ['ok' => false, 'error' => 'No pictures found for "' . $q . '".'];
    return ['ok' => true, 'images' => array_slice($out, 0, $n), 'spare' => array_slice($out, $n, $n)];
}

/* ---------- picture proxy: the screen loads pictures through here so hot-link protection can't block them ---------- */

function chatHostSafe(string $host): bool {
    $host = trim($host, '[]');
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($host, FILTER_VALIDATE_IP)) return filter_var($host, FILTER_VALIDATE_IP, $flags) !== false;
    $ips = @gethostbynamel($host);
    if (!$ips) return false;
    foreach ($ips as $ip) if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) return false;
    return true;
}

function chatAbsUrl(string $base, string $loc): string {
    if (preg_match('~^https?://~i', $loc)) return $loc;
    $p = parse_url($base);
    $root = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strncmp($loc, '//', 2) === 0) return ($p['scheme'] ?? 'http') . ':' . $loc;
    if (strncmp($loc, '/', 1) === 0)  return $root . $loc;
    $dir = rtrim(dirname($p['path'] ?? '/'), '/\\');
    return $root . $dir . '/' . $loc;
}

function chatProxy(string $url): void {
    ini_set('display_errors', '0');
    if (!aikitCanAsk()) { http_response_code(401); exit; }
    if (!function_exists('curl_init')) { http_response_code(503); exit; }
    $url = trim($url);
    $body = ''; $type = ''; $code = 0;

    for ($hop = 0; $hop < 4; $hop++) {
        $p = parse_url($url);
        if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || !chatHostSafe((string)$p['host'])) {
            http_response_code(400); exit;
        }
        $body = ''; $type = ''; $loc = ''; $big = false;
        $ch = curl_init($url);
        $opts = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; phonecam)',
            CURLOPT_HTTPHEADER     => ['Accept: image/*,*/*;q=0.5'],
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$type, &$loc) {
                if (stripos($line, 'content-type:') === 0) $type = strtolower(trim(substr($line, 13)));
                elseif (stripos($line, 'location:') === 0) $loc = trim(substr($line, 9));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($c, $data) use (&$body, &$big) {
                $body .= $data;
                if (strlen($body) > 10485760) { $big = true; return 0; }
                return strlen($data);
            },
        ];
        $ca = aiCaBundle();
        if ($ca !== '') $opts[CURLOPT_CAINFO] = $ca;
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80000) curl_close($ch);

        if ($code >= 300 && $code < 400 && $loc !== '') { $url = chatAbsUrl($url, $loc); continue; }
        break;
    }

    $type = trim(explode(';', $type)[0]);
    if ($code !== 200 || strncmp($type, 'image/', 6) !== 0 || $type === 'image/svg+xml' || $body === '') { http_response_code(404); exit; }

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

/* ---------- the chat itself ---------- */

function chatSystem(array $cfg): string {
    $s = "You are a friendly assistant shown on a computer screen. The person stands a few steps away and controls the screen with hand gestures and often their voice, "
       . "so write plain conversational text: no markdown, no bullet symbols, no tables. Keep answers short (one to four sentences) unless they ask for more. "
       . "Answer in the language they write in.\n"
       . "You CAN put pictures on their screen. When they want to see, find or look at pictures, photos, images, logos, or what something looks like, "
       . "START your reply with exactly one line of this form: [[images: short search words | how many]] "
       . "(how many is 1 to 8; use 4 if they did not say), then add one short friendly sentence. "
       . "The picture search happens automatically after that line. Never write that line for any other kind of request, and never say you cannot show pictures.";
    $extra = trim((string)$cfg['sys_prompt']);
    if ($extra !== '') $s .= "\n\nExtra instructions from the owner:\n" . $extra;
    return $s;
}

/* [clean reply, search words or null, how many or null] */
function chatParseTag(string $reply): array {
    if (preg_match('/\[\[\s*images?\s*:\s*(.+?)\s*(?:\|\s*(\d+)\s*)?\]\]/iu', $reply, $m)) {
        $clean = trim(preg_replace('/\[\[\s*images?\s*:.*?\]\]/iu', '', $reply));
        return [$clean, trim($m[1]), isset($m[2]) && $m[2] !== '' ? (int)$m[2] : null];
    }
    return [$reply, null, null];
}

/* Safety net for AIs that forget the line: "show me pictures of ..." still works */
function chatImageIntent(string $msg): ?string {
    if (!preg_match('/\b(images?|pictures?|photos?|pics?|wallpapers?)\b/i', $msg)) return null;
    if (!preg_match('/\b(show|find|search|get|display|pull up|let me see|give|bring|look)\b|\b(images?|pictures?|photos?|pics?) of\b/i', $msg)) return null;
    $q = preg_replace('/\b(please|can you|could you|would you|show me|show|find me|find|search for|search|get me|get|display|pull up|let me see|give me|bring up|bring|look up|look for|some|any|a few|images?|pictures?|photos?|pics?|wallpapers?|on the screen|on screen)\b/i', ' ', $msg);
    $q = trim(preg_replace('/\s+/', ' ', $q));
    $q = trim(preg_replace('/^(of|for)\s+/i', '', $q));
    return $q !== '' ? mb_substr($q, 0, 120, 'UTF-8') : null;
}

function chatApi(string $act): void {
    ini_set('display_errors', '0');
    ob_start();
    try {
        if (!function_exists('curl_init')) aikitOut(['ok' => false, 'error' => 'The PHP cURL extension is turned off on this server.'], 503);
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';

        if ($act === 'ask') {
            if (!$post)            aikitOut(['ok' => false, 'error' => 'Unknown request.'], 400);
            if (!aikitCanAsk())    aikitOut(['ok' => false, 'error' => 'Please sign in first.'], 401);
            if (!aikitCsrfOk())    aikitOut(['ok' => false, 'error' => 'This page is out of date. Reload it and try again.'], 403);

            $msg = trim((string)($_POST['message'] ?? ''));
            if ($msg === '' || mb_strlen($msg, 'UTF-8') > 4000) aikitOut(['ok' => false, 'error' => 'Write a message (up to 4000 characters).'], 400);
            if (!aikitRateOk())    aikitOut(['ok' => false, 'error' => 'Slow down a little and try again in a minute.'], 429);

            $history = json_decode((string)($_POST['history'] ?? '[]'), true);
            $history = is_array($history) ? array_values(array_filter($history, 'is_array')) : [];

            $cfg = chatCfg();
            $r = aiAsk($msg, chatSystem($cfg), $history);

            if (empty($r['ok'])) {
                if (!empty($r['setup'])) aikitOut(['ok' => false, 'setup' => true, 'error' => 'No AI is set up yet.'], 503);
                aikitOut(['ok' => false, 'error' => 'The AI is busy right now. Try again in a moment.', 'notes' => aikitIsAdmin() ? ($r['notes'] ?? []) : []], 502);
            }

            [$reply, $query, $count] = chatParseTag((string)$r['reply']);
            if ($query === null) {
                $guess = chatImageIntent($msg);
                if ($guess !== null) $query = $guess;
            }

            $images = []; $spare = []; $imgErr = '';
            if ($query !== null && $query !== '') {
                $n = $count ?? (int)$cfg['img_count'];
                $s = chatImageSearch($query, $n, $cfg);
                if ($s['ok']) { $images = $s['images']; $spare = $s['spare']; }
                else $imgErr = $s['error'] . (!empty($s['setup']) ? ' Tap the gear, then the Pictures tab.' : '');
            }
            if (trim($reply) === '') {
                $reply = $images ? 'Here are ' . count($images) . ' pictures of ' . $query . '.' : ($imgErr !== '' ? 'I could not get the pictures.' : '…');
            }
            aikitOut(['ok' => true, 'reply' => $reply, 'query' => $query, 'images' => $images, 'spare' => $spare, 'imgError' => $imgErr]);
        }

        /* everything below is for the settings page */
        if (!aikitIsAdmin()) aikitOut(['ok' => false, 'error' => 'Only the admin can do that.'], 403);

        if ($act === 'cfg_get') {
            aikitOut(['ok' => true, 'cfg' => chatPublicCfg(chatCfg()), 'providers' => chatProviders(), 'ai_count' => count(aiChain())]);
        }

        if (!$post) aikitOut(['ok' => false, 'error' => 'Unknown request.'], 400);
        if (!aikitCsrfOk()) aikitOut(['ok' => false, 'error' => 'This page is out of date. Reload it and try again.'], 403);

        if ($act === 'cfg_save') {
            $cur  = chatCfg();
            $prov = (string)($_POST['img_provider'] ?? 'none');
            if (!isset(chatProviders()[$prov])) aikitOut(['ok' => false, 'error' => 'Unknown picture service.'], 400);

            $key = trim((string)($_POST['img_key'] ?? ''));
            if (($_POST['clear_key'] ?? '') === '1') $key = '';
            elseif ($key === '') $key = trim($cur['img_key']);
            if (preg_match('/\s/', $key) || strlen($key) > 400) aikitOut(['ok' => false, 'error' => 'The key can\'t contain spaces or line breaks.'], 400);

            $base = trim((string)($_POST['img_base'] ?? ''));
            if ($base !== '' && (!aiValidBase($base) || strlen($base) > 255)) aikitOut(['ok' => false, 'error' => 'The SearXNG address must start with http:// or https://'], 400);

            $lang = trim((string)($_POST['lang'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9-]{0,20}$/', $lang)) aikitOut(['ok' => false, 'error' => 'Speech language looks like en-US, en-PH or fil-PH.'], 400);

            $flag = fn($k) => (($_POST[$k] ?? '0') === '1') ? '1' : '0';
            chatSave([
                'img_provider' => $prov,
                'img_key'      => $key,
                'img_base'     => $base,
                'img_safe'     => $flag('img_safe'),
                'img_count'    => (string)max(1, min(8, (int)($_POST['img_count'] ?? 4))),
                'sys_prompt'   => mb_substr(trim((string)($_POST['sys_prompt'] ?? '')), 0, 2000, 'UTF-8'),
                'lang'         => $lang,
                'speak'        => $flag('speak'),
                'auto_listen'  => $flag('auto_listen'),
                'phone_mic'    => $flag('phone_mic'),
            ]);
            aikitOut(['ok' => true, 'cfg' => chatPublicCfg(chatCfg())]);
        }

        if ($act === 'img_test') {
            $cur = chatCfg();
            $c = $cur;
            $c['img_provider'] = (string)($_POST['img_provider'] ?? $cur['img_provider']);
            $k = trim((string)($_POST['img_key'] ?? ''));
            if ($k !== '') $c['img_key'] = $k;
            $c['img_base'] = trim((string)($_POST['img_base'] ?? $cur['img_base']));
            $c['img_safe'] = (($_POST['img_safe'] ?? '1') === '1') ? '1' : '0';
            $t0 = microtime(true);
            $s = chatImageSearch('red panda', 3, $c);
            $s['ms'] = (int)round((microtime(true) - $t0) * 1000);
            aikitOut($s);
        }

        aikitOut(['ok' => false, 'error' => 'Unknown request.'], 400);
    } catch (Throwable $e) {
        aikitOut(['ok' => false, 'error' => aikitIsAdmin() ? $e->getMessage() : 'Something went wrong.'], 500);
    }
}

/* ---------- routes ---------- */

if (isset($_GET['px']))  { chatProxy((string)$_GET['px']); exit; }
if (isset($_GET['api'])) { chatApi((string)$_GET['api']); exit; }

$chatCsrf = AIKIT_OPEN ? '' : aikitCsrfToken();

/* the loader script that viewer.php includes */
if (isset($_GET['js'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store');
?>
(() => {
'use strict';
// ====== tunables ======
const HOLD = 550;         // ms the pose has to be held before the chat opens
const EXT = 1.7;          // a finger counts as straight when (tip to wrist) > this x (knuckle to wrist). Lower it if the pose is not recognised
const CURL = 1.45;        // ... and as folded down when below this. Raise it if the folded fingers are not recognised
const TILT = 35;          // degrees: how far from sideways (horizontal) the hand may be turned
const STILL = 0.35;       // max hand speed (px per ms) while holding
const THUMB_HOLD = 250;   // ms a thumbs up / down has to be held before the chat scrolls
const MAX_PICS = 14;      // pictures on the screen at once (the oldest goes first)

const d2 = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const inR = (p, r, m) => p.x >= r.left - m && p.x <= r.right + m && p.y >= r.top - m && p.y <= r.bottom + m;
const ld = k => { try { return localStorage.getItem(k); } catch (e) { return null; } };
const sv = (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} };
const note = t => { try { if (typeof toast === 'function') toast(t); } catch (e) {} };
const busyOther = () => { try { return !!(grab || mouseDrag || ytBusy() || Object.values(bubs).some(b => b.hdrag || b.mdrag)); } catch (e) { return false; } };
const heldByBub = h => { try { return typeof bubHeld === 'function' && bubHeld(h); } catch (e) { return false; } };

const SRC = new URL('chat.php?embed=1', location.href).href;
const PX = new URL('chat.php?px=', location.href).href;
const SETTINGS = new URL('chat.php?settings=1', location.href).href;
const px = u => PX + encodeURIComponent(u);

let gestureOn = ld('pc_chatg') !== '0';
let win = null, frame = null, barEl = null, xEl = null, mEl = null, rzEl = null;
let isOpen = false, hd = null, md = null, sentSome = false, xPrev = false, mPrev = false, placed = false, wantListen = false;
const W = { x: 0, y: 0, w: 460, h: 560 };
const ps = { since: 0, c: null, t: 0, v: 0, absent: 0, need: false, lock: 0 };
const th = { cand: 0, since: 0, dir: 0, last: 0 };

// ====== styles ======
const st = document.createElement('style');
st.textContent = `
  #chatWin{position:fixed;z-index:8;display:none;flex-direction:column;box-sizing:border-box;border:1.5px solid #2A3758;border-radius:18px;background:#0B1020;box-shadow:0 10px 30px rgba(2,6,23,.6);overflow:hidden;font-family:Inter,system-ui,sans-serif}
  #chatWin.open{display:flex}
  #chatWin .cbar{flex:none;display:flex;align-items:center;justify-content:space-between;padding:4px 6px 4px 12px;background:#121A2E;border-bottom:1px solid #2A3758;cursor:grab;touch-action:none;user-select:none;color:#E8ECF8;font:600 13px Inter,system-ui,sans-serif}
  #chatWin .cbar b{display:inline-flex;align-items:center;gap:8px;font-weight:600}
  #chatWin .cbar svg{width:16px;height:16px;color:#C4B5FD}
  #chatWin .cbtn{display:flex;gap:4px}
  #chatWin .cbtn button{width:36px;height:30px;min-height:0;padding:0;border-radius:8px;background:#1F2B49;color:#E8ECF8;border:1px solid #2A3758;display:inline-flex;align-items:center;justify-content:center;font-size:16px;line-height:1;cursor:pointer}
  #chatWin .cbtn button:hover{background:#29395F}
  #chatWin .cbtn button:focus-visible{outline:2px solid #93C5FD;outline-offset:1px}
  #chatWin iframe{flex:1;min-height:0;width:100%;border:0;background:transparent}
  #chatWin.dragging iframe{pointer-events:none}
  #chatWin .crz{position:absolute;right:0;bottom:0;width:26px;height:26px;z-index:2;cursor:nwse-resize;touch-action:none;
    background:linear-gradient(135deg,transparent 52%,rgba(255,255,255,.6) 52%,rgba(255,255,255,.6) 60%,transparent 60%,transparent 72%,rgba(255,255,255,.6) 72%,rgba(255,255,255,.6) 80%,transparent 80%)}
  .bubble.chat{background:linear-gradient(135deg,#7C3AED,#2563EB)}
  .pcImg{position:fixed;z-index:6;box-sizing:border-box;border-radius:14px;overflow:hidden;background:#121A2E;border:2px solid #2A3758;box-shadow:0 8px 24px rgba(2,6,23,.55);
    touch-action:none;cursor:grab;user-select:none;opacity:0;transform:scale(.92);transition:opacity .2s,transform .2s,border-color .15s}
  .pcImg.ready{opacity:1;transform:none}
  .pcImg.held{border-color:#3ecf8e}
  .pcImg img{width:100%;height:100%;object-fit:contain;display:block;pointer-events:none;-webkit-user-drag:none}
  .pcImg .px{position:absolute;top:6px;right:6px;width:36px;height:36px;padding:0;border-radius:50%;border:1px solid rgba(255,255,255,.35);background:rgba(11,16,32,.82);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer}
  .pcImg .px:hover{background:#DC2626}
  .pcImg .px svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2.4;stroke-linecap:round;pointer-events:none}
  .pcImg .prz{position:absolute;right:0;bottom:0;width:30px;height:30px;cursor:nwse-resize;touch-action:none;
    background:linear-gradient(135deg,transparent 52%,rgba(255,255,255,.75) 52%,rgba(255,255,255,.75) 60%,transparent 60%,transparent 72%,rgba(255,255,255,.75) 72%,rgba(255,255,255,.75) 80%,transparent 80%)}
  .pcImg .cap{position:absolute;left:0;right:34px;bottom:0;padding:14px 8px 6px 10px;font:12px/1.3 Inter,system-ui,sans-serif;color:#fff;background:linear-gradient(transparent,rgba(0,0,0,.72));
    opacity:0;transition:opacity .15s;pointer-events:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .pcImg:hover .cap,.pcImg.held .cap{opacity:1}
  @media (prefers-reduced-motion:reduce){.pcImg{transition:none;transform:none}}
`;
document.head.appendChild(st);

// ====== chat window ======
const ICON_CHAT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

function build() {
  if (win) return;
  win = document.createElement('div');
  win.id = 'chatWin';
  win.innerHTML = '<div class="cbar"><b>' + ICON_CHAT + 'AI chat</b><span class="cbtn">' +
    '<button type="button" class="cm" title="Minimize to a circle" aria-label="Minimize"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/></svg></button>' +
    '<button type="button" class="cx" title="Close" aria-label="Close"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button></span></div>' +
    '<iframe allow="microphone; autoplay" title="AI chat"></iframe><div class="crz" title="Drag to resize"></div>';
  document.body.appendChild(win);
  frame = win.querySelector('iframe'); barEl = win.querySelector('.cbar'); xEl = win.querySelector('.cx'); mEl = win.querySelector('.cm'); rzEl = win.querySelector('.crz');
  mEl.addEventListener('click', e => { e.stopPropagation(); minimizeWin(); });
  xEl.addEventListener('click', e => { e.stopPropagation(); closeWin(); });
  // mouse / touch: drag by the title bar, resize by the corner
  barEl.addEventListener('pointerdown', e => {
    if (e.target.closest('button')) return;
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
  W.w = Math.max(320, Math.min(innerWidth, W.w));
  W.h = Math.max(300, Math.min(innerHeight, W.h));
  W.x = Math.max(0, Math.min(innerWidth - W.w, W.x));
  W.y = Math.max(0, Math.min(innerHeight - W.h, W.y));
  const s = win.style;
  s.left = W.x + 'px'; s.top = W.y + 'px'; s.width = W.w + 'px'; s.height = W.h + 'px';
}

function tell(msg) { try { frame.contentWindow.postMessage(msg, location.origin); } catch (e) {} }

function showWin(listen) {
  build(); place();
  win.classList.add('open'); isOpen = true;
  wantListen = !!listen;
  if (!frame.getAttribute('src')) frame.src = SRC;               // first time: the page announces itself with "ready"
  else if (listen) tell({ pcc: 'listen' });
}
function openWin(listen) {
  build();
  const vb = bubs.chat;
  if (vb && vb.on) { vb.on = false; vb.hdrag = vb.mdrag = null; vb.el.style.display = 'none'; vb.el.classList.remove('drag'); bubLayout(); }   // it was a circle: bring the window back
  if (!placed) {
    W.w = Math.min(460, innerWidth * 0.92); W.h = Math.min(580, innerHeight * 0.82);
    W.x = (innerWidth - W.w) * 0.88; W.y = Math.max(60, (innerHeight - W.h) / 2); placed = true;
  }
  showWin(listen);
}
function hideWin() {
  tell({ pcc: 'stop' });
  win.classList.remove('open', 'dragging');
  isOpen = false; hd = null; md = null; sentSome = false; xPrev = false; mPrev = false; wantListen = false;
  ps.lock = performance.now() + 1500; ps.need = true; ps.since = 0;
}
function closeWin() { if (win && isOpen) hideWin(); }
function minimizeWin() { if (isOpen && typeof bubCollapse === 'function') bubCollapse('chat'); }

// join the viewer's minimize-to-circle system (frame sign with both hands = minimize, reverse clap = open)
window.chatIsOpen = () => isOpen;
if (typeof bubs !== 'undefined' && typeof bubMake === 'function') {
  bubs.chat = {
    id: 'chat', icon: ICON_CHAT, title: 'AI chat: click to open (or reverse-clap)', on: false, side: 't', order: 0, at: 0, x: 0, y: 0, hdrag: null, mdrag: null, el: null,
    ext: {
      collapse() { if (!isOpen) return null; const c = { x: W.x + W.w / 2, y: W.y + W.h / 2 }; hideWin(); return c; },
      open(rect) {
        if (rect) { W.w = Math.max(320, rect.w); W.h = Math.max(300, rect.h); W.x = rect.x + rect.w / 2 - W.w / 2; W.y = rect.y + rect.h / 2 - W.h / 2; placed = true; }
        showWin(false);
      },
      el() { build(); return win; },
    },
  };
  bubMake(bubs.chat);
}

addEventListener('message', e => {
  if (e.origin !== location.origin || !frame || e.source !== frame.contentWindow) return;
  const m = e.data;
  if (!m || !m.pcc) return;
  if (m.pcc === 'ready') { if (isOpen && wantListen) tell({ pcc: 'listen' }); }
  else if (m.pcc === 'close') closeWin();
  else if (m.pcc === 'images') addImages(m.list || [], m.spare || []);
  else if (m.pcc === 'clearImgs') clearImgs();
});

// ====== hands over the chat window ======
function thumbOf(h) {
  const p = h.pts, size = (d2(p[0], p[9]) + d2(p[5], p[17])) / 2 || 1;
  if (size < 25) return 0;
  const curled = [[8, 5], [12, 9], [16, 13], [20, 17]].every(([t, m]) => d2(p[t], p[0]) < CURL * d2(p[m], p[0]));
  if (!curled) return 0;
  if (d2(p[4], p[5]) < 0.8 * size) return 0;
  const dx = p[4].x - p[2].x, dy = p[4].y - p[2].y;
  if (Math.hypot(dx, dy) < 0.6 * size || Math.abs(dy) < 1.2 * Math.abs(dx)) return 0;
  return dy < 0 ? -1 : 1;
}
function thumbStep(hs, now) {
  let dir = 0;
  for (const h of hs) { const t = thumbOf(h); if (t) { dir = t; break; } }
  if (dir !== th.cand) { th.cand = dir; th.since = now; }
  th.dir = dir && now - th.since > THUMB_HOLD ? dir : 0;
  return th.dir;
}

function windowInteract(hs, now) {
  const r = win.getBoundingClientRect(), ir = frame.getBoundingClientRect();
  const out = [];
  hs.forEach(h => {
    if (inR(h.tip, ir, 0) || (h.pinching && inR(h.pp, ir, 0)))
      out.push({ x: h.tip.x - ir.left, y: h.tip.y - ir.top, px: h.pp.x - ir.left, py: h.pp.y - ir.top, pinch: !!h.pinching });
  });
  const tdir = thumbStep(hs, now);
  if (out.length || sentSome || tdir || th.last) {
    tell({ pcc: 'hand', hands: out, thumb: tdir });
    sentSome = out.length > 0; th.last = tdir;
  }

  const pinchers = hs.filter(h => h.pinching);
  if (hd || pinchers.some(h => inR(h.pp, r, 10))) { try { lastHeld = { id: 'chat', t: now }; } catch (e) {} }   // so the frame sign minimizes THIS tool
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

// ====== the gesture: index + middle finger out, ring + little finger folded down, hand turned sideways (either way) ======
function pose(h) {
  const p = h.pts, size = (d2(p[0], p[9]) + d2(p[5], p[17])) / 2 || 1;
  if (size < 25) return null;
  const rt = (t, m) => d2(p[t], p[0]) / (d2(p[m], p[0]) || 1);
  if (rt(8, 5) < EXT || rt(12, 9) < EXT) return null;          // index and middle straight
  if (rt(16, 13) > CURL || rt(20, 17) > CURL) return null;     // ring and little finger folded down
  if (d2(p[4], p[8]) / size < 0.5) return null;                // not a pinch
  const dx = p[9].x - p[0].x, dy = p[9].y - p[0].y;            // wrist -> middle knuckle = which way the hand points
  const ang = Math.abs(Math.atan2(dy, dx)) * 180 / Math.PI;    // 0 = right, 180 = left
  if (Math.min(ang, 180 - ang) > TILT) return null;            // must point (roughly) left or right
  return { c: { x: (p[0].x + p[9].x) / 2, y: (p[0].y + p[9].y) / 2 }, size, dir: dx > 0 ? 1 : -1 };
}

function detect(hs, now) {
  if (!gestureOn || now < ps.lock) { ps.since = 0; ps.c = null; return; }
  let cand = null;
  if (!busyOther() && !ig) {
    for (const h of hs) {
      if (heldByBub(h)) continue;
      const p = pose(h);
      if (p) { cand = { h, p }; break; }
    }
  }
  if (!cand) {
    ps.since = 0; ps.c = null;
    if (!ps.absent) ps.absent = now;
    if (now - ps.absent > 500) ps.need = false;               // the pose has to be dropped once before it can trigger again
    return;
  }
  ps.absent = 0;
  if (ps.need) return;

  const c = cand.p.c;
  if (!ps.since || !ps.c) { ps.since = now; ps.v = 0; }
  else {
    const dt = Math.max(1, now - ps.t);
    ps.v = ps.v * 0.7 + (d2(c, ps.c) / dt) * 0.3;
    if (ps.v > STILL) ps.since = now;
  }
  ps.c = c; ps.t = now;
  const held = now - ps.since;
  if (held > 150) cand.h.pinching = false;                    // this pose is not a pinch: never grabs anything
  const prog = Math.min(1, held / HOLD);
  if (held > 150) {
    octx.save();
    octx.lineWidth = 5; octx.lineCap = 'round'; octx.strokeStyle = '#A78BFA';
    octx.beginPath();
    octx.arc(c.x, c.y, Math.max(30, cand.p.size * 0.9), -Math.PI / 2, -Math.PI / 2 + Math.PI * 2 * Math.max(prog, 0.02));
    octx.stroke();
    octx.restore();
  }
  if (prog >= 1) { openWin(true); ps.need = true; ps.since = 0; note('AI chat opened'); }
}

// ====== pictures on the screen ======
const imgs = [];
let ig = null;       // the picture being moved / resized by hand
let spareQ = [];     // spare pictures for dead links

function put(o) {
  o.x = clamp(o.x, -o.w * 0.5, innerWidth - o.w * 0.5);
  o.y = clamp(o.y, -o.h * 0.5, innerHeight - o.h * 0.5);
  const s = o.el.style;
  s.left = Math.round(o.x) + 'px'; s.top = Math.round(o.y) + 'px'; s.width = Math.round(o.w) + 'px'; s.height = Math.round(o.h) + 'px';
}
function front(o) { document.body.appendChild(o.el); const i = imgs.indexOf(o); if (i >= 0) { imgs.splice(i, 1); imgs.push(o); } }
function removeImg(o) {
  const i = imgs.indexOf(o);
  if (i >= 0) imgs.splice(i, 1);
  o.el.remove();
  if (ig && ig.o === o) ig = null;
}
function clearImgs() { imgs.slice().forEach(removeImg); spareQ = []; }
function resizeImg(o, nw, cx, cy) {
  o.w = clamp(nw, 90, innerWidth * 0.98); o.h = o.w / o.ar;
  if (o.h > innerHeight * 0.98) { o.h = innerHeight * 0.98; o.w = o.h * o.ar; }
  o.x = cx - o.w / 2; o.y = cy - o.h / 2; put(o);
}

function sources(it) {
  const a = [];
  if (it.full) a.push(px(it.full));
  if (it.thumb && it.thumb !== it.full) a.push(px(it.thumb), it.thumb);
  return a;
}
function loadInto(o, it) {
  o.it = it; o.srcs = sources(it); o.si = 0;
  o.img.alt = it.title || '';
  o.cap.textContent = it.title || '';
  o.img.src = o.srcs[0] || '';
}
function makeImg(it, box) {
  const el = document.createElement('div');
  el.className = 'pcImg';
  el.innerHTML = '<img alt="" draggable="false" referrerpolicy="no-referrer"><button type="button" class="px" aria-label="Remove picture" title="Remove"><svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg></button><i class="prz" title="Drag to resize"></i><span class="cap"></span>';
  const ar = it.w > 0 && it.h > 0 ? clamp(it.w / it.h, 0.3, 3.5) : 1;
  let w = ar >= 1 ? box.s : box.s * ar, h = w / ar;
  const o = { el, img: el.querySelector('img'), cap: el.querySelector('.cap'), x: box.x + (box.s - w) / 2, y: box.y + (box.s - h) / 2, w, h, ar, xp: false, srcs: [], si: 0, it };
  o.img.onload = () => {
    if (o.img.naturalWidth > 0) {
      const cx = o.x + o.w / 2, cy = o.y + o.h / 2;
      o.ar = clamp(o.img.naturalWidth / o.img.naturalHeight, 0.2, 5);
      o.h = o.w / o.ar; o.x = cx - o.w / 2; o.y = cy - o.h / 2; put(o);
      el.classList.add('ready');
    }
  };
  o.img.onerror = () => {
    o.si++;
    if (o.si < o.srcs.length) o.img.src = o.srcs[o.si];
    else if (spareQ.length) loadInto(o, spareQ.shift());     // every link of this one is dead: use a spare
    else removeImg(o);
  };
  el.querySelector('.px').addEventListener('click', e => { e.stopPropagation(); removeImg(o); });
  mouseBind(o);
  document.body.appendChild(el);
  put(o);
  imgs.push(o);
  loadInto(o, it);
  return o;
}

function addImages(list, spare) {
  if (!list.length) return;
  spareQ = (spare || []).slice();
  while (imgs.length + list.length > MAX_PICS && imgs.length) removeImg(imgs[0]);
  const n = list.length, gap = 12, top = 70, margin = 24;
  const rightEdge = isOpen ? Math.max(260, W.x - margin) : innerWidth - margin;
  const availW = rightEdge - margin, availH = innerHeight - top - margin;
  let s = Math.min(300, availH), cols = 1, rows = 1;
  for (let k = 0; k < 30; k++) {
    cols = Math.max(1, Math.min(n, Math.floor((availW + gap) / (s + gap))));
    rows = Math.ceil(n / cols);
    if (rows * (s + gap) - gap <= availH || s <= 110) break;
    s = Math.max(110, Math.floor((availH + gap) / rows - gap));
  }
  const gw = cols * (s + gap) - gap, gh = rows * (s + gap) - gap;
  const x0 = margin + Math.max(0, (availW - gw) / 2), y0 = top + Math.max(0, (availH - gh) / 2);
  list.forEach((it, i) => {
    const c = i % cols, r = Math.floor(i / cols);
    makeImg(it, { x: x0 + c * (s + gap), y: y0 + r * (s + gap), s });
  });
}

const imgAt = (p, m) => { for (let i = imgs.length - 1; i >= 0; i--) { const o = imgs[i]; if (o.el.classList.contains('ready') && p.x >= o.x - m && p.x <= o.x + o.w + m && p.y >= o.y - m && p.y <= o.y + o.h + m) return o; } return null; };

function imgStep(hs, now) {
  if (!imgs.length && !ig) return;
  const pinchers = hs.filter(h => h.pinching && !heldByBub(h));

  // x button: a pinch on it removes the picture
  for (const o of imgs.slice()) {
    if (!o.el.classList.contains('ready')) continue;
    const xr = o.el.querySelector('.px').getBoundingClientRect();
    const on = pinchers.some(h => inR(h.pp, xr, 8));
    if (on && !o.xp) { o.xp = true; removeImg(o); continue; }
    o.xp = on;
  }

  if (ig) {
    const o = ig.o, near = (pt, lim) => { let b = null, bd = lim; pinchers.forEach(h => { const k = d2(h.pp, pt); if (k < bd) { bd = k; b = h; } }); return b; };
    let alive = false;
    if (ig.mode === 'pair') {
      const a = near(ig.la, 450);
      const b = pinchers.filter(h => h !== a).reduce((m, h) => (!m || d2(h.pp, ig.lb) < d2(m.pp, ig.lb) ? h : m), null);
      if (a && b && d2(b.pp, ig.lb) < 450) {
        const dd = d2(a.pp, b.pp), mx = (a.pp.x + b.pp.x) / 2, my = (a.pp.y + b.pp.y) / 2;
        resizeImg(o, ig.w0 * dd / ig.d0, ig.cx0 + mx - ig.m0.x, ig.cy0 + my - ig.m0.y);
        ig.la = a.pp; ig.lb = b.pp; ig.lost = 0; alive = true;
      }
    } else {
      const a = near(ig.last, 450);
      if (a) {
        ig.last = a.pp; ig.lost = 0; alive = true;
        if (ig.mode === 'move') {
          o.x = a.pp.x - ig.dx; o.y = a.pp.y - ig.dy; put(o);
          const b = pinchers.find(h => h !== a && inR(h.pp, { left: o.x, top: o.y, right: o.x + o.w, bottom: o.y + o.h }, 10) && d2(h.pp, a.pp) > 50);
          if (b) ig = { mode: 'pair', o, d0: d2(a.pp, b.pp), w0: o.w, cx0: o.x + o.w / 2, cy0: o.y + o.h / 2, m0: { x: (a.pp.x + b.pp.x) / 2, y: (a.pp.y + b.pp.y) / 2 }, la: a.pp, lb: b.pp, lost: 0 };
        } else {
          const dl = ((a.pp.x - ig.sx) + (a.pp.y - ig.sy) * o.ar) / 2;
          resizeImg(o, ig.w0 + dl, ig.cx, ig.cy);
        }
      }
    }
    if (!alive) {
      if (!ig.lost) ig.lost = now;
      if (now - ig.lost > 180) { o.el.classList.remove('held'); ig = null; }
    }
  } else if (pinchers.length && !busyOther() && !(isOpen && win && pinchers.some(h => inR(h.pp, win.getBoundingClientRect(), 10)))) {
    const first = pinchers.find(h => imgAt(h.pp, 6));
    if (first) {
      const o = imgAt(first.pp, 6);
      const other = pinchers.find(h => h !== first && imgAt(h.pp, 10) === o && d2(h.pp, first.pp) > 50);
      const rz = o.el.querySelector('.prz').getBoundingClientRect();
      front(o); o.el.classList.add('held');
      if (other) ig = { mode: 'pair', o, d0: d2(first.pp, other.pp), w0: o.w, cx0: o.x + o.w / 2, cy0: o.y + o.h / 2, m0: { x: (first.pp.x + other.pp.x) / 2, y: (first.pp.y + other.pp.y) / 2 }, la: first.pp, lb: other.pp, lost: 0 };
      else if (inR(first.pp, rz, 6)) ig = { mode: 'size', o, sx: first.pp.x, sy: first.pp.y, w0: o.w, cx: o.x + o.w / 2, cy: o.y + o.h / 2, last: first.pp, lost: 0 };
      else ig = { mode: 'move', o, dx: first.pp.x - o.x, dy: first.pp.y - o.y, last: first.pp, lost: 0 };
    }
  }
  // a pinch on a picture must not grab the boxes / circles underneath it
  hs.forEach(h => { if (h.pinching && imgAt(h.pp, 6)) h.pinching = false; });
}

// mouse / touch: drag, wheel = resize, corner = resize, x = remove
function mouseBind(o) {
  const el = o.el;
  let m = null;
  el.addEventListener('pointerdown', e => {
    if (e.target.closest('.px')) return;
    front(o);
    const corner = !!e.target.closest('.prz');
    m = corner ? { mode: 'size', sx: e.clientX, sy: e.clientY, w0: o.w, cx: o.x + o.w / 2, cy: o.y + o.h / 2 } : { mode: 'move', dx: e.clientX - o.x, dy: e.clientY - o.y };
    el.setPointerCapture(e.pointerId); el.classList.add('held'); e.stopPropagation(); e.preventDefault();
  });
  el.addEventListener('pointermove', e => {
    if (!m) return;
    if (m.mode === 'move') { o.x = e.clientX - m.dx; o.y = e.clientY - m.dy; put(o); }
    else resizeImg(o, m.w0 + ((e.clientX - m.sx) + (e.clientY - m.sy) * o.ar) / 2, m.cx, m.cy);
  });
  const end = () => { m = null; if (!(ig && ig.o === o)) el.classList.remove('held'); };
  el.addEventListener('pointerup', end); el.addEventListener('pointercancel', end);
  el.addEventListener('wheel', e => {
    e.preventDefault();
    resizeImg(o, o.w * (e.deltaY < 0 ? 1.1 : 1 / 1.1), o.x + o.w / 2, o.y + o.h / 2);
  }, { passive: false });
}
addEventListener('resize', () => imgs.forEach(put));

// ====== hook into the viewer's hand loop ======
function step(hs, now) {
  if (isOpen) windowInteract(hs, now);
  imgStep(hs, now);
  if (!isOpen) detect(hs, now);
}
const prevStep = window.scopeStep;   // the telescope / voice search from browser.php keeps working
window.scopeStep = (hs, now) => {
  if (prevStep) prevStep(hs, now);
  try { step(hs, now); } catch (e) { console.warn('ai chat', e); }
};

// ====== buttons + keys ======
(function addUi() {
  const bar = document.getElementById('bar');
  if (!bar) return;
  const b1 = document.createElement('button');
  b1.className = 'ghost'; b1.textContent = 'AI chat (A)';
  b1.onclick = e => { e.stopPropagation(); isOpen ? closeWin() : openWin(false); };
  const b2 = document.createElement('button');
  b2.className = 'ghost';
  const lab = () => { b2.textContent = 'Chat gesture: ' + (gestureOn ? 'on' : 'off') + ' (G)'; };
  b2.onclick = e => { e.stopPropagation(); gestureOn = !gestureOn; sv('pc_chatg', gestureOn ? '1' : '0'); lab(); };
  lab();
  const b3 = document.createElement('button');
  b3.className = 'ghost'; b3.textContent = 'AI chat settings';
  b3.onclick = e => { e.stopPropagation(); window.open(SETTINGS, '_blank'); };
  bar.append(b1, b2, b3);
  window.__chatToggleGesture = () => b2.click();
})();
document.addEventListener('keydown', e => {
  if (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.ctrlKey || e.metaKey || e.altKey) return;
  const k = e.key.toLowerCase();
  if (k === 'a') { isOpen ? closeWin() : openWin(false); }
  else if (k === 'g') { window.__chatToggleGesture && window.__chatToggleGesture(); }
});
})();
<?php
    exit;
}

/* the page inside the floating window */
if (isset($_GET['embed'])) {
    try { $pub = chatPublicCfg(chatCfg()); } catch (Throwable $e) { $pub = chatPublicCfg(chatDefaults()); }
    $js = fn($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI chat</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { color-scheme: dark; --bg:#0B1020; --surface:#121A2E; --surface-3:#1F2B49; --border:#2A3758; --text:#E8ECF8; --muted:#A3AFCC; --primary:#2563EB; --primary-hi:#3B7BFF; --ring:#93C5FD; --ok:#34D399; --danger:#F87171; }
  html, body { margin: 0; height: 100%; background: var(--bg); color: var(--text); font: 15px/1.45 Inter, system-ui, sans-serif; overflow: hidden; }
  body { display: flex; flex-direction: column; }
  svg { width: 1em; height: 1em; flex: none; }
  #msgs { flex: 1; min-height: 0; overflow-y: auto; padding: 14px 12px 6px; display: flex; flex-direction: column; gap: 10px; scroll-behavior: smooth; }
  .m { max-width: 86%; padding: 9px 13px; border-radius: 18px; white-space: pre-wrap; word-break: break-word; }
  .m.me { align-self: flex-end; background: var(--primary); color: #fff; border-bottom-right-radius: 4px; }
  .m.ai { align-self: flex-start; background: var(--surface); border: 1px solid var(--border); border-top-left-radius: 4px; }
  .m.wait { color: var(--muted); animation: blink 1.2s infinite; }
  .m.err { border-color: #B45353; background: rgba(127,29,29,.4); }
  .chip { align-self: flex-start; margin: -4px 0 0 6px; font-size: 12px; color: var(--muted); }
  .chip.bad { color: var(--danger); }
  @keyframes blink { 50% { opacity: .45; } }
  #sv { display: none; flex: 1; min-height: 0; background: #fff; margin: 8px 10px 0; border-radius: 12px; overflow: hidden; }
  #sv iframe { width: 100%; height: 100%; border: 0; display: block; }
  #st { flex: none; margin: 2px 14px 0; min-height: 16px; font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .inp { flex: none; padding: 4px 10px 0; }
  form { display: flex; align-items: center; gap: 6px; background: var(--surface); border: 1px solid var(--border); border-radius: 999px; padding: 3px 4px 3px 16px; }
  form:focus-within { border-color: var(--primary-hi); }
  #q { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; color: var(--text); font: 15px Inter, system-ui, sans-serif; padding: 7px 2px; }
  #q::placeholder { color: var(--muted); }
  .tools { flex: none; display: flex; gap: 6px; padding: 8px 10px 10px; flex-wrap: wrap; justify-content: center; }
  .rip { position: fixed; z-index: 49; width: 30px; height: 30px; margin: -15px 0 0 -15px; border-radius: 50%; border: 3px solid var(--ok); pointer-events: none; animation: rip .45s ease-out forwards; }
  @keyframes rip { from { scale: .3; opacity: 1; } to { scale: 1.6; opacity: 0; } }

  .hb { position: relative; overflow: hidden; min-width: 40px; height: 36px; padding: 0 12px; border-radius: 12px; font: inherit; font-weight: 500; font-size: 13px; color: var(--text); cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; background: var(--surface-3); border: 1px solid var(--border); transition: background .15s; }
  .hb:hover { background: #29395F; }
  .hb:focus-visible { outline: 2px solid var(--ring); outline-offset: 2px; }
  .hb.on { border-color: var(--ok); }
  .hb::before { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: calc(var(--p, 0) * 100%); background: rgba(52,211,153,.55); }
  .hb > span { position: relative; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
  .hb svg { font-size: 16px; }
  #go { background: var(--primary); border-color: var(--primary); color: #fff; border-radius: 999px; height: 34px; }
  #go:hover { background: var(--primary-hi); }
  #mic { width: 38px; height: 38px; min-width: 0; padding: 0; border-radius: 50%; }
  #mic svg { font-size: 18px; }
  #mic.live { background: #DC2626; border-color: #FCA5A5; color: #fff; animation: pulse 1.1s infinite; }
  @keyframes pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(220,38,38,.6); } 50% { box-shadow: 0 0 0 9px rgba(220,38,38,0); } }

  .cur { position: fixed; z-index: 50; width: 18px; height: 18px; margin: -9px 0 0 -9px; border-radius: 50%; border: 3px solid #fff; background: rgba(59,123,255,.6); pointer-events: none; display: none; }
  .cur.pinch { background: rgba(52,211,153,.9); border-color: var(--ok); scale: .8; }
  @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; scroll-behavior: auto !important; } }
</style>
</head>
<body>
<div id="msgs" role="log" aria-live="polite"></div>
<div id="sv"><iframe id="svf" title="AI chat settings"></iframe></div>
<div id="st"></div>
<div class="inp">
  <form id="f" autocomplete="off">
    <input id="q" type="text" placeholder="Say or type something…" aria-label="Message" spellcheck="false">
    <button type="button" id="mic" class="hb" data-act="mic" title="Speak / stop" aria-label="Speak or stop"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/></svg></span></button>
    <button type="submit" id="go" class="hb" data-act="go"><span>Send</span></button>
  </form>
</div>
<div class="tools">
  <button type="button" class="hb" data-act="up" data-rep="1" title="Scroll up" aria-label="Scroll up"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 15-6-6-6 6"/></svg></span></button>
  <button type="button" class="hb" data-act="down" data-rep="1" title="Scroll down" aria-label="Scroll down"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></span></button>
  <button type="button" class="hb" data-act="pics" title="Remove all pictures from the screen"><span>Clear pictures</span></button>
  <button type="button" class="hb" data-act="fresh" title="Start a new conversation"><span>New chat</span></button>
  <button type="button" class="hb" id="bSpeak" data-act="speak" title="Read replies aloud"><span>Voice: off</span></button>
  <button type="button" class="hb" id="bGear" data-act="gear" title="Settings"><span>Settings</span></button>
  <button type="button" class="hb" data-act="close" title="Close" aria-label="Close"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></span></button>
</div>
<i class="cur"></i><i class="cur"></i>

<script>
const $ = s => document.querySelector(s);
const CFG = <?= $js($pub) ?>;
const CSRF = <?= $js($chatCsrf) ?>;
const LANG = CFG.lang || navigator.language || 'en-US';
const inFrame = window.parent !== window;
const msgs = $('#msgs'), q = $('#q'), st = $('#st'), micBtn = $('#mic'), svBox = $('#sv'), svf = $('#svf');
const say = t => { st.textContent = t || ''; };
const up = m => { if (inFrame) parent.postMessage(m, location.origin); };
let hist = [], busy = false, listening = false, rec = null, gotError = '', heard = '', onSettings = false;
let speakOn = false;
try { const v = localStorage.getItem('pcc_speak'); speakOn = v === null ? CFG.speak === '1' : v === '1'; } catch (e) { speakOn = CFG.speak === '1'; }
const showSpeak = () => { const b = $('#bSpeak'); b.firstElementChild.textContent = 'Voice: ' + (speakOn ? 'on' : 'off'); b.classList.toggle('on', speakOn); };

// ---------- messages ----------
function addMsg(role, text) {
  const d = document.createElement('div');
  d.className = 'm ' + role; d.textContent = text;
  msgs.append(d); msgs.scrollTop = msgs.scrollHeight;
  return d;
}
function addChip(text, bad) {
  const c = document.createElement('div');
  c.className = 'chip' + (bad ? ' bad' : ''); c.textContent = text;
  msgs.append(c); msgs.scrollTop = msgs.scrollHeight;
}
function greet() {
  msgs.textContent = '';
  addMsg('ai', 'Hi! Ask me anything, or say something like “show me pictures of red pandas” and I will put them on the screen.');
}

// ---------- reading replies aloud ----------
function cancelSpeech() { try { speechSynthesis.cancel(); } catch (e) {} }
function speak(t) {
  if (!speakOn || !window.speechSynthesis) return;
  cancelSpeech();
  const u = new SpeechSynthesisUtterance(t); u.lang = LANG;
  try { speechSynthesis.speak(u); } catch (e) {}
}

// ---------- talking to the AI ----------
async function send(t) {
  t = (t || '').trim();
  if (!t || busy) return;
  if (onSettings) toggleSettings();
  q.value = ''; stopListen(); cancelSpeech();
  addMsg('me', t);
  const wait = addMsg('ai', '…'); wait.classList.add('wait');
  busy = true; say('Thinking…');

  const fd = new FormData();
  fd.append('message', t); fd.append('history', JSON.stringify(hist.slice(-10))); fd.append('csrf', CSRF);
  let r;
  try { r = await fetch('chat.php?api=ask', { method: 'POST', body: fd }).then(x => x.json()); }
  catch (e) { r = { ok: false, error: 'Could not reach the server.' }; }
  busy = false; wait.classList.remove('wait'); say('');

  if (r && r.ok) {
    wait.textContent = r.reply;
    const n = (r.images || []).length;
    hist.push({ role: 'user', text: t }, { role: 'model', text: r.reply + (n ? ' (I put ' + n + ' pictures of "' + r.query + '" on the screen.)' : '') });
    if (n) { up({ pcc: 'images', list: r.images, spare: r.spare || [] }); addChip(n + ' picture' + (n > 1 ? 's' : '') + ' on your screen: pinch to move, two hands to resize', false); }
    if (r.imgError) addChip(r.imgError, true);
    speak(r.reply);
  } else {
    wait.classList.add('err');
    wait.textContent = r && r.setup ? 'No AI is set up yet. Tap Settings below and add one on the AI tab.' : ((r && r.error) || 'Something went wrong.');
    if (r && r.notes && r.notes.length) say(r.notes[0]);
  }
  msgs.scrollTop = msgs.scrollHeight;
}

// ---------- settings (shown inside this window) ----------
function toggleSettings() {
  onSettings = !onSettings;
  msgs.style.display = onSettings ? 'none' : '';
  svBox.style.display = onSettings ? 'block' : 'none';
  $('#bGear').classList.toggle('on', onSettings);
  $('#bGear').firstElementChild.textContent = onSettings ? 'Back to chat' : 'Settings';
  if (onSettings) { stopListen(); if (!svf.getAttribute('src')) svf.src = 'chat.php?settings=1'; }
  else msgs.scrollTop = msgs.scrollHeight;
}

// ---------- speech to text ----------
const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
function phoneTrack() {
  try {
    if (CFG.phone_mic !== '1' || !inFrame) return null;
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
    if (t && !gotError) send(t);
    else if (!gotError) say("I didn't catch that. Tap the mic and try again.");
  };
  return r;
}
function startListen() {
  if (onSettings) toggleSettings();
  if (!SR) { say('Speech recognition needs Chrome or Edge (Firefox does not have it). You can still type.'); return; }
  if (listening || busy) return;
  cancelSpeech();
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
  go: () => { stopListen(); send(q.value); },
  up: () => msgs.scrollBy({ top: -240, behavior: 'smooth' }),
  down: () => msgs.scrollBy({ top: 240, behavior: 'smooth' }),
  pics: () => { up({ pcc: 'clearImgs' }); say('Pictures removed'); },
  fresh: () => { stopListen(); cancelSpeech(); hist = []; if (onSettings) toggleSettings(); greet(); say(''); },
  speak: () => { speakOn = !speakOn; try { localStorage.setItem('pcc_speak', speakOn ? '1' : '0'); } catch (e) {} showSpeak(); if (!speakOn) cancelSpeech(); },
  gear: toggleSettings,
  close: () => { stopListen(); cancelSpeech(); up({ pcc: 'close' }); },
};
document.querySelectorAll('.hb').forEach(b => {
  if (b.id === 'go') return;                       // the form submit handles Send
  b.addEventListener('click', () => act[b.dataset.act] && act[b.dataset.act]());
});
$('#f').addEventListener('submit', e => { e.preventDefault(); act.go(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') act.close(); });

// ---------- hands (sent by chat.php?js=1) ----------
const DWELL = 700;
const curEls = [...document.querySelectorAll('.cur')];
const dw = new Map(), latch = new Map(), pinchPrev = new Map();
let lastT = 0, scrollY = null, tap = null;
const setP = (el, v) => { v = Math.round(v * 50) / 50; if (el._p !== v) { el._p = v; el.style.setProperty('--p', v); } };

function clickAt(x, y) {                          // a quick pinch = a click at the fingertip
  const r = document.createElement('i');
  r.className = 'rip'; r.style.left = x + 'px'; r.style.top = y + 'px';
  document.body.appendChild(r); setTimeout(() => r.remove(), 500);
  const el = document.elementFromPoint(x, y);
  if (!el) return;
  if (el === svf) { say('Use the mouse and keyboard for the settings page.'); return; }
  try { el.focus && el.focus(); } catch (e) {}
  el.click();
}

function onHands(list, thumb) {
  const t = performance.now(), dt = Math.min(100, t - (lastT || t)); lastT = t;
  if (thumb && !onSettings) msgs.scrollTop += thumb * dt * 0.8;   // thumbs up: up, thumbs down: down
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

  // pinch + drag on the messages scrolls them; a quick pinch (tap) clicks what is under the fingertip
  const rr = (onSettings ? svBox : msgs).getBoundingClientRect();
  const p = list.find(h => h.pinch && inR(rr, h.px, h.py, 0));
  if (p && !overBtn && !onSettings) {
    if (scrollY === null) { scrollY = p.py; tap = { x: p.px, y: p.py, t, moved: false }; }
    else {
      msgs.scrollTop -= p.py - scrollY; scrollY = p.py;
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
  if (!m || !m.pcc) return;
  if (m.pcc === 'hand') onHands(m.hands || [], m.thumb || 0);
  else if (m.pcc === 'listen') { if (CFG.auto_listen === '1') startListen(); }
  else if (m.pcc === 'stop') { stopListen(); cancelSpeech(); onHands([], 0); }
});

greet(); showSpeak();
if (inFrame) up({ pcc: 'ready' });
</script>
</body>
</html>
<?php
    exit;
}

/* the settings page */
if (isset($_GET['settings'])) {
    if (!aikitIsAdmin()) { http_response_code(403); exit('Admins only.'); }
    try { chatCfg(); } catch (Throwable $e) { /* the page shows the error when it loads */ }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI chat settings</title>
<style>
:root{--bg:#f5f5f3;--card:#fff;--line:#d9d9d4;--text:#151515;--muted:#5d5d58;--accent:#151515;--on:#fff;--bad:#c4242a;--ok:#247b50;color-scheme:light}
@media(prefers-color-scheme:dark){:root{--bg:#121212;--card:#1f1f1f;--line:#3a3a3a;--text:#f2f1ee;--muted:#a9a7a1;--accent:#f2f1ee;--on:#121212;--bad:#ff9aa0;--ok:#4cc38a;color-scheme:dark}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:820px;margin:0 auto;padding:20px 16px 60px}h1{font-size:1.4rem;margin:0 0 4px}p.sub{color:var(--muted);margin:0 0 16px}
.tabs{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap}
.tabs button{height:36px;padding:0 14px;border:1px solid var(--line);background:var(--card);color:var(--text);border-radius:8px;font:inherit;cursor:pointer}
.tabs button[aria-selected=true]{background:var(--accent);color:var(--on);border-color:var(--accent)}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px;margin-bottom:14px}
.row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}.row+.row{margin-top:10px}.grow{flex:1;min-width:150px}
label{display:block;font-size:.8rem;color:var(--muted);margin-bottom:3px}
input[type=text],input[type=password],input[type=number],select,textarea{width:100%;padding:0 10px;background:var(--card);color:var(--text);border:1px solid var(--line);border-radius:6px;font:inherit}
input[type=text],input[type=password],input[type=number],select{height:36px}textarea{padding:8px 10px;min-height:96px;resize:vertical}
.chk{display:flex;gap:8px;align-items:center;color:var(--text);font-size:.95rem;margin:0}.chk input{width:18px;height:18px}
button.b{height:34px;padding:0 14px;border:1px solid var(--line);background:var(--card);color:var(--text);border-radius:6px;font:inherit;cursor:pointer}
button.b.p{background:var(--accent);color:var(--on);border-color:var(--accent)}button.b:disabled{opacity:.45}
.meta{color:var(--muted);font-size:.88rem}.help{color:var(--muted);font-size:.88rem;margin:8px 0 0}
#msg{min-height:20px;margin:10px 0 0;font-size:.9rem}.err{color:var(--bad)}.good{color:var(--ok)}
.thumbs{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.thumbs img{height:90px;border-radius:6px;border:1px solid var(--line);background:var(--bg)}
iframe#aif{width:100%;height:760px;border:1px solid var(--line);border-radius:10px;background:var(--bg)}
[hidden]{display:none!important}
</style>
</head>
<body>
<main>
<h1>AI chat settings</h1>
<p class="sub">Everything the hand-gesture chat needs, in one place.</p>

<div class="tabs" role="tablist">
  <button type="button" role="tab" id="t-ai" aria-selected="true" aria-controls="p-ai">AI</button>
  <button type="button" role="tab" id="t-pic" aria-selected="false" aria-controls="p-pic">Pictures</button>
  <button type="button" role="tab" id="t-bot" aria-selected="false" aria-controls="p-bot">Assistant</button>
</div>

<section id="p-ai" role="tabpanel" aria-labelledby="t-ai">
  <p class="meta" id="aiStatus">Checking…</p>
  <iframe id="aif" src="aikit.php" title="AI setup"></iframe>
  <p class="help">This is <b>aikit.php</b>: pick a service, paste the key, press Load models, Test, then Save. The top AI is used first; if it fails, the next one answers.
    <a href="aikit.php" target="_blank">Open it in its own tab</a></p>
</section>

<section id="p-pic" role="tabpanel" aria-labelledby="t-pic" hidden>
  <div class="card">
    <div class="row">
      <div class="grow"><label for="provider">Picture search service</label><select id="provider"></select></div>
      <div style="width:130px"><label for="count">Pictures per request</label><input type="number" id="count" min="1" max="8"></div>
    </div>
    <div class="row" id="keyRow">
      <div class="grow" style="flex:2"><label for="key">API key <span id="keyhint"></span></label><input type="password" id="key" autocomplete="off"></div>
    </div>
    <div class="row" id="baseRow" hidden>
      <div class="grow" style="flex:2"><label for="base">SearXNG address</label><input type="text" id="base" placeholder="http://localhost:8080"></div>
    </div>
    <div class="row"><label class="chk"><input type="checkbox" id="safe"> Safe search</label></div>
    <p class="help" id="provHelp"></p>
    <div class="row">
      <button type="button" class="b" id="test">Test with "red panda"</button>
    </div>
    <div class="thumbs" id="thumbs"></div>
  </div>
  <p class="help">Google&rsquo;s own image search API is closed to new customers and is switching off on January 1, 2027, so the options above are the practical ways to get pictures now.
    Picture search is only used when you ask for pictures; ordinary conversation never touches it.</p>
</section>

<section id="p-bot" role="tabpanel" aria-labelledby="t-bot" hidden>
  <div class="card">
    <div class="row"><div class="grow"><label for="sys">Extra instructions for the assistant (optional)</label>
      <textarea id="sys" maxlength="2000" placeholder="e.g. Call me Sam. Keep answers under two sentences."></textarea></div></div>
    <div class="row"><div class="grow"><label for="lang">Speech language (voice input and spoken replies)</label>
      <input type="text" id="lang" maxlength="20" placeholder="empty = your browser's language; or en-US, en-PH, fil-PH"></div></div>
    <div class="row"><label class="chk"><input type="checkbox" id="auto"> Start listening as soon as the gesture opens the chat</label></div>
    <div class="row"><label class="chk"><input type="checkbox" id="phone"> Listen through the phone&rsquo;s microphone when the browser allows it</label></div>
    <div class="row"><label class="chk"><input type="checkbox" id="speak"> Read replies aloud (can also be switched in the chat window)</label></div>
  </div>
</section>

<div id="saveBar" hidden><button type="button" class="b p" id="save">Save</button></div>
<div id="msg" role="status"></div>
</main>

<script>
const CSRF = <?= json_encode($chatCsrf, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const $ = id => document.getElementById(id);
const HELP = {
  none: 'Pictures are off. The chat still works as a normal conversation.',
  brave: 'Get a key at brave.com/search/api (self-serve). It searches Brave\u2019s own index, so results are not Google\u2019s.',
  serper: 'Get a key at serper.dev. It returns real Google image results; it is a paid service with some free credits to start.',
  serpapi: 'Get a key at serpapi.com. It returns real Google Images results; it is a paid service with a small free allowance.',
  searxng: 'Runs on your own computer or server. The instance must allow the json format (search > formats in its settings.yml).',
};
let DATA = null;

async function api(action, data = {}) {
  const fd = new FormData();
  fd.append('csrf', CSRF);
  for (const k in data) fd.append(k, data[k]);
  try { return await (await fetch('chat.php?api=' + action, { method: 'POST', body: fd })).json(); }
  catch (e) { return { ok: false, error: 'Could not reach the server.' }; }
}
function say(t, bad) { $('msg').textContent = t; $('msg').className = bad ? 'err' : 'good'; }

// tabs
const tabs = ['ai', 'pic', 'bot'];
function showTab(k) {
  tabs.forEach(t => { $('t-' + t).setAttribute('aria-selected', String(t === k)); $('p-' + t).hidden = t !== k; });
  $('saveBar').hidden = k === 'ai';
  say('');
}
tabs.forEach(t => $('t-' + t).onclick = () => showTab(t));

function provUi() {
  const p = $('provider').value;
  $('keyRow').hidden = p === 'none' || p === 'searxng';
  $('baseRow').hidden = p !== 'searxng';
  $('provHelp').textContent = HELP[p] || '';
  $('test').disabled = p === 'none';
}
function fill(cfg) {
  $('provider').value = cfg.img_provider; $('count').value = cfg.img_count; $('base').value = cfg.img_base;
  $('safe').checked = cfg.img_safe === '1'; $('key').value = '';
  $('keyhint').textContent = cfg.has_key ? '(leave empty to keep ' + cfg.key_hint + ')' : '';
  $('sys').value = cfg.sys_prompt; $('lang').value = cfg.lang;
  $('auto').checked = cfg.auto_listen === '1'; $('phone').checked = cfg.phone_mic === '1'; $('speak').checked = cfg.speak === '1';
  provUi();
}
function form() {
  return { img_provider: $('provider').value, img_key: $('key').value, img_base: $('base').value, img_safe: $('safe').checked ? '1' : '0',
           img_count: $('count').value, sys_prompt: $('sys').value, lang: $('lang').value,
           auto_listen: $('auto').checked ? '1' : '0', phone_mic: $('phone').checked ? '1' : '0', speak: $('speak').checked ? '1' : '0' };
}

async function load() {
  const r = await fetch('chat.php?api=cfg_get').then(x => x.json()).catch(() => null);
  if (!r || !r.ok) { $('aiStatus').textContent = (r && r.error) || 'Could not load the settings.'; return say((r && r.error) || 'Could not load the settings.', true); }
  DATA = r;
  for (const k in r.providers) { const o = document.createElement('option'); o.value = k; o.textContent = r.providers[k]; $('provider').append(o); }
  fill(r.cfg);
  $('aiStatus').textContent = r.ai_count > 0
    ? r.ai_count + ' AI' + (r.ai_count > 1 ? 's are' : ' is') + ' ready to answer.'
    : 'No AI is set up yet. Add one below: pick a service, paste the key, Load models, Test, Save.';
}

$('provider').onchange = () => { provUi(); $('thumbs').textContent = ''; };
$('save').onclick = async () => {
  const r = await api('cfg_save', form());
  if (r.ok) { fill(r.cfg); say('Saved.', false); } else say(r.error, true);
};
$('test').onclick = async () => {
  say('Searching…'); $('thumbs').textContent = ''; $('test').disabled = true;
  const f = form();
  const r = await api('img_test', { img_provider: f.img_provider, img_key: f.img_key, img_base: f.img_base, img_safe: f.img_safe });
  $('test').disabled = false;
  if (!r.ok) return say(r.error, true);
  say('Works! ' + r.images.length + ' pictures in ' + r.ms + ' ms. Save to keep these settings.', false);
  r.images.forEach(it => {
    const i = document.createElement('img'); i.alt = it.title || ''; i.src = 'chat.php?px=' + encodeURIComponent(it.thumb);
    i.onerror = () => { i.onerror = null; i.src = it.thumb; };
    $('thumbs').append(i);
  });
};
// reload the AI count when coming back to the first tab (the setup page may have changed it)
$('t-ai').addEventListener('click', () => { fetch('chat.php?api=cfg_get').then(x => x.json()).then(r => { if (r.ok) $('aiStatus').textContent = r.ai_count > 0 ? r.ai_count + ' AI' + (r.ai_count > 1 ? 's are' : ' is') + ' ready to answer.' : 'No AI is set up yet.'; }).catch(() => {}); });
load();
</script>
</body>
</html>
<?php
    exit;
}

/* nothing asked for: go to the settings */
header('Location: chat.php?settings=1');
