<?php
/* Приём файла с техническим заданием.
 *
 * Файл загружается на лендинг, здесь из него извлекается текст, и дальше в
 * регистрацию уходит только текст. На manage.clientbase.ru файлы не
 * отправляются сознательно: приём чужих документов на систему управления
 * аккаунтами — лишняя поверхность для атаки. Разбор идёт тут, в контуре,
 * который не жалко, а наружу выходит уже проверенная строка.
 *
 * Сам файл мы не храним. Извлекли текст — отдали — забыли: тогда нет ни
 * вопроса о сроках хранения, ни об ответственности за чужие персональные
 * данные внутри ТЗ. Если понадобится прикладывать оригинал к карточке
 * аккаунта, хранение надо будет заводить отдельно и с политикой удаления.
 *
 *   POST api/brief.php   file=<документ>   →  { ok, text, chars, name, kind }
 *
 * Настройка через переменные окружения (см. api/_lib.php):
 *   KEBY_BRIEF_MAX_BYTES   предел размера файла, по умолчанию 10 МБ
 *   KEBY_BRIEF_MAX_CHARS   предел длины извлечённого текста, по умолчанию 100000
 *
 * Внимание: upload_max_filesize и post_max_size в PHP по умолчанию 2 МБ —
 * предел здесь ни на что не влияет, пока они не подняты.
 */
define('KEBY_API', 1);
require __DIR__ . '/_lib.php';

const BRIEF_TYPES = ['txt', 'md', 'rtf', 'docx', 'pdf'];

require_post();
require_same_origin();

// ── Лимиты ────────────────────────────────────────────────────────────────
function brief_max_bytes(): int {
    $v = (int)(getenv('KEBY_BRIEF_MAX_BYTES') ?: 0);
    return $v > 0 ? $v : 10 * 1024 * 1024;
}
function brief_max_chars(): int {
    $v = (int)(getenv('KEBY_BRIEF_MAX_CHARS') ?: 0);
    return $v > 0 ? $v : 100000;
}

// Не больше десяти разборов с адреса в час: разбор документа стоит процессора,
// и крутить его бесконечно чужими руками незачем. Лимит необязательный —
// если каталог состояния недоступен, приём файла от этого падать не должен.
function brief_rate_ok(): bool {
    try {
        $db = db();
        $ip = client_ip();
        if (count_events($db, 'brief', $ip, 3600) >= 10) return false;
        add_event($db, 'brief', $ip);
    } catch (Throwable $e) { /* лимит не обязателен для работы */ }
    return true;
}

// ── Текст из разных форматов ──────────────────────────────────────────────

// Простой текст приходит в любой кодировке: из Блокнота — обычно CP1251,
// из редактора кода — UTF-8. Приводим к UTF-8, иначе в карточке будут кракозябры.
function brief_to_utf8(string $raw): string {
    if ($raw === '') return '';
    $bom = "\xEF\xBB\xBF";
    if (strncmp($raw, $bom, 3) === 0) $raw = substr($raw, 3);
    $enc = mb_detect_encoding($raw, ['UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-5'], true);
    if ($enc === false) $enc = 'Windows-1251';
    if ($enc === 'UTF-8') return $raw;
    $out = @iconv($enc, 'UTF-8//TRANSLIT', $raw);
    return $out === false ? mb_convert_encoding($raw, 'UTF-8', $enc) : $out;
}

// .docx — это zip, внутри word/document.xml. Сторонние библиотеки не нужны:
// абзацы размечены </w:p>, разрывы строк <w:br/>, табуляции <w:tab/>.
function brief_from_docx(string $path): ?string {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false || $xml === '') return null;

    $xml = preg_replace('~<w:(?:tab)\b[^>]*/?>~', "\t", $xml);
    $xml = preg_replace('~<w:(?:br|cr)\b[^>]*/?>~', "\n", $xml);
    $xml = preg_replace('~</w:p\s*>~', "\n", $xml);
    $xml = preg_replace('~</w:tr\s*>~', "\n", $xml);
    $xml = preg_replace('~</w:tc\s*>~', "\t", $xml);
    $text = strip_tags($xml);
    return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// .rtf — управляющие слова со обратной косой. Разбираем ровно столько,
// сколько нужно для текста: кодировка, \uN, \'xx, абзацы, служебные группы.
function brief_from_rtf(string $raw): ?string {
    if (strncmp($raw, '{\\rtf', 5) !== 0) return null;

    // Кодировка однобайтовых вставок \'xx
    $cp = 'Windows-1251';
    if (preg_match('~\\\\ansicpg(\d+)~', $raw, $m)) {
        $known = ['1250' => 'Windows-1250', '1251' => 'Windows-1251', '1252' => 'Windows-1252',
                  '1253' => 'Windows-1253', '1254' => 'Windows-1254', '65001' => 'UTF-8'];
        $cp = $known[$m[1]] ?? 'Windows-1251';
    }

    // Служебные группы целиком: шрифты, цвета, стили, сведения о документе
    // Группа вида {\fonttbl …} или {\*\themedata …}, в том числе с одним
    // уровнем вложенности внутри
    $raw = preg_replace('~\{(?:\\\\\*)?\\\\(?:fonttbl|colortbl|stylesheet|info|pict|object|' .
                        'themedata|colorschememapping|latentstyles|datastore)[^{}]*' .
                        '(?:\{[^{}]*\}[^{}]*)*\}~s', '', $raw);

    $raw = preg_replace('~\\\\par[d]?\b~', "\n", $raw);
    $raw = preg_replace('~\\\\(?:line|page)\b~', "\n", $raw);
    $raw = preg_replace('~\\\\tab\b~', "\t", $raw);

    // \uN — символ Unicode, следом идёт запасной символ, его выбрасываем
    $raw = preg_replace_callback('~\\\\u(-?\d+)\s?\??~', function ($m) {
        $code = (int)$m[1];
        if ($code < 0) $code += 65536;
        return mb_chr($code, 'UTF-8') ?: '';
    }, $raw);

    // \'xx — байт в кодировке документа
    $raw = preg_replace_callback("~\\\\'([0-9a-fA-F]{2})~", function ($m) use ($cp) {
        $byte = chr(hexdec($m[1]));
        if ($cp === 'UTF-8') return $byte;
        $out = @iconv($cp, 'UTF-8//IGNORE', $byte);
        return $out === false ? '' : $out;
    }, $raw);

    $raw = preg_replace('~\\\\[a-zA-Z]+-?\d*\s?~', '', $raw);  // прочие команды
    $raw = str_replace(['\\{', '\\}', '\\\\'], ['{', '}', '\\'], $raw);
    $raw = str_replace(['{', '}'], '', $raw);
    return $raw;
}

// .pdf — только через pdftotext из poppler-utils и только если в файле есть
// текстовый слой. Скан страниц без распознавания не прочитает никто, и
// обещать обратное нечестно.
function brief_from_pdf(string $path): ?string {
    $bin = brief_pdftotext();
    if ($bin === null) return null;
    $cmd = escapeshellcmd($bin) . ' -q -enc UTF-8 -eol unix ' . escapeshellarg($path) . ' -';
    $out = @shell_exec($cmd . ' 2>/dev/null');
    return is_string($out) && trim($out) !== '' ? $out : null;
}

function brief_pdftotext(): ?string {
    foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/bin/pdftotext'] as $p) {
        if (@is_executable($p)) return $p;
    }
    return null;
}

// ── Чистка ────────────────────────────────────────────────────────────────
// Текст уходит в чужую систему и будет показан человеку, поэтому к отправке
// готовим осознанно, а не «как получилось».
function brief_clean(string $text, int $limit): array {
    $text = brief_to_utf8($text);
    $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
    // Управляющие символы, кроме перевода строки и табуляции
    $text = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $text);
    $text = preg_replace('~[ \t]+~', ' ', $text);
    $text = preg_replace('~ *\n *~', "\n", $text);
    $text = preg_replace('~\n{3,}~', "\n\n", $text);
    $text = trim($text);

    // Формульная инъекция: строка, начинающаяся с = или @, при выгрузке в
    // Excel выполнится как формула. Ломаем это пробелом в начале — в отличие
    // от апострофа он незаметен и не трогает маркированные списки с дефисом.
    $text = preg_replace('~^(?=[=@])~m', ' ', $text);

    $cut = false;
    if (mb_strlen($text, 'UTF-8') > $limit) {
        $text = mb_substr($text, 0, $limit, 'UTF-8');
        $cut = true;
    }
    return [$text, $cut];
}

// ── Обработка запроса ─────────────────────────────────────────────────────
$f = $_FILES['file'] ?? null;
if (!$f || !is_array($f)) fail(400, 'no_file');

if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $tooBig = in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    fail(400, $tooBig ? 'too_big' : 'upload');
}
if (!is_uploaded_file($f['tmp_name'])) fail(400, 'upload');
if (($f['size'] ?? 0) > brief_max_bytes()) fail(413, 'too_big');
if (!brief_rate_ok()) fail(429, 'rate');

$name = (string)($f['name'] ?? 'file');
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
// Расширению верим только как подсказке: что внутри, решает содержимое
$head = (string)@file_get_contents($f['tmp_name'], false, null, 0, 8);
if (strncmp($head, "PK\x03\x04", 4) === 0 && $ext !== 'docx') $ext = 'docx';
if (strncmp($head, '%PDF-', 5) === 0) $ext = 'pdf';
if (strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0) fail(415, 'old_doc');   // .doc, .xls
if (!in_array($ext, BRIEF_TYPES, true)) fail(415, 'type');

$text = null;
switch ($ext) {
    case 'docx': $text = brief_from_docx($f['tmp_name']); break;
    case 'pdf':  $text = brief_from_pdf($f['tmp_name']);  break;
    case 'rtf':  $text = brief_from_rtf((string)@file_get_contents($f['tmp_name'])); break;
    default:     $text = (string)@file_get_contents($f['tmp_name']);
}
@unlink($f['tmp_name']);   // оригинал не храним

if ($text === null || trim($text) === '') {
    log_line('brief: не извлечён текст, ' . $ext . ', ' . (int)($f['size'] ?? 0) . ' байт');
    fail(422, $ext === 'pdf' ? 'pdf_empty' : 'empty');
}

[$clean, $cut] = brief_clean($text, brief_max_chars());
if ($clean === '') fail(422, 'empty');

log_line('brief: ' . $ext . ', ' . (int)($f['size'] ?? 0) . ' байт → ' .
         mb_strlen($clean, 'UTF-8') . ' символов' . ($cut ? ' (обрезано)' : ''));

respond(200, [
    'ok'        => true,
    'text'      => $clean,
    'chars'     => mb_strlen($clean, 'UTF-8'),
    'truncated' => $cut,
    'name'      => mb_substr(preg_replace('~[\x00-\x1F/\\\\]~', '', $name), 0, 120, 'UTF-8'),
    'kind'      => $ext,
]);
