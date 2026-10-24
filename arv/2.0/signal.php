<?php
// Tiny WebRTC signaling relay. Messages are kept in temp files for up to 10 minutes.
header('Content-Type: application/json');
header('Cache-Control: no-store');

$room = preg_replace('/[^A-Za-z0-9]/', '', $_REQUEST['room'] ?? '');
if (strlen($room) < 8 || strlen($room) > 32) {
    http_response_code(400);
    exit('{"error":"bad room"}');
}
$to = ($_REQUEST['to'] ?? '') === 'camera' ? 'camera' : 'viewer';
$action = $_REQUEST['action'] ?? '';

function box_file($room, $box) {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "phonecam_{$room}_{$box}.json";
}

function locked($file, $fn) {
    $fp = fopen($file, 'c+');
    if (!$fp) { http_response_code(500); exit('{"error":"storage"}'); }
    flock($fp, LOCK_EX);
    $d = json_decode(stream_get_contents($fp), true);
    if (!is_array($d)) $d = ['last' => (int)(microtime(true) * 1000), 'msgs' => []];
    $now = time();
    $d['msgs'] = array_values(array_filter($d['msgs'], fn($m) => $now - $m['t'] < 600));
    $res = $fn($d);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($d));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $res;
}

if ($action === 'send') {
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 60000 || json_decode($raw) === null) {
        http_response_code(400);
        exit('{"error":"bad message"}');
    }
    $msg = json_decode($raw, true);
    locked(box_file($room, $to), function (&$d) use ($msg) {
        $id = max($d['last'] + 1, (int)(microtime(true) * 1000));
        $d['last'] = $id;
        $d['msgs'][] = ['id' => $id, 't' => time(), 'msg' => $msg];
        if (count($d['msgs']) > 200) $d['msgs'] = array_slice($d['msgs'], -200);
    });
    echo '{"ok":true}';
} elseif ($action === 'poll') {
    $since = (int)($_REQUEST['since'] ?? 0);
    $out = locked(box_file($room, $to), function (&$d) use ($since) {
        return array_values(array_filter($d['msgs'], fn($m) => $m['id'] > $since));
    });
    echo json_encode(['msgs' => $out]);
} elseif ($action === 'reset') {
    foreach (['viewer', 'camera'] as $b) {
        locked(box_file($room, $b), function (&$d) { $d['msgs'] = []; });
    }
    echo '{"ok":true}';
} else {
    http_response_code(400);
    echo '{"error":"unknown action"}';
}
