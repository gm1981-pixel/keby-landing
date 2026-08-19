# keby-landing

Лендинг Кэби — обычный статический сайт: HTML, CSS, JS, картинки и шрифты
лежат отдельными файлами. Выкладывается на **https://keby.clientbase.ru/**

## Структура

```
index.html                разметка страницы и логика компонента
assets/
  css/
    fonts.css             @font-face для Nunito и JetBrains Mono
    base.css              базовые стили и анимации
  js/
    dc-runtime.js         рантайм, который отрисовывает страницу
    react.production.min.js
    react-dom.production.min.js
  img/
    hero.png              персонаж на первом экране
    screen-dashboard.png  скриншоты интерфейса для секции «Интерфейс»
    screen-invoices.png
    screen-leads.png
    screen-invoice.png
  fonts/                  woff2 по языковым подмножествам
```

## Как посмотреть локально

Просто открыть `index.html` в браузере не получится — браузер запрещает
локальной странице подгружать соседние файлы. Нужен любой локальный сервер,
например:

```
python3 -m http.server 8000
```

и открыть http://localhost:8000

## Как выложить

Каталог целиком заливается на любой статический хостинг (GitHub Pages,
Netlify, Vercel, обычный nginx). Никаких сборки и зависимостей не требуется —
React и шрифты лежат внутри репозитория. Наружу страница ходит только за
счётчиком Яндекс.Метрики.

## Регистрация

Форма (одинаковая вверху и внизу страницы) собирает описание задачи и email.
Кнопка не сработает, пока не отмечено согласие с офертой и обработкой
персональных данных — как на clientbase.ru.

Отправка повторяет `validateAcc()` и `test_acc()` из `main-script.js` сайта КБ:

- `POST` на `/client_register_fast.php`, тело form-urlencoded;
- поля `mconf_id`, `memail`, `referer` / `code` / `friend` из cookie
  `referer_frm`, `partner_id`, `friend_id`, пять `utm_*` из адресной строки
  и `utm_mark_title` из cookie `title_mark`;
- метка «текущее время + 1 минута» уходит и заголовком `validateAcc`,
  и одноимённой cookie;
- логин нового аккаунта берётся из ответа
  (`data.command.data.command.parameters.login`), после чего вызывается цель
  `freeaccount` и происходит переход на `<логин>.clientbase.ru/login.php`.
  При `type: "fast"` сразу, при `"standard"` — через 30 секунд;
- если обработчик отдал страницу 404 (создание аккаунтов приостановлено) или
  запрос не прошёл, форма остаётся на месте и показывает ошибку.

Настройки — в начале класса `Component` в конце `index.html`:
`KB_ORIGIN`, `REGISTER_PATH`, `MCONF_ID`, `METRIKA_ID`, `GOAL`.

### Что нужно на стороне сервера

Лендинг стоит на `keby.clientbase.ru`, обработчик — на `clientbase.ru`.
Для браузера это **разные origin**, поэтому запрос считается межсайтовым и
по умолчанию будет отклонён. Печенька `validateAcc` ставится на общий домен
`.clientbase.ru`, так что до обработчика она доедет, а вот сам запрос нужно
разрешить одним из двух способов:

**Проще всего** — проверить, отвечает ли `client_register_fast.php` на самом
`keby.clientbase.ru`. Откройте https://keby.clientbase.ru/client_register_fast.php
Если это не 404, очистите `KB_ORIGIN` в `index.html` — запрос станет
внутренним, и настраивать больше нечего.

**Иначе** — добавить на `clientbase.ru` для этого адреса заголовки:

```
Access-Control-Allow-Origin: https://keby.clientbase.ru
Access-Control-Allow-Credentials: true
Access-Control-Allow-Headers: validateAcc, Content-Type
Access-Control-Allow-Methods: POST, OPTIONS
```

и отвечать на предварительный `OPTIONS` кодом 204. Без этого браузер не
выпустит даже сам запрос — из-за заголовка `validateAcc` он сначала шлёт
`OPTIONS` и ждёт разрешения.

### Описание задачи

`client_register_fast.php` поля под ТЗ не имеет. Оно уходит параметром
`brief` и будет молча отброшено, пока обработчик не научится его принимать.

## Аналитика

Яндекс.Метрика, счётчик `19963723` — тот же, что на clientbase.ru. Цель
`freeaccount` вызывается перед переходом в созданный аккаунт, как в
`goto_new_acc()` на сайте КБ.

## Номер сборки

В подвале рядом с копирайтом стоит номер сборки — по нему видно, обновилась
ли выложенная версия. Значение задаётся полем `BUILD` в классе `Component`;
поднимайте его при каждой выкладке.

## Как устроена страница

Разметка внутри `<x-dc>` — это шаблон с подстановками `{{ ... }}`,
условиями `<sc-if>` и циклами `<sc-for>`. Данные и обработчики для него
задаёт класс в блоке `<script type="text/x-dc">` в конце `index.html`.
Отрисовывает всё это `assets/js/dc-runtime.js`.

Этот блок со скриптом должен оставаться внутри `index.html` — рантайм читает
его содержимое напрямую и не умеет подключать по `src`.
