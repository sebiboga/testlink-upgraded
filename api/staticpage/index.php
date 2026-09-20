<?php
/**
 * BFF API — Static Help / Instructions page
 *
 * Ports the last standalone lib/general/* screen — lib/general/staticPage.php,
 * the "help / instructions" viewer that serves the locale texts.php content
 * ($TLS_htmltext / $TLS_htmltext_title) for every instructions help key
 * (assignReqs, editTc, searchTc, searchReqSpec, planAddTC, planUpdateTC,
 * test_urgency, showMetrics, ...). Modernized screen:
 * gui/templates/documentation/staticPage.html.
 *
 * Routes (session-based; 401 anonymous):
 *   GET ?action=show&key=<key>[&refreshTree=0|1]
 *     -> { status, key, title, content_html, notFound, locale, refreshTree }
 *
 * Behavioural parity with the legacy controller:
 *   - the locale is resolved from the session (fallback to the configured
 *     default_language) and any locale whose texts.php is missing falls back
 *     to en_GB;
 *   - the key is a plain string parameter; only printable ASCII letters,
 *     digits and '_' are honoured (anything else -> 400, instead of the
 *     legacy HTML-escape-and-render of an arbitrary string);
 *   - a key that exists in no texts.php variant yields the legacy
 *     "ask administrator to update localization file" message body
 *     (notFound=true) rather than an error status.
 *
 * No rights gate: the legacy page required any authenticated session (any
 * logged-in user can open the help/instructions pages). Refs #1501.
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

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : 'show';
if ($action !== 'show') {
    http_response_code(405);
    echo json_encode(array('status' => 'error',
                           'message' => 'Method not allowed',
                           'allowed' => array('show')));
    exit;
}

$key = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
if ($key === '' || preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1) {
    http_response_code(400);
    echo json_encode(array('status' => 'error',
                           'message' => 'Invalid or missing contact key parameter'));
    exit;
}

$refreshTree = 0;
if (isset($_GET['refreshTree'])) {
    $refreshTree = ((int)$_GET['refreshTree'] === 1) ? 1 : 0;
}

/**
 * Load the $TLS_htmltext / $TLS_htmltext_title arrays for a given locale.
 * Returns null when the locale bundle file is missing.
 */
function staticpageLoadLocale($locale) {
    if (!is_string($locale) || preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale) !== 1) {
        return null;
    }
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

/**
 * Map a client bundle short code (de, ro, pt, ...) to the full TestLink
 * locale dir used for the texts.php help bundles. Prefers the canonical
 * region variant that ships a texts.php (pt_PT over pt_BR, es_ES over
 * es_AR), mirroring the i18n.js LOCALE_MAP direction.
 */
function staticpageLocaleDir($short) {
    $map = array(
        'en' => 'en_GB', 'ro' => 'ro_RO', 'de' => 'de_DE', 'fr' => 'fr_FR',
        'es' => 'es_ES', 'it' => 'it_IT', 'pt' => 'pt_PT', 'ru' => 'ru_RU',
        'ja' => 'ja_JP', 'zh' => 'zh_CN', 'ko' => 'ko_KR', 'nl' => 'nl_NL',
        'pl' => 'pl_PL', 'cs' => 'cs_CZ', 'fi' => 'fi_FI', 'id' => 'id_ID',
    );
    return isset($map[$short]) ? $map[$short] : null;
}

$locale = isset($_SESSION['locale']) ? (string)$_SESSION['locale'] : '';
if ($locale === '' && isset($tlCfg) && !empty($tlCfg->default_language)) {
    $locale = $tlCfg->default_language;
}

// Explicit client-locale hint (from the TLi18n bundle the screen is
// displaying): superset of legacy parity — the legacy page had no locale
// switching at all. Only honoured when that locale ships a real texts.php.
if (isset($_GET['locale'])) {
    $hint = strtolower(trim((string)$_GET['locale']));
    if (preg_match('/^[a-z]{2}$/', $hint) === 1) {
        $hintDir = staticpageLocaleDir($hint);
        if (!is_null($hintDir) && is_file(dirname(__FILE__) . '/../../locale/' . $hintDir . '/texts.php')) {
            $locale = $hintDir;
        }
    }
}

$strings = staticpageLoadLocale($locale);
if (is_null($strings) && $locale !== 'en_GB') {
    $strings = staticpageLoadLocale('en_GB');
    $locale = 'en_GB';
}

if (is_null($strings)) {
    // No locale texts.php at all — en_GB is always shipped, treat as fatal.
    http_response_code(500);
    echo json_encode(array('status' => 'error',
                           'message' => 'No help texts bundle available'));
    exit;
}

$found = isset($strings['body'][$key]);
// Legacy parity: staticPage.php left pageTitle empty for unknown keys; the
// front-end falls back to the raw key in that case (lang_get('title_help')
// has no server-side definition).
$title = $found ? $strings['title'][$key] : '';
$content = $found ? $strings['body'][$key] : sprintf(
    'Please, ask administrator to update localization file ' .
    '(&lt;testlink_root&gt;/locale/%s/texts.php) - missing key: %s',
    htmlspecialchars($locale, ENT_QUOTES, 'UTF-8'),
    htmlspecialchars($key, ENT_QUOTES, 'UTF-8')
);

echo json_encode(array(
    'status'      => 'ok',
    'key'         => $key,
    'title'       => $title,
    'content_html'=> $content,
    'notFound'    => !$found,
    'locale'      => $locale,
    'refreshTree' => $refreshTree,
));