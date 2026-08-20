<?php
// Выкладка сайта: запускает /etc/cron.daily/update_site и печатает его вывод.
//
// Точка входа открыта наружу, поэтому закрыта токеном. Сам токен лежит ФАЙЛОМ
// ВНЕ каталога сайта — репозиторий публичный, и секрет в нём хранить нельзя.
//
// Как включить (один раз, на сервере):
//   1. openssl rand -hex 24 > /home/deploy/update_token
//   2. chown www-data /home/deploy/update_token && chmod 600 /home/deploy/update_token
//   3. дёргать: https://keby.clientbase.ru/update.php?token=<содержимое файла>
//
// Пока файла с токеном нет, скрипт отвечает 404 и ничего не запускает.

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$token_file = '/home/deploy/update_token';
$expected = is_readable($token_file) ? trim((string)file_get_contents($token_file)) : '';
$given = isset($_GET['token']) ? (string)$_GET['token'] : '';

// Отвечаем 404, а не 403: незачем подсказывать, что здесь что-то есть
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(404);
    echo "Not found\n";
    exit;
}

exec('/bin/sh /etc/cron.daily/update_site 2>&1', $output, $exitCode);

if ($exitCode === 0) {
    http_response_code(200);
    echo "Site updated successfully.\n\n";
} else {
    http_response_code(500);
    echo "Site update failed.\n\n";
}

echo implode("\n", $output), "\n";
