<?php
// Подтверждение телефона по SMS.
//
//   GET  ?action=challenge      → одноразовый талон на отправку
//   POST action=send            → отправить код (phone, challenge, website)
//   POST action=check           → проверить код (phone, code) → phone_token
//
// Все ответы — JSON. Настройки и секреты — в /etc/keby/sms.php (см.
// sms.config.example.php). Без конфига ничего не отправляется.
//
// От чего защищаемся и как — по слоям:
//   1. только POST и только со своего домена — нельзя дёрнуть с чужого сайта;
//   2. только российские мобильные, номер экранируется — нельзя подсунуть
//      чужое направление или дописать в XML лишних абонентов;
//   3. талон (challenge), подписанный и привязанный к IP: без загрузки
//      страницы и паузы в несколько секунд SMS не запросить;
//   4. лимиты на номер, на IP и ОБЩИЙ потолок в час и в сутки — последний
//      ограничивает расход при любой атаке, даже если остальное обойдут;
//   5. код живёт 5 минут, 5 попыток ввода, в базе только его хеш;
//   6. успех даёт подписанный phone_token — его можно проверить на стороне
//      регистрации, чтобы телефон без подтверждения не принимался.

define('KEBY_API', 1);
require __DIR__ . '/_lib.php';

if (!configured()) fail(503, 'not_configured');

$C   = cfg();
$L   = limits();
$ip  = client_ip();
$now = time();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Талон ──────────────────────────────────────────────────────────────────
if ($action === 'challenge') {
    $ts = $now;
    respond(200, [
        'ok'        => true,
        'challenge' => $ts . '.' . sign("challenge|$ip|$ts"),
        'min_age'   => $L['challenge_min_age'],
    ]);
}

// ── Отправка кода ──────────────────────────────────────────────────────────
if ($action === 'send') {
    require_post();
    require_same_origin();

    // Ловушка для ботов: поле, которого человек не видит. Заполнено — делаем
    // вид, что всё прошло, но ничего не шлём. Тихий «успех» сбивает автомат
    // с толку лучше, чем ошибка.
    if (!empty($_POST['website'])) {
        log_line("honeypot ip=$ip");
        respond(200, ['ok' => true, 'resend_after' => $L['resend_after'], 'ttl' => $L['code_ttl']]);
    }

    $phone = normalize_phone((string)($_POST['phone'] ?? ''));
    if (!$phone) fail(400, 'bad_phone');

    // Талон: подпись верна, привязан к этому IP, не слишком свежий и не протух
    $parts = explode('.', (string)($_POST['challenge'] ?? ''), 2);
    $ts = (int)($parts[0] ?? 0);
    $ok = count($parts) === 2 && $ts > 0
       && hash_equals(sign("challenge|$ip|$ts"), $parts[1])
       && $now - $ts >= $L['challenge_min_age']
       && $now - $ts <= $L['challenge_max_age'];
    if (!$ok) {
        log_line("challenge отклонён ip=$ip phone=" . mask_phone($phone));
        fail(400, 'bad_challenge');
    }

    $db = db();
    $db->exec('BEGIN IMMEDIATE');   // лимиты считаем и записываем атомарно
    try {
        // Повтор на тот же номер — не раньше resend_after
        $st = $db->prepare('SELECT created_at FROM codes WHERE phone = ?');
        $st->execute([$phone]);
        $prev = $st->fetchColumn();
        if ($prev !== false && $now - (int)$prev < $L['resend_after']) {
            $db->exec('ROLLBACK');
            fail(429, 'too_soon', ['retry_after' => $L['resend_after'] - ($now - (int)$prev)]);
        }

        $checks = [
            ['send_phone',  $phone, 3600,  $L['phone_per_hour'],  'phone_limit'],
            ['send_phone',  $phone, 86400, $L['phone_per_day'],   'phone_limit'],
            ['send_ip',     $ip,    3600,  $L['ip_per_hour'],     'ip_limit'],
            ['send_ip',     $ip,    86400, $L['ip_per_day'],      'ip_limit'],
            ['send_global', '*',    3600,  $L['global_per_hour'], 'busy'],
            ['send_global', '*',    86400, $L['global_per_day'],  'busy'],
        ];
        foreach ($checks as [$kind, $key, $window, $max, $error]) {
            if (count_events($db, $kind, $key, $window) >= $max) {
                $retry = retry_after($db, $kind, $key, $window);
                $db->exec('ROLLBACK');
                $tag = $kind === 'send_global' ? 'ПОТОЛОК' : 'лимит';
                log_line("$tag $kind/$window ip=$ip phone=" . mask_phone($phone));
                fail(429, $error, ['retry_after' => $retry]);
            }
        }

        $office = in_array($ip, $C['office_ips'] ?? [], true) || !empty($C['dry_run']);
        $code = $office ? (string)($C['office_code'] ?? '363636') : str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $db->prepare('INSERT INTO codes (phone, code_hash, created_at, expires_at, attempts, verified_at, ip)
                      VALUES (?, ?, ?, ?, 0, NULL, ?)
                      ON CONFLICT(phone) DO UPDATE SET code_hash = excluded.code_hash,
                          created_at = excluded.created_at, expires_at = excluded.expires_at,
                          attempts = 0, verified_at = NULL, ip = excluded.ip')
           ->execute([$phone, code_hash($phone, $code), $now, $now + $L['code_ttl'], $ip]);
        add_event($db, 'send_phone', $phone);
        add_event($db, 'send_ip', $ip);
        add_event($db, 'send_global', '*');
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        log_line('ошибка базы: ' . $e->getMessage());
        fail(503, 'storage');
    }

    if ($office) {
        log_line("служебный код ip=$ip phone=" . mask_phone($phone) . ($C['dry_run'] ?? false ? ' (dry_run)' : ''));
    } else {
        $err = send_sms($phone, $code);
        if ($err !== null) {
            // Лимиты уже списаны — это правильно: попытка была
            log_line("шлюз отказал ip=$ip phone=" . mask_phone($phone) . ": $err");
            fail(502, 'send_failed');
        }
        log_line("отправлено ip=$ip phone=" . mask_phone($phone));
    }

    respond(200, ['ok' => true, 'resend_after' => $L['resend_after'], 'ttl' => $L['code_ttl']]);
}

// ── Проверка кода ──────────────────────────────────────────────────────────
if ($action === 'check') {
    require_post();
    require_same_origin();

    $phone = normalize_phone((string)($_POST['phone'] ?? ''));
    $code  = (string)($_POST['code'] ?? '');
    if (!$phone) fail(400, 'bad_phone');
    if (!preg_match('/^\d{6}$/', $code)) fail(400, 'bad_code');

    $db = db();
    if (count_events($db, 'check_ip', $ip, 3600) >= $L['check_ip_per_hour']) {
        log_line("лимит проверок ip=$ip");
        fail(429, 'ip_limit', ['retry_after' => retry_after($db, 'check_ip', $ip, 3600)]);
    }
    add_event($db, 'check_ip', $ip);

    $st = $db->prepare('SELECT * FROM codes WHERE phone = ?');
    $st->execute([$phone]);
    $row = $st->fetch();
    if (!$row) fail(400, 'no_code');
    if ((int)$row['expires_at'] < $now) fail(410, 'expired');
    if ((int)$row['attempts'] >= $L['max_attempts']) fail(429, 'too_many_attempts');

    if (!hash_equals($row['code_hash'], code_hash($phone, $code))) {
        $db->prepare('UPDATE codes SET attempts = attempts + 1 WHERE phone = ?')->execute([$phone]);
        $left = $L['max_attempts'] - (int)$row['attempts'] - 1;
        log_line("неверный код ip=$ip phone=" . mask_phone($phone) . " осталось=$left");
        fail(400, 'wrong_code', ['attempts_left' => $left]);
    }

    // Код одноразовый: после успеха он больше не примется
    $db->prepare('UPDATE codes SET verified_at = ?, attempts = ? WHERE phone = ?')
       ->execute([$now, $L['max_attempts'], $phone]);
    $exp = $now + $L['token_ttl'];
    log_line("подтверждён ip=$ip phone=" . mask_phone($phone));
    respond(200, [
        'ok'          => true,
        'phone'       => $phone,
        'phone_token' => "$phone.$exp." . sign("verified|$phone|$exp"),
        'expires_in'  => $L['token_ttl'],
    ]);
}

fail(400, 'action');

// ── Шлюз ───────────────────────────────────────────────────────────────────
// Тот же XML API sms16.ru, что был. Отличия: значения экранируются, есть
// таймаут, и результат разбирается, а не пробрасывается как есть.
function send_sms(string $phone, string $code): ?string {
    $g = cfg()['sms'] ?? [];
    if (empty($g['login']) || empty($g['password'])) return 'нет учётных данных шлюза';
    $e = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $text = str_replace('{code}', $code, $g['text'] ?? 'Код подтверждения Кэби: {code}');
    $xml = '<?xml version="1.0" encoding="utf-8" ?>'
         . '<request><security><login value="' . $e($g['login']) . '" /><password value="' . $e($g['password']) . '" /></security>'
         . '<message><sender>' . $e($g['sender'] ?? 'Clientbase') . '</sender><text>' . $e($text) . '</text>'
         . '<abonent phone="' . $e($phone) . '" number_sms="1" /></message></request>';

    $ch = curl_init($g['url'] ?? 'https://xml.sms16.ru/xml/');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Content-type: text/xml; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) return "curl: $curlErr";
    if ($status !== 200)  return "HTTP $status";
    $doc = @simplexml_load_string($body);
    $info = $doc ? trim((string)$doc->information) : '';
    if ($info === 'send') return null;
    return 'ответ шлюза: ' . ($info !== '' ? $info : substr((string)$body, 0, 200));
}
