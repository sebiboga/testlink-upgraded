<?php
/**
 * BFF API — Localized Help popup viewer
 *
 * Replaces the legacy standalone Help popup
 * lib/general/show_help.php (Refs #1552).
 *
 * The legacy screen was broken dead code on the upgraded branch:
 *   - it referenced the undefined constant TL_HELP_RPATH (PHP 8 fatal);
 *   - it rendered gui/help/<locale>/<key>.html files that were deleted by
 *     commit 3e6994b71 (their content was migrated into the locale
 *     $TLS_htmltext / $TLS_htmltext_title bundles in locale/<loc>/texts.php —
 *     the same source staticPage (#1501) serves).
 *
 * Routes (session-based; 401 anonymous):
 *   GET ?action=index            -> { status, items: [{key,title,hasContent}], locale }
 *   GET ?action=show&help=<key>[&locale=xx]
 *                                -> { status, key, title, content_html, notFound, locale }
 *
 * Behavioural parity with the legacy controller:
 *   - key honoured as a plain string, validated ^[A-Za-z0-9_]+$ (400 otherwise);
 *   - content resolved from the session locale (fallback: configured default
 *     language, then en_GB); explicit client-locale hint honoured only when
 *     that locale ships a texts.php;
 *   - an unknown key yields the legacy "ask administrator to update
 *     localization file" message body with notFound=true (not an error status).
 *
 * No rights gate: the legacy page required only an authenticated session
 * (any logged-in user can open the Help pages).
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
    exit;
}

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : 'index';
if ($action !== 'index' && $action !== 'show') {
    http_response_code(405);
    echo json_encode(array('status' => 'error',
                           'message' => 'Method not allowed',
                           'allowed' => array('index', 'show')));
    exit;
}

function helpLoadLocale($locale) {
    $path = dirname(__FILE__) . '/../../locale/' . $locale . '/texts.php';
    if (!is_file($path)) {
        return null;
    }
    $TLS_htmltext = array();
    $TLS_htmltext_title = array();
    ob_start();
    include $path;
    ob_end_clean();
    return array('body' => $TLS_htmltext, 'title' => $TLS_htmltext_title);
}

function helpLocaleDir($short) {
    $map = array(
        'en' => 'en_GB', 'ro' => 'ro_RO', 'de' => 'de_DE', 'fr' => 'fr_FR',
        'es' => 'es_ES', 'it' => 'it_IT', 'pt' => 'pt_PT', 'ru' => 'ru_RU',
        'ja' => 'ja_JP', 'zh' => 'zh_CN', 'ko' => 'ko_KR', 'nl' => 'nl_NL',
        'pl' => 'pl_PL', 'cs' => 'cs_CZ', 'fi' => 'fi_FI', 'id' => 'id_ID',
    );
    return isset($map[$short]) ? $map[$short] : null;
}

function helpIsValidLocaleDir($locale) {
    return is_string($locale) && preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale) === 1;
}

$locale = isset($_SESSION['locale']) ? (string)$_SESSION['locale'] : '';
if ($locale === '' && isset($tlCfg) && !empty($tlCfg->default_language)) {
    $locale = $tlCfg->default_language;
}
if (!helpIsValidLocaleDir($locale)
    || !is_file(dirname(__FILE__) . '/../../locale/' . $locale . '/texts.php')) {
    $locale = '';
}

if (isset($_GET['locale'])) {
    $hint = strtolower(trim((string)$_GET['locale']));
    if (preg_match('/^[a-z]{2}$/', $hint) === 1) {
        $hintDir = helpLocaleDir($hint);
        if (!is_null($hintDir) && is_file(dirname(__FILE__) . '/../../locale/' . $hintDir . '/texts.php')) {
            $locale = $hintDir;
        }
    }
}

$strings = helpLoadLocale($locale);
if (is_null($strings) && $locale !== 'en_GB') {
    $strings = helpLoadLocale('en_GB');
    $locale = 'en_GB';
}
if (is_null($strings)) {
    http_response_code(500);
    echo json_encode(array('status' => 'error', 'message' => 'No help texts bundle available'));
    exit;
}

if ($action === 'index') {
    $items = array();
    foreach ($strings['body'] as $key => $body) {
        $items[] = array(
            'key'        => $key,
            'title'      => isset($strings['title'][$key]) ? $strings['title'][$key] : $key,
            'hasContent' => ($body !== '' && $body !== null),
        );
    }
    echo json_encode(array('status' => 'ok', 'items' => $items, 'locale' => $locale));
    exit;
}

$key = isset($_GET['help']) ? trim((string)$_GET['help']) : '';
if ($key === '' || preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1) {
    http_response_code(400);
    echo json_encode(array('status' => 'error',
                           'message' => 'Invalid or missing help key parameter'));
    exit;
}

$found = isset($strings['body'][$key]);
$title = $found ? (isset($strings['title'][$key]) ? $strings['title'][$key] : $key) : $key;
$content = $found ? $strings['body'][$key] : sprintf(
    'Please, ask administrator to update localization file ' .
    '(&lt;testlink_root&gt;/locale/%s/texts.php) - missing key: %s',
    htmlspecialchars($locale, ENT_QUOTES, 'UTF-8'),
    htmlspecialchars($key, ENT_QUOTES, 'UTF-8')
);

echo json_encode(array(
    'status'       => 'ok',
    'key'          => $key,
    'title'        => $title,
    'content_html' => $content,
    'notFound'     => !$found,
    'locale'       => $locale,
));