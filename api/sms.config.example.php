<?php
// Настройки подтверждения телефона по SMS.
//
// Скопировать в /etc/keby/sms.php НА СЕРВЕРЕ и заполнить. В репозиторий
// заполненный файл попадать не должен — репозиторий публичный.
//
//   mkdir -p /etc/keby /var/lib/keby-sms
//   cp api/sms.config.example.php /etc/keby/sms.php
//   chown www-data /var/lib/keby-sms && chmod 750 /var/lib/keby-sms
//   chmod 640 /etc/keby/sms.php && chown root:www-data /etc/keby/sms.php
//
// Секрет: openssl rand -hex 32

return [
    // Подписывает талоны, хеши кодов и phone_token. Не меньше 32 символов.
    'secret' => '',

    // Каталог для базы (sms.sqlite) и журнала (sms.log). Вне каталога сайта:
    // тот перезаписывается при выкладке. Должен быть доступен www-data на запись.
    'state_dir' => '/var/lib/keby-sms',

    // Шлюз sms16.ru (Intis). Пароль сюда, и только сюда.
    'sms' => [
        'login'    => 'sms_cb',
        'password' => '',
        'sender'   => 'Clientbase',
        'text'     => 'Код подтверждения Кэби: {code}',
        'url'      => 'https://xml.sms16.ru/xml/',
    ],

    // Сайт стоит за nginx. Настоящий IP клиента берётся из X-Real-IP /
    // X-Forwarded-For, но только когда запрос пришёл с одного из этих адресов.
    // Сюда — адрес nginx с точки зрения контейнера (обычно docker-подсеть).
    'trusted_proxies' => ['172.16.0.0/12', '10.0.0.0/8', '127.0.0.1'],

    // С этих адресов SMS не уходит, код всегда office_code — для проверки
    // формы без расхода баланса. Шесть цифр.
    'office_ips'  => ['94.180.249.46'],
    'office_code' => '363636',

    // true — не отправлять вообще, всем выдавать office_code. Для отладки.
    'dry_run' => false,

    // Лимиты. Значения по умолчанию перечислены в api/_lib.php, здесь можно
    // переопределить любое. Главные два — global_per_hour и global_per_day:
    // это потолок расхода, который держится при любой атаке.
    'limits' => [
        // 'global_per_hour' => 60,
        // 'global_per_day'  => 400,
    ],
];
