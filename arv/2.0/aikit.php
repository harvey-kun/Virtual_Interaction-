<?php
/*
    AI KIT - one file, "bring your own AI" for any PHP website.

    SETUP: copy this file into the site. That's all. Open it in the browser (yoursite.com/aikit.php)
    to add AIs: pick a service, paste the key, Load models, Test, Save.

    DATABASE (automatic, nothing to create by hand):
      - the site already has a mysqli connection called $conn  -> it is reused
      - or fill in AIKIT_DB_* below                             -> MySQL
      - or neither                                              -> a private SQLite file is created
    Tables made automatically:  aikit_providers (the AIs)  and  aikit_hits (rate limit, the safety net).

    USE FROM PHP:
        require "aikit.php";
        $r = aiAsk("Summarise this...", "You are a summariser.");      // optional 3rd argument: history
        echo $r["ok"] ? $r["reply"] : "The AI is busy";

    USE FROM JAVASCRIPT:
        const fd = new FormData(); fd.append("message", "Hello");
        const r = await fetch("aikit.php?ask", {method: "POST", body: fd}).then(x => x.json());
        // {ok:true, reply:"..."}

    Every setting below can also be defined BEFORE including this file, so the file itself never needs editing.
*/

/* ---------- Settings ---------- */
if (!defined("AIKIT_OPEN"))  define("AIKIT_OPEN", true);   // true = no login needed (own computer / testing). Set false + edit the two functions below for a public site.

if (!defined("AI_API_KEY"))  define("AI_API_KEY", getenv("AI_API_KEY") ?: "");   // optional default AI, always tried last
if (!defined("AI_MODEL"))    define("AI_MODEL", "");                              // e.g. "gemini-flash-latest"

if (!defined("AIKIT_DB_NAME")) define("AIKIT_DB_NAME", "phonecam");        // empty = reuse $conn, or SQLite when there is none
if (!defined("AIKIT_DB_HOST")) define("AIKIT_DB_HOST", "127.0.0.1");
if (!defined("AIKIT_DB_USER")) define("AIKIT_DB_USER", "root");
if (!defined("AIKIT_DB_PASS")) define("AIKIT_DB_PASS", "");
if (!defined("AIKIT_DATA_DIR")) define("AIKIT_DATA_DIR", "");      // SQLite folder; empty = a folder next to (preferably above) the web root

if (!defined("AIKIT_SYSTEM"))        define("AIKIT_SYSTEM", "You are a helpful assistant on this website. Answer clearly and briefly.");
if (!defined("AIKIT_RATE_PER_MIN"))  define("AIKIT_RATE_PER_MIN", 15);   // questions per visitor (IP) per minute; 0 = off
if (!defined("AI_TRIES"))    define("AI_TRIES", 2);      // tries on one AI before moving to the next
if (!defined("AI_BUDGET"))   define("AI_BUDGET", 150);   // most seconds one question may take in total
if (!defined("AI_TIMEOUT"))  define("AI_TIMEOUT", 90);   // most seconds one single request may take
if (!defined("AI_MAX_TOKENS")) define("AI_MAX_TOKENS", 1500);
// define("AI_CA_BUNDLE", "C:/xampp/php/extras/ssl/cacert.pem");   // only if XAMPP/Windows says "SSL certificate problem"

/* ---------- Who may do what (only used when AIKIT_OPEN is false) ---------- */
function aikitSession(): void {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) @session_start();
}
if (!function_exists("aikitIsAdmin")) {
    function aikitIsAdmin(): bool {
        if (AIKIT_OPEN) return true;
        aikitSession();
        return !empty($_SESSION["is_admin"]);       // adapt to the site's login
    }
}
if (!function_exists("aikitCanAsk")) {
    function aikitCanAsk(): bool {
        if (AIKIT_OPEN) return true;
        aikitSession();
        return !empty($_SESSION["user_id"]);        // adapt, or return true to let everyone ask
    }
}
function aikitCsrfToken(): string {
    aikitSession();
    if (empty($_SESSION["aikit_csrf"])) $_SESSION["aikit_csrf"] = bin2hex(random_bytes(24));
    return $_SESSION["aikit_csrf"];
}
function aikitCsrfOk(): bool {
    if (AIKIT_OPEN) return true;                    // anyone can call it anyway
    aikitSession();
    $sent = (string)($_POST["csrf"] ?? ($_SERVER["HTTP_X_CSRF_TOKEN"] ?? ""));
    return $sent !== "" && !empty($_SESSION["aikit_csrf"]) && hash_equals($_SESSION["aikit_csrf"], $sent);
}

/* ---------- Database: MySQL ($conn / settings) or SQLite, tables created on first use ---------- */

function aikitDataDir(): string {
    $cands = [];
    if (AIKIT_DATA_DIR !== "") $cands[] = AIKIT_DATA_DIR;
    $root = rtrim((string)($_SERVER["DOCUMENT_ROOT"] ?? ""), "/\\");
    if ($root !== "") $cands[] = dirname($root) . "/aikit-data";     // outside the web root: not downloadable
    $cands[] = __DIR__ . "/aikit-data";

    foreach ($cands as $d) {
        if (!is_dir($d)) @mkdir($d, 0700, true);
        if (is_dir($d) && is_writable($d)) {
            // in case the folder IS reachable from the web: block it (Apache) and hide the listing
            if (!is_file($d . "/.htaccess")) @file_put_contents($d . "/.htaccess", "Require all denied\nDeny from all\n");
            if (!is_file($d . "/index.html")) @file_put_contents($d . "/index.html", "");
            return $d;
        }
    }
    throw new RuntimeException("No writable folder for the SQLite file. Set AIKIT_DATA_DIR, or AIKIT_DB_* for MySQL.");
}

function aikitDb(): array {
    static $db = null;
    if ($db !== null) return $db;

    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        $db = ["t" => "mysql", "h" => $conn];
    } elseif (AIKIT_DB_NAME !== "") {
        try {   // make sure the database exists (XAMPP's default root login can create it)
            $boot = @new mysqli(AIKIT_DB_HOST, AIKIT_DB_USER, AIKIT_DB_PASS);
            if (!$boot->connect_error) {
                $boot->query("CREATE DATABASE IF NOT EXISTS `" . str_replace("`", "", AIKIT_DB_NAME) . "` CHARACTER SET utf8mb4");
                $boot->close();
            }
        } catch (Throwable $e) { /* the real connection below reports the problem */ }
        $h = @new mysqli(AIKIT_DB_HOST, AIKIT_DB_USER, AIKIT_DB_PASS, AIKIT_DB_NAME);
        if ($h->connect_error) throw new RuntimeException("Database connection failed: " . $h->connect_error);
        $h->set_charset("utf8mb4");
        $db = ["t" => "mysql", "h" => $h];
    } else {
        if (!extension_loaded("pdo_sqlite")) throw new RuntimeException("No database found: the site has no \$conn, AIKIT_DB_* is empty and PHP has no pdo_sqlite.");
        $pdo = new PDO("sqlite:" . aikitDataDir() . "/aikit.sqlite");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db = ["t" => "sqlite", "h" => $pdo];
    }

    if ($db["t"] === "sqlite") {
        aikitExec("CREATE TABLE IF NOT EXISTS aikit_providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'openai',
            base_url TEXT NOT NULL DEFAULT '', api_key TEXT NOT NULL DEFAULT '', model TEXT NOT NULL DEFAULT '',
            priority INTEGER NOT NULL DEFAULT 10, enabled INTEGER NOT NULL DEFAULT 1, cooldown_until INTEGER NOT NULL DEFAULT 0,
            last_status TEXT NOT NULL DEFAULT '', last_used_at INTEGER NOT NULL DEFAULT 0)");
        aikitExec("CREATE TABLE IF NOT EXISTS aikit_hits (k TEXT NOT NULL, t INTEGER NOT NULL)");
        aikitExec("CREATE INDEX IF NOT EXISTS aikit_hits_k ON aikit_hits (k, t)");
    } else {
        try {
            aikitExec("CREATE TABLE IF NOT EXISTS aikit_providers (
                id INT NOT NULL AUTO_INCREMENT, name VARCHAR(60) NOT NULL, kind VARCHAR(12) NOT NULL DEFAULT 'openai',
                base_url VARCHAR(255) NOT NULL DEFAULT '', api_key VARCHAR(500) NOT NULL DEFAULT '', model VARCHAR(120) NOT NULL DEFAULT '',
                priority INT NOT NULL DEFAULT 10, enabled TINYINT NOT NULL DEFAULT 1, cooldown_until INT NOT NULL DEFAULT 0,
                last_status VARCHAR(255) NOT NULL DEFAULT '', last_used_at INT NOT NULL DEFAULT 0, PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            aikitExec("CREATE TABLE IF NOT EXISTS aikit_hits (k VARCHAR(40) NOT NULL, t INT NOT NULL, KEY aikit_hits_k (k, t)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { /* created by a parallel request */ }
    }
    return $db;
}

function aikitRun(string $sql, array $p, bool $wantRows) {
    $db = aikitDb();
    if ($db["t"] === "sqlite") {
        $st = $db["h"]->prepare($sql);
        $st->execute(array_values($p));
        return $wantRows ? $st->fetchAll(PDO::FETCH_ASSOC) : $st->rowCount();
    }
    $st = $db["h"]->prepare($sql);
    if (!$st) throw new RuntimeException($db["h"]->error);
    if ($p) { $p = array_values($p); $st->bind_param(str_repeat("s", count($p)), ...$p); }
    if (!$st->execute()) throw new RuntimeException($st->error);
    if ($wantRows) {
        $res  = $st->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $st->close();
        return $rows;
    }
    $n = $st->affected_rows;
    $st->close();
    return $n;
}
function aikitRows(string $sql, array $p = []): array { return aikitRun($sql, $p, true); }
function aikitExec(string $sql, array $p = []): int   { return (int)aikitRun($sql, $p, false); }
function aikitLastId(): int {
    $db = aikitDb();
    return $db["t"] === "sqlite" ? (int)$db["h"]->lastInsertId() : (int)$db["h"]->insert_id;
}

/* Safety net: at most AIKIT_RATE_PER_MIN questions per visitor (IP) per minute. REMOTE_ADDR only: proxy headers can be faked. */
function aikitRateOk(): bool {
    $max = (int)AIKIT_RATE_PER_MIN;
    if ($max <= 0) return true;
    $k   = substr(sha1((string)($_SERVER["REMOTE_ADDR"] ?? "?")), 0, 40);
    $now = time();
    aikitExec("DELETE FROM aikit_hits WHERE t < ?", [$now - 120]);
    $r = aikitRows("SELECT COUNT(*) AS c FROM aikit_hits WHERE k = ? AND t > ?", [$k, $now - 60]);
    if ((int)($r[0]["c"] ?? 0) >= $max) return false;
    aikitExec("INSERT INTO aikit_hits (k, t) VALUES (?, ?)", [$k, $now]);
    return true;
}

/* =====================================================================
   Providers: talking to ONE AI service (Gemini, OpenAI-compatible, Claude)
   ===================================================================== */

/*
    Talks to ONE AI service. Three families cover almost every provider on the market:

      gemini     Google Gemini
      openai     OpenAI and every "OpenAI-compatible" API (Groq, OpenRouter, Mistral, DeepSeek, xAI, Ollama ...)
      anthropic  Anthropic Claude

    The rest of the site never builds provider requests itself: it hands over a neutral conversation
    ("ctx") and gets text back. Used by the switchboard below.

    ctx = [
        "system" => "instructions",
        "msgs"   => [ ["role" => "user"|"model", "parts" => [ part, ... ]], ... ],
    ]
    part = ["t"=>"text","text"=>..] | ["t"=>"image","mime"=>..,"b64"=>..] | ["t"=>"pdf","b64"=>..] | ["t"=>"audio","mime"=>..,"b64"=>..]
*/

if (!defined("AI_MAX_TOKENS")) define("AI_MAX_TOKENS", 1500);   // longest answer, in tokens

function aiKinds(): array {
    return [
        "gemini"    => "Google Gemini",
        "openai"    => "OpenAI-compatible",
        "anthropic" => "Anthropic Claude",
    ];
}

function aiDefaultBase(string $kind): string {
    return [
        "gemini"    => "https://generativelanguage.googleapis.com/v1beta",
        "openai"    => "https://api.openai.com/v1",
        "anthropic" => "https://api.anthropic.com/v1",
    ][$kind] ?? "";
}

function aiDefaultModel(string $kind): string {
    return ["gemini" => "gemini-flash-latest", "openai" => "gpt-4o-mini", "anthropic" => "claude-haiku-5-5"][$kind] ?? "";
}

/* Ready-made choices for the admin form: picking one fills in the type and address. [label, kind, base url, suggested model] */
function aiPresets(): array {
    return [
        "gemini"     => ["Google Gemini",        "gemini",    aiDefaultBase("gemini"),    "gemini-flash-latest"],
        "openai"     => ["OpenAI",               "openai",    "https://api.openai.com/v1", "gpt-4o-mini"],
        "anthropic"  => ["Anthropic Claude",     "anthropic", aiDefaultBase("anthropic"), "claude-haiku-5-5"],
        "groq"       => ["Groq",                 "openai",    "https://api.groq.com/openai/v1", "llama-3.3-70b-versatile"],
        "openrouter" => ["OpenRouter",           "openai",    "https://openrouter.ai/api/v1", "openrouter/auto"],
        "mistral"    => ["Mistral",              "openai",    "https://api.mistral.ai/v1", "mistral-small-latest"],
        "deepseek"   => ["DeepSeek",             "openai",    "https://api.deepseek.com/v1", "deepseek-chat"],
        "xai"        => ["xAI Grok",             "openai",    "https://api.x.ai/v1", ""],
        "cerebras"   => ["Cerebras",             "openai",    "https://api.cerebras.ai/v1", ""],
        "together"   => ["Together AI",          "openai",    "https://api.together.xyz/v1", ""],
        "ollama"     => ["Ollama (this computer)", "openai",  "http://localhost:11434/v1", ""],
        "custom"     => ["Other (OpenAI-compatible)", "openai", "", ""],
    ];
}

/* Guess the family from the model name / key, so config.php only needs a key and a model */
function aiGuessKind(string $model, string $key): string {
    if (preg_match('/^(models\/)?(gemini|gemma)/i', $model)) return "gemini";
    if (preg_match('/^claude/i', $model))                    return "anthropic";
    if (strncmp($key, "AIza", 4) === 0)                      return "gemini";
    if (strncmp($key, "sk-ant-", 7) === 0)                   return "anthropic";
    return "openai";
}

/* Web address for a key + family (OpenRouter / Groq / xAI keys have a telltale start) */
function aiGuessBase(string $kind, string $key): string {
    if ($kind === "openai") {
        if (strncmp($key, "sk-or-", 6) === 0) return "https://openrouter.ai/api/v1";
        if (strncmp($key, "gsk_", 4) === 0)   return "https://api.groq.com/openai/v1";
        if (strncmp($key, "xai-", 4) === 0)    return "https://api.x.ai/v1";
    }
    return aiDefaultBase($kind);
}

function aiValidBase(string $url): bool {
    $p = parse_url($url);
    return is_array($p) && in_array(strtolower($p["scheme"] ?? ""), ["http", "https"], true) && !empty($p["host"]) && empty($p["user"]);
}

/* ---------- HTTP ---------- */

/*
    Windows PHP (XAMPP, WAMP...) often has no list of trusted certificate authorities, so every https call fails with
    "SSL certificate problem". Use the one that is configured; otherwise look for the bundle that ships with XAMPP,
    or a cacert.pem placed next to these files. Optional: define("AI_CA_BUNDLE", "C:/path/cacert.pem") in the settings at the top of this file.
*/
function aiCaBundle(): string {
    static $found = null;
    if ($found !== null) return $found;
    $found = "";
    if (defined("AI_CA_BUNDLE") && is_file((string)AI_CA_BUNDLE)) return $found = (string)AI_CA_BUNDLE;
    foreach (["curl.cainfo", "openssl.cafile"] as $k) {
        $v = (string)ini_get($k);
        if ($v !== "" && is_file($v)) return $found = "";          // already configured: leave it alone
    }
    $dirs = [__DIR__, dirname(PHP_BINARY) . "/extras/ssl", dirname(PHP_BINARY) . "/extras", dirname(PHP_BINARY)];
    foreach ($dirs as $d) {
        foreach (["cacert.pem", "curl-ca-bundle.crt", "ca-bundle.crt"] as $n) {
            if (is_file($d . "/" . $n)) return $found = $d . "/" . $n;
        }
    }
    return $found;
}

function aiHttp(string $method, string $url, array $headers, ?string $body, int $timeout): array {
    $wait = 0;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => max(5, $timeout),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => false,     // a provider must never bounce the key somewhere else
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$wait) {
            if (stripos($line, "retry-after:") === 0) $wait = (int)trim(substr($line, 12));
            return strlen($line);
        },
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    $ca = aiCaBundle();
    if ($ca !== "") $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $opts);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    if (PHP_VERSION_ID < 80000) curl_close($ch);   // no-op since PHP 8.0, deprecated in 8.5

    return ["code" => $resp === false ? 0 : $code, "body" => $resp === false ? "" : (string)$resp, "err" => $err, "wait" => $wait];
}

function aiErrText(string $body): string {
    $d = json_decode($body, true);
    if (is_array($d)) {
        $m = $d["error"]["message"] ?? ($d["error"] ?? ($d["message"] ?? ""));
        if (is_array($m)) $m = json_encode($m);
        return mb_substr(trim((string)$m), 0, 300, "UTF-8");
    }
    return mb_substr(trim(strip_tags($body)), 0, 200, "UTF-8");
}

/*
    retry = worth trying the SAME service once more (network trouble, rate limit, server overloaded)
    code -1 = the service answered but gave no usable text (blocked / empty): switch service, no retry
*/
function aiFail(int $code, string $msg, int $wait = 0): array {
    $retry = $code === 0 || $code === 408 || $code === 409 || $code === 429 || $code >= 500;
    return ["ok" => false, "code" => $code, "retry" => $retry, "msg" => $msg, "wait" => $wait];
}

function aiHttpFail(array $h): array {
    if ($h["code"] === 0) {
        $hint = stripos($h["err"], "certificate") !== false
              ? " - this server has no certificate bundle: download cacert.pem from curl.se/docs/caextract.html and put it next to aikit.php"
              : "";
        return aiFail(0, "could not connect" . ($h["err"] !== "" ? " (" . $h["err"] . ")" : "") . $hint);
    }
    $t = aiErrText($h["body"]);
    return aiFail($h["code"], "HTTP " . $h["code"] . ($t !== "" ? ": " . $t : ""), $h["wait"]);
}

/* ---------- Building each family's request ---------- */

function aiMergeNote(): string {
    return "[The audio itself could not be given to you by this AI service: you only have the tags above.]";
}

function aiGeminiBody(array $p, array $ctx): array {
    $contents = [];
    foreach ($ctx["msgs"] as $m) {
        $parts = [];
        foreach ($m["parts"] as $x) {
            if ($x["t"] === "text") { if ($x["text"] !== "") $parts[] = ["text" => $x["text"]]; }
            elseif ($x["t"] === "pdf")  $parts[] = ["inline_data" => ["mime_type" => "application/pdf", "data" => $x["b64"]]];
            else                        $parts[] = ["inline_data" => ["mime_type" => $x["mime"], "data" => $x["b64"]]];
        }
        if ($parts) $contents[] = ["role" => $m["role"] === "model" ? "model" : "user", "parts" => $parts];
    }
    $model = preg_replace('#^models/#', "", $p["model"]);
    return [
        aiBase($p) . "/models/" . rawurlencode($model) . ":generateContent",
        ["Content-Type: application/json", "x-goog-api-key: " . $p["key"]],
        [
            "systemInstruction" => ["parts" => [["text" => $ctx["system"]]]],
            "contents"          => $contents,
            "generationConfig"  => ["temperature" => 0.4, "maxOutputTokens" => AI_MAX_TOKENS],
        ],
    ];
}

function aiOpenAiBody(array $p, array $ctx): array {
    $isOpenAi = strcasecmp((string)parse_url($p["base_url"], PHP_URL_HOST), "api.openai.com") === 0;
    $messages = [["role" => "system", "content" => $ctx["system"]]];

    foreach ($ctx["msgs"] as $m) {
        $role = $m["role"] === "model" ? "assistant" : "user";
        if ($role === "assistant") {
            $text = "";
            foreach ($m["parts"] as $x) if ($x["t"] === "text") $text .= $x["text"];
            if ($text !== "") $messages[] = ["role" => "assistant", "content" => $text];
            continue;
        }

        $content = [];
        $dropped = false;
        foreach ($m["parts"] as $x) {
            if ($x["t"] === "text")       { if ($x["text"] !== "") $content[] = ["type" => "text", "text" => $x["text"]]; }
            elseif ($x["t"] === "image")  $content[] = ["type" => "image_url", "image_url" => ["url" => "data:" . $x["mime"] . ";base64," . $x["b64"]]];
            elseif ($x["t"] === "pdf")    $content[] = ["type" => "file", "file" => ["filename" => "document.pdf", "file_data" => "data:application/pdf;base64," . $x["b64"]]];
            else                          $dropped = true;
        }
        if ($dropped) $content[] = ["type" => "text", "text" => aiMergeNote()];
        if (!$content) continue;
        // a message with only text is sent as a plain string (the most widely supported form)
        $plain = count($content) === 1 && $content[0]["type"] === "text";
        $messages[] = ["role" => "user", "content" => $plain ? $content[0]["text"] : $content];
    }

    $body = ["model" => $p["model"], "messages" => $messages];
    $body[$isOpenAi ? "max_completion_tokens" : "max_tokens"] = AI_MAX_TOKENS;
    if (!($isOpenAi && preg_match('/^(o\d|gpt-5)/i', $p["model"]))) $body["temperature"] = 0.4;   // those models only accept the default

    $headers = ["Content-Type: application/json"];
    if ($p["key"] !== "") $headers[] = "Authorization: Bearer " . $p["key"];
    return [aiBase($p) . "/chat/completions", $headers, $body];
}

function aiAnthropicBody(array $p, array $ctx): array {
    $messages = [];
    foreach ($ctx["msgs"] as $m) {
        $role = $m["role"] === "model" ? "assistant" : "user";
        $blocks = [];
        $dropped = false;
        foreach ($m["parts"] as $x) {
            if ($x["t"] === "text")       { if ($x["text"] !== "") $blocks[] = ["type" => "text", "text" => $x["text"]]; }
            elseif ($x["t"] === "image")  $blocks[] = ["type" => "image", "source" => ["type" => "base64", "media_type" => $x["mime"], "data" => $x["b64"]]];
            elseif ($x["t"] === "pdf")    $blocks[] = ["type" => "document", "source" => ["type" => "base64", "media_type" => "application/pdf", "data" => $x["b64"]]];
            else                          $dropped = true;
        }
        if ($dropped) $blocks[] = ["type" => "text", "text" => aiMergeNote()];
        if ($blocks) $messages[] = ["role" => $role, "content" => $blocks];
    }
    return [
        aiBase($p) . "/messages",
        ["Content-Type: application/json", "x-api-key: " . $p["key"], "anthropic-version: 2023-06-01"],
        ["model" => $p["model"], "max_tokens" => AI_MAX_TOKENS, "temperature" => 0.4, "system" => $ctx["system"], "messages" => $messages],
    ];
}

function aiBase(array $p): string {
    return rtrim(trim((string)$p["base_url"]), "/");
}

/* ---------- Reading each family's answer ---------- */

function aiParse(string $kind, array $h): array {
    $d = json_decode($h["body"], true);
    if (!is_array($d)) return aiFail(-1, "the answer was not readable");

    $text = "";
    if ($kind === "gemini") {
        if (!empty($d["promptFeedback"]["blockReason"])) return aiFail(-1, "blocked (" . $d["promptFeedback"]["blockReason"] . ")");
        foreach (($d["candidates"][0]["content"]["parts"] ?? []) as $x) {
            if (isset($x["text"]) && empty($x["thought"])) $text .= $x["text"];
        }
    } elseif ($kind === "anthropic") {
        foreach (($d["content"] ?? []) as $x) {
            if (($x["type"] ?? "") === "text") $text .= (string)($x["text"] ?? "");
        }
    } else {
        $c = $d["choices"][0]["message"]["content"] ?? "";
        if (is_array($c)) {
            foreach ($c as $x) $text .= is_array($x) ? (string)($x["text"] ?? "") : (string)$x;
        } else {
            $text = (string)$c;
        }
    }

    $text = trim($text);
    return $text === "" ? aiFail(-1, "empty reply") : ["ok" => true, "reply" => $text];
}

/* One request to one service. $p = ["kind","base_url","key","model", ...] */
function aiCall(array $p, array $ctx, int $timeout): array {
    if (!aiValidBase((string)$p["base_url"])) return aiFail(-1, "the web address is not valid");
    if ($p["model"] === "")                   return aiFail(-1, "no model is set");

    [$url, $headers, $body] = $p["kind"] === "gemini" ? aiGeminiBody($p, $ctx)
                            : ($p["kind"] === "anthropic" ? aiAnthropicBody($p, $ctx) : aiOpenAiBody($p, $ctx));

    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) return aiFail(-1, "could not prepare the request");

    $h = aiHttp("POST", $url, $headers, $json, $timeout);
    if ($h["code"] < 200 || $h["code"] >= 300) return aiHttpFail($h);

    return aiParse($p["kind"], $h);
}

/* ---------- Model lists (the admin's "Load models" button) ---------- */

function aiPickModel(array $models): string {
    $skip = '/image|tts|audio|live|embed|vision|robotics|computer|whisper|moderation|dall|realtime|transcribe|guard|safeguard/i';
    $ok = array_values(array_filter($models, fn($m) => !preg_match($skip, $m)));
    if (!$ok) $ok = $models;
    rsort($ok, SORT_NATURAL);   // newer-looking names first

    foreach (['/flash-latest$/', '/-flash$/', '/flash(?!.*lite)/', '/haiku/', '/mini/', '/small/', '/instant/', '/versatile/', '/flash/'] as $re) {
        foreach ($ok as $m) if (preg_match($re, $m)) return $m;
    }
    return $ok[0] ?? "";
}

/* Returns ["ok" => bool, "models" => [...], "suggest" => "...", "error" => "..."] */
function aiListModels(string $kind, string $base, string $key): array {
    $base = rtrim(trim($base), "/");
    if (!aiValidBase($base)) return ["ok" => false, "error" => "Enter a valid web address first (starting with https://)."];

    if ($kind === "gemini") {
        $h = aiHttp("GET", $base . "/models?pageSize=200", ["x-goog-api-key: " . $key], null, 20);
    } elseif ($kind === "anthropic") {
        $h = aiHttp("GET", $base . "/models?limit=100", ["x-api-key: " . $key, "anthropic-version: 2023-06-01"], null, 20);
    } else {
        $h = aiHttp("GET", $base . "/models", $key !== "" ? ["Authorization: Bearer " . $key] : [], null, 20);
    }

    if ($h["code"] < 200 || $h["code"] >= 300) {
        $e = aiHttpFail($h);
        return ["ok" => false, "error" => "Could not load the list: " . $e["msg"]];
    }

    $d = json_decode($h["body"], true);
    $models = [];
    if ($kind === "gemini") {
        foreach (($d["models"] ?? []) as $m) {
            if (in_array("generateContent", $m["supportedGenerationMethods"] ?? [], true)) {
                $models[] = preg_replace('#^models/#', "", (string)($m["name"] ?? ""));
            }
        }
    } else {
        $rows = is_array($d) ? ($d["data"] ?? (array_is_list($d) ? $d : [])) : [];
        foreach ($rows as $m) {
            $id = is_array($m) ? (string)($m["id"] ?? "") : (string)$m;
            if ($id !== "") $models[] = $id;
        }
        if ($kind === "openai" && strcasecmp((string)parse_url($base, PHP_URL_HOST), "api.openai.com") === 0) {
            $models = array_filter($models, fn($m) => !preg_match('/embed|whisper|tts|dall-e|moderation|davinci|babbage|image|audio|realtime|transcribe|search/i', $m));
        }
    }

    $models = array_values(array_unique(array_filter($models)));
    sort($models, SORT_NATURAL | SORT_FLAG_CASE);
    if (!$models) return ["ok" => false, "error" => "The service answered, but listed no chat models."];

    return ["ok" => true, "models" => $models, "suggest" => aiPickModel($models)];
}

/* =====================================================================
   The switchboard: AI list, order of use, retries, resting, fallback
   ===================================================================== */

/* Every AI added in the admin page, in order of use. Keys are included: never send this to the browser. */
function aiRows(): array {
    $now = time();
    $out = [];
    foreach (aikitRows("SELECT id, name, kind, base_url, api_key, model, priority, enabled, last_status, last_used_at, cooldown_until
                          FROM aikit_providers ORDER BY priority, id") as $r) {
        $until = (int)$r["cooldown_until"];
        $out[] = [
            "id"       => (int)$r["id"],
            "name"     => (string)$r["name"],
            "kind"     => (string)$r["kind"],
            "base_url" => (string)$r["base_url"],
            "key"      => (string)$r["api_key"],
            "model"    => (string)$r["model"],
            "priority" => (int)$r["priority"],
            "enabled"  => (int)$r["enabled"] === 1,
            "status"   => (string)$r["last_status"],
            "last"     => (int)$r["last_used_at"] > 0 ? date("Y-m-d H:i:s", (int)$r["last_used_at"]) : "",
            "cooling"  => $until > $now,
            "cool_s"   => max(0, $until - $now),
        ];
    }
    return $out;
}

/* The default AI from config.php, or null when no real key was pasted there */
function aiConfigDefault(): ?array {
    $key   = defined("AI_API_KEY") ? trim((string)AI_API_KEY) : "";
    $model = defined("AI_MODEL")   ? trim((string)AI_MODEL)   : "";
    if ($key === "" || preg_match('/paste|your[_ -]?api|^x{6,}$/i', $key)) return null;

    $kind = defined("AI_PROVIDER") && isset(aiKinds()[strtolower((string)AI_PROVIDER)])
          ? strtolower((string)AI_PROVIDER) : aiGuessKind($model, $key);
    $base = defined("AI_BASE_URL") && trim((string)AI_BASE_URL) !== "" ? trim((string)AI_BASE_URL) : aiGuessBase($kind, $key);
    if ($model === "") $model = aiDefaultModel($kind);

    return ["id" => 0, "name" => "Default (config.php)", "kind" => $kind, "base_url" => $base, "key" => $key,
            "model" => $model, "priority" => PHP_INT_MAX, "enabled" => true, "status" => "", "last" => "", "cooling" => false, "cool_s" => 0];
}

/* Switched-on AIs that are complete, then the default AI (unless it is the same one as an added AI) */
function aiChain(): array {
    $chain = [];
    foreach (aiRows() as $p) {
        if (!$p["enabled"] || $p["model"] === "" || !aiValidBase($p["base_url"])) continue;
        if ($p["key"] === "" && $p["kind"] !== "openai") continue;   // only OpenAI-compatible servers (e.g. Ollama) may work without a key
        $chain[] = $p;
    }

    $def = aiConfigDefault();
    if ($def) {
        $dup = false;
        foreach ($chain as $p) if ($p["key"] === $def["key"] && $p["model"] === $def["model"]) $dup = true;
        if (!$dup) $chain[] = $def;
    }
    return $chain;
}

function aiMark(array $p, bool $ok, string $status, int $cool = 0): void {
    if ($p["id"] <= 0) return;   // the default AI has no row
    try {
        $status = mb_strtoupper(mb_substr($status, 0, 1, "UTF-8"), "UTF-8") . mb_substr($status, 1, null, "UTF-8");
        $status = mb_substr(($ok ? "OK: " : "Failed: ") . $status, 0, 250, "UTF-8");
        $id  = (int)$p["id"];
        $now = time();
        if ($ok)            aikitExec("UPDATE aikit_providers SET last_status = ?, last_used_at = ?, cooldown_until = 0 WHERE id = ?", [$status, $now, $id]);
        elseif ($cool > 0)  aikitExec("UPDATE aikit_providers SET last_status = ?, last_used_at = ?, cooldown_until = ? WHERE id = ?", [$status, $now, $now + $cool, $id]);
        else                aikitExec("UPDATE aikit_providers SET last_status = ?, last_used_at = ? WHERE id = ?", [$status, $now, $id]);
    } catch (Throwable $e) { /* bookkeeping only, never break an answer */ }
}

/* ---------- Helpers ---------- */

function aiKey(array $p): string {
    return $p["id"] > 0 ? "p" . $p["id"] : "cfg";
}

/* What the conversation contains that not every AI can read */
function aiNeeds(array $ctx): array {
    $n = ["pdf" => false, "audio" => false, "media" => false];
    foreach ($ctx["msgs"] as $m) {
        foreach ($m["parts"] as $x) {
            if ($x["t"] === "pdf")   $n["pdf"] = true;
            if ($x["t"] === "audio") $n["audio"] = true;
            if ($x["t"] !== "text")  $n["media"] = true;
        }
    }
    return $n;
}

/* Can this AI really read that kind of content? (audio: Gemini only; PDF: Gemini, Claude, OpenAI, OpenRouter) */
function aiCanRead(array $p, array $needs): bool {
    $host = strtolower((string)parse_url($p["base_url"], PHP_URL_HOST));
    if ($needs["audio"] && $p["kind"] !== "gemini") return false;
    if ($needs["pdf"]) {
        if ($p["kind"] === "openai" && $host !== "api.openai.com" && $host !== "openrouter.ai") return false;
    }
    return true;
}

/* The same conversation with pictures / PDFs / audio replaced by a short notice (the "text only" last round) */
function aiStrip(array $ctx): array {
    foreach ($ctx["msgs"] as &$m) {
        $parts = [];
        $note  = false;
        foreach ($m["parts"] as $x) {
            if ($x["t"] === "text") $parts[] = $x;
            else $note = true;
        }
        if ($note) $parts[] = ["t" => "text", "text" => "[The file's own content (picture, PDF or audio) could not be shown to you, so answer only from what is written here and say so.]"];
        $m["parts"] = $parts;
    }
    unset($m);
    return $ctx;
}

/* Alternating user / model turns, starting and ending with the user (some services refuse anything else) */
function aiNormalize(array $msgs): array {
    $out = [];
    foreach ($msgs as $m) {
        $role = $m["role"] === "model" ? "model" : "user";
        if (!$m["parts"]) continue;
        if ($out && $out[count($out) - 1]["role"] === $role) {
            $out[count($out) - 1]["parts"] = array_merge($out[count($out) - 1]["parts"], $m["parts"]);
        } else {
            $out[] = ["role" => $role, "parts" => $m["parts"]];
        }
    }
    while ($out && $out[0]["role"] !== "user") array_shift($out);
    return $out;
}

/* How long to rest an AI after a failure (seconds). 0 = no rest (a 400 can be about this one file, not about the AI) */
function aiRest(array $r): int {
    $c = $r["code"];
    if ($c === 401 || $c === 403) return 900;
    if ($c === 404) return 600;
    if ($c === 429) return max(60, min(300, (int)$r["wait"]));
    if ($c === 0 || $c === 408 || $c === 409 || $c >= 500) return 45;
    return 0;
}

/* ---------- One round over the chain ---------- */

function aiRound(array $chain, array $ctx, float $deadline, array &$notes, array &$skip): array {
    $needs = aiNeeds($ctx);

    // AIs that can read this content come first, AIs that are resting come last (and get a single try)
    $ready = [];
    $maybe = [];
    $rest  = [];
    foreach ($chain as $p) {
        if (isset($skip[aiKey($p)])) continue;
        if ($p["cooling"])                  $rest[]  = $p;
        elseif (!aiCanRead($p, $needs))     $maybe[] = $p;
        else                                $ready[] = $p;
    }

    $allEmpty = true;
    $tried    = 0;

    foreach ([[array_merge($ready, $maybe), max(1, (int)AI_TRIES)], [$rest, 1]] as [$group, $tries]) {
        foreach ($group as $p) {
            for ($t = 1; $t <= $tries; $t++) {
                $left = (int)floor($deadline - microtime(true));
                if ($left < 4) {
                    $notes[] = "Ran out of time.";
                    break 3;
                }

                $r = aiCall($p, $ctx, min((int)AI_TIMEOUT, $left));
                $tried++;

                if ($r["ok"]) {
                    aiMark($p, true, "answered");
                    return ["ok" => true, "reply" => $r["reply"], "provider" => $p["name"], "model" => $p["model"]];
                }

                $notes[] = $p["name"] . " (" . $p["model"] . ")" . ($tries > 1 ? ", try " . $t : "") . ": " . $r["msg"];
                if ($r["code"] !== -1) $allEmpty = false;

                // worth another go on the same AI? (and not if it asks us to wait a long time)
                if (!$r["retry"] || $t >= $tries || $r["wait"] > 5) break;
                usleep((int)min(2500000, max(1000000, $r["wait"] * 1000000)));
            }

            $rest_s = aiRest($r ?? ["code" => 0, "wait" => 0]);
            aiMark($p, false, $r["msg"] ?? "no answer", $rest_s);
            if ($rest_s > 0) $skip[aiKey($p)] = true;   // don't come back to it in this question
        }
    }

    return ["ok" => false, "empty" => $tried > 0 && $allEmpty];
}

/*
    Asks the AIs. $ctx = see aiprov.php.
    Returns ["ok"=>true, "reply"=>..., "provider"=>..., "model"=>...]
         or ["ok"=>false, "setup"=>true]                no AI is set up at all
         or ["ok"=>false, "empty"=>bool, "notes"=>[...]] every AI failed (notes say why, for the admin)
*/
function aiGenerate(array $ctx): array {
    $chain = aiChain();
    if (!$chain) return ["ok" => false, "setup" => true, "notes" => []];

    @set_time_limit((int)AI_BUDGET + 30);
    $deadline = microtime(true) + (int)AI_BUDGET;
    $notes = [];
    $skip  = [];

    $res = aiRound($chain, $ctx, $deadline, $notes, $skip);

    if (!$res["ok"] && aiNeeds($ctx)["media"]) {
        $notes[] = "Trying again without the picture / PDF / audio.";
        $res = aiRound($chain, aiStrip($ctx), $deadline, $notes, $skip);
        if ($res["ok"]) $res["text_only"] = true;
    }

    $res["notes"] = $notes;
    return $res;
}

/* One quick test request for the admin's "Test" button (no retries, no switching) */
function aiTest(array $p): array {
    $ctx = ["system" => "You are a connection test. Reply with the single word OK.",
            "msgs"   => [["role" => "user", "parts" => [["t" => "text", "text" => "Say OK."]]]]];
    $t0 = microtime(true);
    $r  = aiCall($p, $ctx, 40);
    $ms = (int)round((microtime(true) - $t0) * 1000);

    if ($r["ok"]) return ["ok" => true, "ms" => $ms, "reply" => mb_substr($r["reply"], 0, 80, "UTF-8")];
    return ["ok" => false, "ms" => $ms, "error" => $r["msg"]];
}


/* =====================================================================
   aiAsk(): the one function the rest of the site calls
   ===================================================================== */

/*
    $history = [ ["role" => "user"|"model", "text" => "..."], ... ]  (optional, oldest first)
    Returns ["ok"=>true, "reply"=>, "provider"=>, "model"=>]
         or ["ok"=>false, "setup"=>bool, "notes"=>[...]]
*/
function aiAsk(string $question, string $system = "", array $history = []): array {
    $msgs = [];
    foreach (array_slice($history, -10) as $h) {
        $t = trim((string)($h["text"] ?? ""));
        if ($t === "") continue;
        $msgs[] = ["role" => (($h["role"] ?? "") === "model") ? "model" : "user",
                   "parts" => [["t" => "text", "text" => mb_substr($t, 0, 4000, "UTF-8")]]];
    }
    $msgs[] = ["role" => "user", "parts" => [["t" => "text", "text" => $question]]];

    try {
        return aiGenerate(["system" => $system !== "" ? $system : AIKIT_SYSTEM, "msgs" => aiNormalize($msgs)]);
    } catch (Throwable $e) {
        return ["ok" => false, "setup" => false, "notes" => [$e->getMessage()]];
    }
}

/* =====================================================================
   Web endpoints (only when this file is opened directly in the browser)
   ===================================================================== */

function aikitOut(array $data, int $status = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function aikitHint(string $key): string { return $key === "" ? "" : "••••" . substr($key, -4); }

function aikitPublicRow(array $p): array {
    return [
        "id" => $p["id"], "name" => $p["name"], "kind" => $p["kind"], "base_url" => $p["base_url"], "model" => $p["model"],
        "enabled" => $p["enabled"], "has_key" => $p["key"] !== "", "key_hint" => aikitHint($p["key"]),
        "status" => $p["status"], "last" => $p["last"], "cooling" => $p["cooling"], "cool_s" => $p["cool_s"],
    ];
}

function aikitFindRow(int $id): ?array {
    foreach (aiRows() as $p) if ($p["id"] === $id) return $p;
    return null;
}

/* The AI a "models" / "test" request is about: a saved one (its stored key is used) or the typed form values */
function aikitTarget(): array {
    $id = (int)($_POST["id"] ?? -1);

    if ($id === 0) {
        $d = aiConfigDefault();
        if (!$d) aikitOut(["ok" => false, "error" => "No default key is set."], 400);
        return $d;
    }

    $saved = $id > 0 ? aikitFindRow($id) : null;
    if ($id > 0 && !$saved) aikitOut(["ok" => false, "error" => "That AI no longer exists."], 404);

    $kind  = (string)($_POST["kind"] ?? ($saved["kind"] ?? "openai"));
    $base  = trim((string)($_POST["base_url"] ?? ($saved["base_url"] ?? "")));
    $key   = trim((string)($_POST["api_key"] ?? ""));
    $model = trim((string)($_POST["model"] ?? ($saved["model"] ?? "")));

    if (!isset(aiKinds()[$kind])) aikitOut(["ok" => false, "error" => "Unknown type."], 400);
    if ($key === "" && $saved) $key = $saved["key"];
    if ($base === "") $base = aiGuessBase($kind, $key);
    if (!aiValidBase($base)) aikitOut(["ok" => false, "error" => "The web address must start with http:// or https://"], 400);

    return ["id" => $saved["id"] ?? -1, "name" => "test", "kind" => $kind, "base_url" => $base, "key" => $key, "model" => $model];
}

/*
    Admin API (the setup page talks to it):
      GET  ?action=list
      POST action=save|toggle|delete|move|models|test
*/
function aikitAdminApi(string $act): void {
    if (!aikitIsAdmin()) aikitOut(["ok" => false, "error" => "Only the admin can do that."], 403);
    if (!function_exists("curl_init")) aikitOut(["ok" => false, "error" => "The PHP cURL extension is turned off on this server."], 503);

    $isPost = $_SERVER["REQUEST_METHOD"] === "POST";
    if ($isPost && !aikitCsrfOk()) aikitOut(["ok" => false, "error" => "This page is out of date. Reload it and try again."], 403);

    if ($act === "list") {
        $def = aiConfigDefault();
        aikitOut([
            "ok"      => true,
            "ais"     => array_map("aikitPublicRow", aiRows()),
            "default" => $def ? ["set" => true, "kind" => $def["kind"], "model" => $def["model"], "base_url" => $def["base_url"]] : ["set" => false],
            "kinds"   => aiKinds(),
            "presets" => aiPresets(),
            "tries"   => (int)AI_TRIES,
        ]);
    }

    if (!$isPost) aikitOut(["ok" => false, "error" => "Unknown request."], 400);

    if ($act === "save") {
        $id    = (int)($_POST["id"] ?? 0);
        $kind  = (string)($_POST["kind"] ?? "");
        $name  = trim((string)($_POST["name"] ?? ""));
        $key   = trim((string)($_POST["api_key"] ?? ""));
        $model = trim((string)($_POST["model"] ?? ""));
        $base  = trim((string)($_POST["base_url"] ?? ""));
        $on    = ($_POST["enabled"] ?? "1") === "0" ? 0 : 1;

        $saved = $id > 0 ? aikitFindRow($id) : null;
        if ($id > 0 && !$saved) aikitOut(["ok" => false, "error" => "That AI no longer exists."], 404);

        if (!isset(aiKinds()[$kind]))                  aikitOut(["ok" => false, "error" => "Choose the type of AI."], 400);
        if ($key === "" && $saved) $key = $saved["key"];
        if (preg_match('/\s/', $key) || strlen($key) > 500) aikitOut(["ok" => false, "error" => "The API key can't contain spaces or line breaks."], 400);
        if ($key === "" && $kind !== "openai")         aikitOut(["ok" => false, "error" => "Paste the API key."], 400);
        if ($model === "" || mb_strlen($model) > 120 || preg_match('/\s/', $model)) aikitOut(["ok" => false, "error" => "Enter the model name (or press Load models)."], 400);
        if ($base === "") $base = aiGuessBase($kind, $key);
        if (!aiValidBase($base) || strlen($base) > 255) aikitOut(["ok" => false, "error" => "The web address must start with http:// or https://"], 400);
        if ($name === "") $name = parse_url($base, PHP_URL_HOST) ?: "AI";
        $name = mb_substr($name, 0, 60, "UTF-8");

        if ($saved) {
            aikitExec("UPDATE aikit_providers SET name = ?, kind = ?, base_url = ?, api_key = ?, model = ?, enabled = ?, cooldown_until = 0, last_status = '' WHERE id = ?",
                      [$name, $kind, $base, $key, $model, $on, $id]);
            aikitOut(["ok" => true, "id" => $id]);
        }

        $r    = aikitRows("SELECT COALESCE(MAX(priority), 0) + 10 AS p FROM aikit_providers");
        $prio = (int)($r[0]["p"] ?? 10);
        aikitExec("INSERT INTO aikit_providers (name, kind, base_url, api_key, model, priority, enabled) VALUES (?, ?, ?, ?, ?, ?, ?)",
                  [$name, $kind, $base, $key, $model, $prio, $on]);
        aikitOut(["ok" => true, "id" => aikitLastId()]);
    }

    if ($act === "toggle") {
        aikitExec("UPDATE aikit_providers SET enabled = 1 - enabled, cooldown_until = 0 WHERE id = ?", [(int)($_POST["id"] ?? 0)]);
        aikitOut(["ok" => true]);
    }

    if ($act === "delete") {
        aikitExec("DELETE FROM aikit_providers WHERE id = ?", [(int)($_POST["id"] ?? 0)]);
        aikitOut(["ok" => true]);
    }

    if ($act === "move") {
        $id  = (int)($_POST["id"] ?? 0);
        $dir = ($_POST["dir"] ?? "") === "up" ? -1 : 1;
        $ids = array_column(aiRows(), "id");
        $i   = array_search($id, $ids, true);
        if ($i === false) aikitOut(["ok" => false, "error" => "That AI no longer exists."], 404);

        $j = $i + $dir;
        if ($j >= 0 && $j < count($ids)) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $n => $pid) aikitExec("UPDATE aikit_providers SET priority = ? WHERE id = ?", [($n + 1) * 10, $pid]);
        }
        aikitOut(["ok" => true]);
    }

    if ($act === "models") {
        $t = aikitTarget();
        aikitOut(aiListModels($t["kind"], $t["base_url"], $t["key"]));
    }

    if ($act === "test") {
        $t = aikitTarget();
        if ($t["model"] === "") aikitOut(["ok" => false, "error" => "Enter the model name first."], 400);
        if ($t["key"] === "" && $t["kind"] !== "openai") aikitOut(["ok" => false, "error" => "Paste the API key first."], 400);

        $r = aiTest($t);
        if ($t["id"] > 0) aiMark($t, $r["ok"], $r["ok"] ? "test passed" : $r["error"]);
        aikitOut($r);
    }

    aikitOut(["ok" => false, "error" => "Unknown request."], 400);
}

/* The endpoint the site's own pages call:  POST aikit.php?ask  message=...  [history=JSON] */
function aikitAskApi(): void {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") aikitOut(["ok" => false, "error" => "Unknown request."], 400);
    if (!aikitCanAsk())  aikitOut(["ok" => false, "error" => "Please sign in first."], 401);
    if (!aikitCsrfOk())  aikitOut(["ok" => false, "error" => "This page is out of date. Reload it and try again."], 403);
    if (!function_exists("curl_init")) aikitOut(["ok" => false, "error" => "The PHP cURL extension is turned off on this server."], 503);

    $msg = trim((string)($_POST["message"] ?? ""));
    if ($msg === "" || mb_strlen($msg, "UTF-8") > 4000) aikitOut(["ok" => false, "error" => "Write a message (up to 4000 characters)."], 400);
    if (!aikitRateOk()) aikitOut(["ok" => false, "error" => "Slow down a little and try again in a minute."], 429);

    $history = json_decode((string)($_POST["history"] ?? "[]"), true);
    if (!is_array($history)) $history = [];

    $r = aiAsk($msg, "", $history);

    if ($r["ok"]) {
        $res = ["ok" => true, "reply" => $r["reply"]];
        if (aikitIsAdmin()) $res["provider"] = $r["provider"] . " (" . $r["model"] . ")";
        aikitOut($res);
    }
    if (!empty($r["setup"])) aikitOut(["ok" => false, "error" => "The AI is not set up yet."], 503);

    $res = ["ok" => false, "error" => "The AI is busy right now. Please try again in a moment."];
    if (aikitIsAdmin()) $res["notes"] = $r["notes"] ?? [];   // the reasons, for the admin only
    aikitOut($res, 502);
}

/* The setup page (add / test / order the AIs) */
function aikitPage(): void {
    if (!aikitIsAdmin()) { http_response_code(403); exit("Admins only."); }
    aikitDb();   // creates the tables on the first visit
    header("Content-Type: text/html; charset=utf-8");
    header("Cache-Control: no-store");
    echo str_replace("__CSRF__", json_encode(AIKIT_OPEN ? "" : aikitCsrfToken()), <<<'AIKITHTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI setup</title>
<style>
:root{--bg:#f5f5f3;--card:#fff;--line:#d9d9d4;--text:#151515;--muted:#5d5d58;--accent:#151515;--on:#fff;--blue:#1d5fbf;--bad:#c4242a;--ok:#247b50;color-scheme:light}
@media(prefers-color-scheme:dark){:root{--bg:#121212;--card:#1f1f1f;--line:#3a3a3a;--text:#f2f1ee;--muted:#a9a7a1;--accent:#f2f1ee;--on:#121212;--blue:#8ab4ff;--bad:#ff9aa0;--ok:#4cc38a;color-scheme:dark}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:820px;margin:0 auto;padding:24px 16px 60px}h1{font-size:1.4rem;margin:0 0 4px}p.sub{color:var(--muted);margin:0 0 20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px;margin-bottom:14px}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.row+.row{margin-top:10px}.grow{flex:1;min-width:140px}
label{display:block;font-size:.8rem;color:var(--muted);margin-bottom:3px}
input,select{width:100%;height:36px;padding:0 10px;background:var(--card);color:var(--text);border:1px solid var(--line);border-radius:6px;font:inherit}
button{height:34px;padding:0 12px;border:1px solid var(--line);background:var(--card);color:var(--text);border-radius:6px;font:inherit;cursor:pointer}
button:hover{border-color:var(--muted)}button.p{background:var(--accent);color:var(--on);border-color:var(--accent)}button.d{color:var(--bad)}button:disabled{opacity:.45}
.name{font-weight:600}.meta{color:var(--muted);font-size:.85rem;word-break:break-all}
.tag{font-size:.75rem;padding:1px 8px;border-radius:99px;border:1px solid var(--line)}.on{color:var(--ok)}.off,.cool{color:var(--bad)}
#msg{min-height:20px;margin:6px 0 0;font-size:.9rem}.err{color:var(--bad)}.good{color:var(--ok)}
</style>
</head>
<body>
<main>
<h1>AI setup</h1>
<p class="sub">Add as many AIs as you like. The top one is used first; if it is busy or fails, the next one answers.</p>

<div id="list"></div>

<div class="card">
  <div class="name" id="ftitle">Add an AI</div>
  <div class="row">
    <div class="grow"><label>Service</label><select id="preset"></select></div>
    <div class="grow"><label>Name (optional)</label><input id="name" maxlength="60"></div>
  </div>
  <div class="row">
    <div class="grow"><label>Type</label><select id="kind"></select></div>
    <div class="grow" style="flex:2"><label>Web address</label><input id="base" placeholder="filled in for you"></div>
  </div>
  <div class="row">
    <div class="grow" style="flex:2"><label>API key <span id="keyhint"></span></label><input id="key" type="password" autocomplete="off"></div>
    <div class="grow" style="flex:2"><label>Model</label><input id="model" list="models" autocomplete="off"><datalist id="models"></datalist></div>
    <div><label>&nbsp;</label><button id="load" type="button">Load models</button></div>
  </div>
  <div class="row">
    <button class="p" id="save" type="button">Save</button>
    <button id="test" type="button">Test</button>
    <button id="cancel" type="button" class="hidden" style="display:none">Cancel edit</button>
  </div>
  <div id="msg"></div>
</div>
</main>

<script>
const CSRF = __CSRF__;
const $ = id => document.getElementById(id);
let DATA = null, editId = 0;

function h(tag, attrs, ...kids) {
  const e = document.createElement(tag);
  for (const k in (attrs || {})) k === "class" ? e.className = attrs[k] : e.setAttribute(k, attrs[k]);
  kids.flat().forEach(c => e.append(c));
  return e;
}
function say(t, bad) { $("msg").textContent = t; $("msg").className = bad ? "err" : "good"; }

async function api(action, data = {}) {
  const fd = new FormData();
  fd.append("action", action); fd.append("csrf", CSRF);
  for (const k in data) fd.append(k, data[k]);
  try {
    const r = await fetch(location.pathname, { method: "POST", body: fd });
    return await r.json();
  } catch (e) { return { ok: false, error: "Could not reach the server." }; }
}

function form(forSave) {
  // save uses id 0 = new; test/models use id -1 = "the values typed here" (0 would mean the config default)
  return { id: editId > 0 ? editId : (forSave ? 0 : -1), name: $("name").value, kind: $("kind").value, base_url: $("base").value,
           api_key: $("key").value, model: $("model").value };
}

function resetForm() {
  editId = 0;
  ["name", "base", "key", "model"].forEach(i => $(i).value = "");
  $("models").innerHTML = ""; $("keyhint").textContent = "";
  $("ftitle").textContent = "Add an AI"; $("cancel").style.display = "none"; $("preset").selectedIndex = 0;
  applyPreset();
}

function applyPreset() {
  const p = DATA.presets[$("preset").value];
  if (!p) return;
  $("kind").value = p[1]; $("base").value = p[2]; if (!$("model").value) $("model").value = p[3];
}

function render() {
  const box = $("list"); box.innerHTML = "";
  const rows = DATA.ais;
  rows.forEach((a, i) => {
    const state = !a.enabled ? ["off", "off"] : a.cooling ? ["cool", "resting " + a.cool_s + "s"] : ["on", "on"];
    const btn = (t, fn, cls) => { const b = h("button", { type: "button", class: cls || "" }, t); b.onclick = fn; return b; };
    box.append(h("div", { class: "card" },
      h("div", { class: "row" },
        h("div", { class: "grow" },
          h("div", { class: "name" }, (i + 1) + ". " + a.name + " "), 
          h("div", { class: "meta" }, DATA.kinds[a.kind] + " · " + a.model + " · key " + (a.has_key ? a.key_hint : "none")),
          h("div", { class: "meta" }, a.status || "not used yet")),
        h("span", { class: "tag " + state[0] }, state[1])),
      h("div", { class: "row" },
        btn("↑", () => act("move", { id: a.id, dir: "up" })), btn("↓", () => act("move", { id: a.id, dir: "down" })),
        btn(a.enabled ? "Turn off" : "Turn on", () => act("toggle", { id: a.id })),
        btn("Test", async e => { e.target.disabled = true; const r = await api("test", { id: a.id }); e.target.disabled = false; say(a.name + ": " + (r.ok ? "works (" + r.ms + " ms)" : r.error), !r.ok); load(); }),
        btn("Edit", () => edit(a)),
        btn("Delete", () => confirm("Delete " + a.name + "?") && act("delete", { id: a.id }), "d"))));
  });
  if (DATA.default.set) box.append(h("div", { class: "card" }, h("div", { class: "name" }, "Default (config) — always last"),
    h("div", { class: "meta" }, DATA.default.kind + " · " + DATA.default.model)));
  if (!rows.length && !DATA.default.set) box.append(h("div", { class: "card meta" }, "No AI yet. Add one below."));
}

function edit(a) {
  editId = a.id; $("name").value = a.name; $("kind").value = a.kind; $("base").value = a.base_url;
  $("model").value = a.model; $("key").value = ""; $("keyhint").textContent = "(leave empty to keep " + a.key_hint + ")";
  $("ftitle").textContent = "Edit " + a.name; $("cancel").style.display = ""; scrollTo({ top: document.body.scrollHeight, behavior: "smooth" });
}

async function act(action, data) { const r = await api(action, data); if (!r.ok) say(r.error, true); load(); }

async function load() {
  const r = await fetch(location.pathname + "?action=list").then(x => x.json()).catch(() => null);
  if (!r || !r.ok) return say((r && r.error) || "Could not load the list.", true);
  const first = !DATA; DATA = r; render();
  if (first) {
    for (const k in r.presets) $("preset").append(h("option", { value: k }, r.presets[k][0]));
    for (const k in r.kinds) $("kind").append(h("option", { value: k }, r.kinds[k]));
    applyPreset();
  }
}

$("preset").onchange = () => { $("model").value = ""; $("base").value = ""; applyPreset(); };
$("cancel").onclick = resetForm;
$("save").onclick = async () => { const r = await api("save", form(true)); if (r.ok) { say("Saved.", false); resetForm(); load(); } else say(r.error, true); };
$("test").onclick = async () => { say("Testing…"); const r = await api("test", form()); say(r.ok ? "Works! (" + r.ms + " ms) reply: " + r.reply : r.error, !r.ok); };
$("load").onclick = async () => {
  say("Loading models…"); const r = await api("models", form());
  if (!r.ok) return say(r.error, true);
  $("models").innerHTML = ""; r.models.forEach(m => $("models").append(h("option", { value: m })));
  if (!$("model").value || DATA.presets[$("preset").value]?.[3] === $("model").value) $("model").value = r.suggest;
  say(r.models.length + " models loaded. Suggested: " + r.suggest, false);
};
load();
</script>
</body>
</html>

AIKITHTML
    );
    exit;
}

function aikitRoute(): void {
    ini_set("display_errors", "0");
    ob_start();
    $isApi = isset($_GET["ask"]) || isset($_POST["action"]) || isset($_GET["action"]);
    try {
        if (isset($_GET["ask"])) aikitAskApi();
        $act = (string)($_POST["action"] ?? $_GET["action"] ?? "");
        if ($act !== "") aikitAdminApi($act);
        aikitPage();
    } catch (Throwable $e) {
        $msg = aikitIsAdmin() ? $e->getMessage() : "Something went wrong.";
        if ($isApi) aikitOut(["ok" => false, "error" => $msg], 500);
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        exit(htmlspecialchars($msg));
    }
}

// Open directly in the browser -> act as a page / endpoint. Included by another page -> only provide aiAsk().
if (PHP_SAPI !== "cli" && realpath((string)($_SERVER["SCRIPT_FILENAME"] ?? "")) === realpath(__FILE__)) aikitRoute();
