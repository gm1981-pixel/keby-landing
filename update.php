<?php
// Выкладка сайта: запускает /etc/cron.daily/update_site и печатает его вывод.
// Дёрнули адрес — сайт обновился.
//
// ВНИМАНИЕ. Своей проверки доступа здесь нет намеренно: адрес закрыт по IP
// на уровне сервера, дёргается только из офиса. Если это ограничение когда-то
// снимут или сайт переедет — точка входа окажется открыта всему интернету,
// то есть любой сможет запускать обновление подряд и читать вывод
// update_site, где видны пути, адрес репозитория и текст ошибок.
// В этом случае здесь нужна проверка: токен или список адресов.

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

exec('/bin/sh /etc/cron.daily/update_site 2>&1', $output, $exitCode);

if ($exitCode === 0) {
    http_response_code(200);
    echo "Site updated successfully.\n\n";
} else {
    http_response_code(500);
    echo "Site update failed.\n\n";
}

echo implode("\n", $output), "\n";
