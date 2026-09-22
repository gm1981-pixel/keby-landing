<?php
// Журнал согласий.
//
//   POST action=cookie  choice=yes|no  version=…  page=…
//
// Пишет строку в consents.log рядом с базой SMS-модуля: дата и время, IP,
// адрес страницы, тип согласия, версия текста. Этого достаточно, чтобы
// подтвердить факт согласия, если его оспорят (152-ФЗ ст. 9 ч. 3).
//
// Ответ всегда пустой и быстрый: страница не ждёт результата.

define('KEBY_API', 1);
require __DIR__ . '/_lib.php';

require_post();
require_same_origin();

$choice = (string)($_POST['choice'] ?? '');
if (!in_array($choice, ['yes', 'no'], true)) fail(400, 'choice');

// Длину ограничиваем, чтобы в журнал нельзя было залить мусор
$clean = fn($v, $max) => mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v), 0, $max);

$line = json_encode([
    'ts'      => date('c'),
    'ip'      => client_ip(),
    'type'    => $clean($_POST['action'] ?? 'cookie', 32),
    'choice'  => $choice,
    'version' => $clean($_POST['version'] ?? '', 32),
    'page'    => $clean($_POST['page'] ?? '', 300),
    'form'    => $clean($_POST['form'] ?? '', 64),
    'ua'      => $clean($_SERVER['HTTP_USER_AGENT'] ?? '', 200),
], JSON_UNESCAPED_UNICODE);

@file_put_contents(state_dir() . '/consents.log', $line . "\n", FILE_APPEND | LOCK_EX);

respond(200, ['ok' => true]);
