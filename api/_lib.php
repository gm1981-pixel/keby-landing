<?php
// Общие функции для api/*.php. Напрямую не вызывается.
if (!defined('KEBY_API')) { http_response_code(404); exit; }

// ── Конфиг ─────────────────────────────────────────────────────────────────
// Лежит ВНЕ каталога сайта: репозиторий публичный, и каталог перезаписывается
// при выкладке. Путь можно переопределить переменной окружения KEBY_SMS_CONFIG.
function cfg(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = getenv('KEBY_SMS_CONFIG') ?: '/etc/keby/sms.php';
    $loaded = is_readable($path) ? include $path : null;
    $cfg = is_array($loaded) ? $loaded : [];
    return $cfg;
}

function configured(): bool {
    $c = cfg();
    return strlen((string)($c['secret'] ?? '')) >= 32 && !empty($c['state_dir']);
}

function limits(): array {
    return (cfg()['limits'] ?? []) + [
        'resend_after'      => 60,     // с — повторная отправка на тот же номер
        'code_ttl'          => 300,    // с — сколько живёт код
        'max_attempts'      => 5,      // попыток ввода на один код
        'phone_per_hour'    => 3,
        'phone_per_day'     => 5,
        'ip_per_hour'       => 8,
        'ip_per_day'        => 20,
        'global_per_hour'   => 60,     // потолок расходов: больше SMS в час не уйдёт
        'global_per_day'    => 400,    //   ни при какой атаке
        'check_ip_per_hour' => 30,     // проверок кода с одного IP
        'challenge_min_age' => 3,      // с — раньше этого SMS не запросить
        'challenge_max_age' => 1800,
        'token_ttl'         => 1800,   // с — сколько действует подтверждение
    ];
}

// ── Ответы ─────────────────────────────────────────────────────────────────
function respond(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $status, string $error, array $extra = []): void {
    respond($status, ['ok' => false, 'error' => $error] + $extra);
}

function require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405, 'method');
}

// Origin или Referer обязаны указывать на нас. От curl это не защитит, но
// закрывает самый массовый способ — запуск с чужого сайта через <img>/<form>.
function require_same_origin(): void {
    // HTTP_HOST может нести порт, parse_url(PHP_URL_HOST) — никогда.
    // Сравниваем только имена.
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $src  = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    $from = strtolower((string)parse_url($src, PHP_URL_HOST));
    if ($host === '' || $from === '' || $from !== $host) {
        log_line("origin отклонён: host=$host from=" . ($from ?: '-'));
        fail(403, 'origin');
    }
}

// ── IP клиента ─────────────────────────────────────────────────────────────
// Сайт стоит за nginx: REMOTE_ADDR внутри контейнера — это nginx, а не человек.
// Настоящий адрес берём из X-Real-IP / X-Forwarded-For, но ТОЛЬКО если запрос
// пришёл от доверенного прокси. Иначе любой мог бы подставить чужой IP и
// обойти лимиты.
function client_ip(): string {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $trusted = cfg()['trusted_proxies'] ?? [];
    foreach ($trusted as $cidr) {
        if (cidr_match($remote, $cidr)) {
            if (!empty($_SERVER['HTTP_X_REAL_IP']) && filter_var($_SERVER['HTTP_X_REAL_IP'], FILTER_VALIDATE_IP)) {
                return $_SERVER['HTTP_X_REAL_IP'];
            }
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                // Первый адрес в цепочке — клиент; остальные дописаны прокси
                $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
            }
            break;
        }
    }
    return $remote;
}

function cidr_match(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) return $ip === $cidr;
    [$net, $bits] = explode('/', $cidr, 2);
    $ipBin = @inet_pton($ip); $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
    $bits = (int)$bits; $bytes = intdiv($bits, 8); $rest = $bits % 8;
    if ($bytes && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) return false;
    if ($rest === 0) return true;
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
}

// ── Телефон ────────────────────────────────────────────────────────────────
// Только российские мобильные. Приводим к 11 цифрам вида 79XXXXXXXXX.
// Всё остальное отбрасываем: это и защита от подстановки чужих направлений,
// и защита от инъекции в XML запроса к SMS-шлюзу.
function normalize_phone(string $raw): ?string {
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 10 && $d[0] === '9') $d = '7' . $d;
    if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);
    return (strlen($d) === 11 && substr($d, 0, 2) === '79') ? $d : null;
}

function mask_phone(string $p): string {
    return strlen($p) === 11 ? substr($p, 0, 4) . '***' . substr($p, -4) : '***';
}

// ── Подписи ────────────────────────────────────────────────────────────────
function sign(string $payload): string {
    return hash_hmac('sha256', $payload, cfg()['secret']);
}

// Код в базе не хранится — только его HMAC с привязкой к номеру
function code_hash(string $phone, string $code): string {
    return sign("code|$phone|$code");
}

// ── Хранилище ──────────────────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = rtrim(cfg()['state_dir'], '/');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        log_line("state_dir недоступен: $dir");
        fail(503, 'storage');
    }
    $pdo = new PDO('sqlite:' . $dir . '/sms.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS codes (
        phone TEXT PRIMARY KEY, code_hash TEXT NOT NULL, created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
        verified_at INTEGER, ip TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL,
        kind TEXT NOT NULL, key TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS events_lookup ON events(kind, key, ts)');
    // Иногда подчищаем историю: для лимитов нужны только последние сутки
    if (random_int(1, 50) === 1) {
        $pdo->prepare('DELETE FROM events WHERE ts < ?')->execute([time() - 2 * 86400]);
        $pdo->prepare('DELETE FROM codes WHERE expires_at < ?')->execute([time() - 86400]);
    }
    return $pdo;
}

function count_events(PDO $db, string $kind, string $key, int $window): int {
    $st = $db->prepare('SELECT COUNT(*) FROM events WHERE kind = ? AND key = ? AND ts > ?');
    $st->execute([$kind, $key, time() - $window]);
    return (int)$st->fetchColumn();
}

function add_event(PDO $db, string $kind, string $key): void {
    $db->prepare('INSERT INTO events (ts, kind, key) VALUES (?, ?, ?)')->execute([time(), $kind, $key]);
}

// Через сколько секунд освободится окно лимита
function retry_after(PDO $db, string $kind, string $key, int $window): int {
    $st = $db->prepare('SELECT MIN(ts) FROM events WHERE kind = ? AND key = ? AND ts > ?');
    $st->execute([$kind, $key, time() - $window]);
    $oldest = (int)$st->fetchColumn();
    return max(1, $oldest + $window - time());
}

// ── Журнал ─────────────────────────────────────────────────────────────────
// Append-only файл рядом с базой. Номера в нём замаскированы.
function log_line(string $msg): void {
    $dir = cfg()['state_dir'] ?? null;
    if (!$dir || !is_dir($dir)) return;
    @file_put_contents(rtrim($dir, '/') . '/sms.log',
        date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}
