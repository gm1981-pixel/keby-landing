/* Согласие на файлы cookie и отложенный запуск Яндекс.Метрики.
 *
 * Пока посетитель не нажал «Принять», счётчик не загружается и cookie не
 * пишутся: до согласия нет основания для обработки (152-ФЗ ст. 6, ст. 9).
 * Кнопки «Принять» и «Отклонить» равнозначны — одинаковый размер, вес и вид.
 * Отказ, который выглядит слабее согласия, делает согласие несвободным.
 *
 * Выбор хранится у посетителя (localStorage, при недоступности — cookie на
 * год) и дополнительно отправляется на api/consent.php, чтобы факт согласия
 * можно было подтвердить с нашей стороны.
 *
 * Счётчик подключается не напрямую, а по событию `cookieConsentAccepted`:
 * условие запуска видно в одном месте, и любой следующий счётчик вешается
 * туда же, а не мимо согласия.
 */
(function () {
  'use strict';

  var KEY = 'keby_cookie_consent';
  var VERSION = '2026-10-07';          // редакция текста баннера
  var CATEGORIES = 'analytics';        // какие необязательные cookie просим
  var EVENT = 'cookieConsentAccepted';
  var started = false;

  // ── Хранилище выбора ─────────────────────────────────────────────────────
  function read() {
    try {
      var v = window.localStorage.getItem(KEY);
      if (v) return v;
    } catch (e) { /* приватный режим или запрет на доступ к хранилищу */ }
    var m = document.cookie.match(/(?:^|; )keby_cookie_consent=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
  }

  function write(value) {
    try { window.localStorage.setItem(KEY, value); } catch (e) { /* см. выше */ }
    var year = 365 * 24 * 60 * 60;
    document.cookie = KEY + '=' + encodeURIComponent(value) +
      ';path=/;max-age=' + year + ';samesite=lax' +
      (location.protocol === 'https:' ? ';secure' : '');
  }

  // ── Условие запуска счётчиков ────────────────────────────────────────────
  // Единственная точка, где аналитика вообще может начаться. Ни один счётчик
  // не вызывается из разметки и не стартует по загрузке страницы.
  function allow() {
    try { window.dispatchEvent(new Event(EVENT)); }
    catch (e) {                                  // старый браузер без new Event
      var ev = document.createEvent('Event');
      ev.initEvent(EVENT, false, false);
      window.dispatchEvent(ev);
    }
  }

  window.addEventListener(EVENT, function startMetrika() {
    var id = window.KEBY_METRIKA_ID;
    if (started || !id) return;
    started = true;
    (function (m, e, t, r, i, k, a) {
      m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
      m[i].l = 1 * new Date();
      k = e.createElement(t); a = e.getElementsByTagName(t)[0];
      k.async = 1; k.src = r; a.parentNode.insertBefore(k, a);
    })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');
    window.ym(id, 'init', {
      clickmap: true,
      trackLinks: true,
      accurateTrackBounce: true,
      webvisor: true
    });
  });

  // ── Отметка на сервере ───────────────────────────────────────────────────
  // Обязанность доказать согласие лежит на операторе (152-ФЗ ст. 9 ч. 3).
  // Время и адрес посетителя проставляет обработчик, отсюда уходит остальное:
  // выбор, затронутые категории, редакция текста и страница.
  // Не блокирует интерфейс и молча пропускается, если обработчик не настроен.
  function record(choice) {
    try {
      var body = new URLSearchParams({
        action: 'cookie',
        choice: choice,
        categories: CATEGORIES,
        version: VERSION,
        page: location.href
      });
      fetch('api/consent.php', { method: 'POST', body: body, keepalive: true })
        .catch(function () {});
    } catch (e) { /* старый браузер — обойдёмся хранилищем посетителя */ }
  }

  // ── Баннер ───────────────────────────────────────────────────────────────
  var CSS =
    '#keby-cookie{position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;' +
    'max-width:780px;margin:0 auto;background:#fff;border:1px solid var(--line);' +
    'border-radius:14px;box-shadow:0 14px 40px -22px rgba(30,41,57,0.4);' +
    'padding:18px 20px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;' +
    "font-family:Onest,system-ui,sans-serif;color:var(--ink)}" +
    '#keby-cookie .keby-cookie-text{flex:1;min-width:250px}' +
    '#keby-cookie b{display:block;margin-bottom:5px;font-size:15px;font-weight:700;color:var(--ink)}' +
    '#keby-cookie p{margin:0;font-size:14px;line-height:1.5;color:var(--ink-2)}' +
    '#keby-cookie a{color:var(--ink);font-weight:600;text-decoration:underline;text-underline-offset:2px}' +
    '#keby-cookie .keby-cookie-actions{display:flex;gap:10px;flex-wrap:wrap}' +
    // Обе кнопки одинаковы: размер, вес, заливка, рамка и цвет надписи.
    // Отказаться должно быть не сложнее и не «тише», чем согласиться
    // (152-ФЗ ст. 9 ч. 1 — согласие должно быть свободным)
    '#keby-cookie button{font-family:inherit;font-size:15px;font-weight:700;' +
    'padding:11px 22px;min-width:150px;border-radius:11px;cursor:pointer;' +
    'border:1px solid var(--ink);background:#fff;color:var(--ink)}' +
    '#keby-cookie button:hover{background:var(--surface)}' +
    // На телефоне снизу висит кнопка «Собрать мою CRM» — поднимаем баннер
    // над ней, иначе они перекрываются
    '@media (max-width:900px){#keby-cookie{bottom:84px}}' +
    '@media (max-width:520px){#keby-cookie{padding:16px}' +
    '#keby-cookie .keby-cookie-actions{width:100%}' +
    '#keby-cookie button{flex:1;min-width:0}}';

  function show() {
    var style = document.createElement('style');
    style.textContent = CSS;
    document.head.appendChild(style);

    var box = document.createElement('div');
    box.id = 'keby-cookie';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-label', 'Согласие на файлы cookie');
    box.innerHTML =
      '<div class="keby-cookie-text">' +
      '<b>Мы используем файлы cookie</b>' +
      '<p>Технические файлы нужны для работы сайта. Аналитические — сервис ' +
      'статистики Яндекс.Метрика — помогают понять, какими разделами ' +
      'пользуются; их мы включаем только с вашего согласия. Подробнее — в ' +
      '<a href="docs/privacy.pdf" target="_blank" rel="noopener">политике обработки ' +
      'персональных данных</a>.</p>' +
      '</div>' +
      '<div class="keby-cookie-actions">' +
      '<button type="button" class="keby-cookie-accept">Принять</button>' +
      '<button type="button" class="keby-cookie-decline">Отклонить</button>' +
      '</div>';
    document.body.appendChild(box);

    function choose(choice) {
      write(choice);
      record(choice);
      if (choice === 'yes') allow();
      box.remove();
    }
    box.querySelector('.keby-cookie-accept').addEventListener('click', function () { choose('yes'); });
    box.querySelector('.keby-cookie-decline').addEventListener('click', function () { choose('no'); });
  }

  // ── Запуск ───────────────────────────────────────────────────────────────
  // Отказ — это окончательное «нет»: счётчик не запускается вовсе, баннер
  // больше не показывается. Пока выбора нет, аналитики тоже нет.
  var saved = read();
  if (saved === 'yes') {
    allow();
  } else if (saved !== 'no') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', show);
    } else {
      show();
    }
  }

  // Чтобы человек мог передумать: keby.resetCookieConsent() в консоли или
  // ссылка «Настройки cookie» в подвале.
  window.keby = window.keby || {};
  window.keby.resetCookieConsent = function () {
    try { window.localStorage.removeItem(KEY); } catch (e) {}
    document.cookie = KEY + '=;path=/;max-age=0';
    location.reload();
  };
})();
