<?php
// Works out the address your PHONE should use to reach this folder, and remembers it in XAMPP's MySQL.
//  - If you opened the viewer through a real domain, that domain is used.
//  - If you opened it through localhost / a LAN address, your public (WAN) IP is detected from the server
//    (cached in the database for 5 minutes, so it follows IP changes by itself).
//  - A manual override (set from the viewer page) always wins; clear it to go back to automatic.
// Default XAMPP MySQL login is root with an empty password; change below if yours differs.
const DB_HOST = '127.0.0.1';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'phonecam';
const IP_CACHE_SECONDS = 300;

header('Content-Type: application/json');
header('Cache-Control: no-store');

// ---------- storage: MySQL, with a temp-file fallback if MySQL isn't running ----------
$pdo = null;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4');
    $pdo->exec('USE `' . DB_NAME . '`');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k VARCHAR(40) PRIMARY KEY, v TEXT NOT NULL, updated_at INT NOT NULL)');
} catch (Throwable $e) {
    $pdo = null;
}
$storeFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phonecam_settings.json';

function kv_get($k) {
    global $pdo, $storeFile;
    if ($pdo) {
        $s = $pdo->prepare('SELECT v, updated_at FROM settings WHERE k = ?');
        $s->execute([$k]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? ['v' => $r['v'], 't' => (int)$r['updated_at']] : null;
    }
    $d = is_file($storeFile) ? json_decode(file_get_contents($storeFile), true) : [];
    return isset($d[$k]) ? $d[$k] : null;
}
function kv_set($k, $v) {
    global $pdo, $storeFile;
    if ($pdo) {
        $s = $pdo->prepare('REPLACE INTO settings (k, v, updated_at) VALUES (?, ?, ?)');
        $s->execute([$k, $v, time()]);
        return;
    }
    $d = is_file($storeFile) ? json_decode(file_get_contents($storeFile), true) : [];
    if (!is_array($d)) $d = [];
    $d[$k] = ['v' => $v, 't' => time()];
    file_put_contents($storeFile, json_encode($d), LOCK_EX);
}
function kv_del($k) {
    global $pdo, $storeFile;
    if ($pdo) { $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]); return; }
    $d = is_file($storeFile) ? json_decode(file_get_contents($storeFile), true) : [];
    if (is_array($d)) { unset($d[$k]); file_put_contents($storeFile, json_encode($d), LOCK_EX); }
}

// ---------- helpers ----------
function http_get($url) {
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'phonecam']);
        $r = curl_exec($c);
        curl_close($c);
        return is_string($r) ? $r : null;
    }
    $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 4], 'ssl' => ['verify_peer' => false]]));
    return $r === false ? null : $r;
}
function public_ip($force = false) {
    $c = kv_get('public_ip');
    if (!$force && $c && time() - $c['t'] < IP_CACHE_SECONDS) return $c['v'];
    foreach (['https://api.ipify.org', 'https://ifconfig.me/ip', 'https://icanhazip.com'] as $u) {
        $r = trim((string)http_get($u));
        if (filter_var($r, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { kv_set('public_ip', $r); return $r; }
    }
    return $c ? $c['v'] : null; // detection failed: reuse the last known IP if there is one
}
function is_local_host($h) {
    $h = strtolower(trim($h, '[]'));
    if ($h === 'localhost' || $h === '::1' || strpos($h, '.') === false && !filter_var($h, FILTER_VALIDATE_IP)) return true;
    if (filter_var($h, FILTER_VALIDATE_IP)) return !filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    return preg_match('/\.(local|lan|home|internal)$/', $h) === 1;
}

// ---------- request ----------
$action = $_REQUEST['action'] ?? 'get';

if ($action === 'set') {
    $v = trim((string)($_REQUEST['host'] ?? ''));
    if ($v === '') kv_del('custom_base');
    else {
        if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
        $v = preg_replace('~/(?:viewer|camera|address)\.php.*$~i', '/', $v);
        if (!filter_var($v, FILTER_VALIDATE_URL)) { http_response_code(400); exit('{"error":"bad address"}'); }
        kv_set('custom_base', rtrim($v, '/') . '/');
    }
    // fall through to return the new state
} elseif ($action === 'refresh') {
    public_ip(true);
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
$scheme = $https ? 'https' : 'http';
$reqHost = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
$port = (int)($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));
$portPart = ($port === 80 && !$https) || ($port === 443 && $https) ? '' : ':' . $port;
$path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';

$custom = kv_get('custom_base');
$ip = null; $source = '';
if ($custom) {
    $base = $custom['v']; $source = 'custom';
} elseif (!is_local_host($reqHost)) {
    $base = "$scheme://$reqHost$portPart$path"; $source = 'request';
} elseif (($ip = public_ip())) {
    $h = strpos($ip, ':') !== false ? "[$ip]" : $ip;
    $base = "$scheme://$h$portPart$path"; $source = 'wan-ip';
} else {
    $base = ''; $source = 'none'; // viewer falls back to its own address
}

echo json_encode([
    'base' => $base,
    'source' => $source,
    'custom' => $custom ? $custom['v'] : '',
    'ip' => $ip,
    'storage' => $pdo ? 'mysql' : 'file',
]);
