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

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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
    $path = dirname(__FILE__) . '/../../locale/' . $locale . '/texts.php';
    if (!is_file($path)) {
        return null;
    }
    $TLS_htmltext = array();
    $TLS_htmltext_title = array();
    include $path;
    return array('body' => $TLS_htmltext, 'title' => $TLS_htmltext_title);
}

$locale = isset($_SESSION['locale']) ? (string)$_SESSION['locale'] : '';
if ($locale === '' && isset($tlCfg) && !empty($tlCfg->default_language)) {
    $locale = $tlCfg->default_language;
}
$strings = staticpageLoadLocale($locale);
if (is_null($strings)) {
    $strings = staticpageLoadLocale('en_GB');
}

if (is_null($strings)) {
    // No locale texts.php at all — en_GB is always shipped, treat as fatal.
    http_response_code(500);
    echo json_encode(array('status' => 'error',
                           'message' => 'No help texts bundle available'));
    exit;
}

$found = isset($strings['body'][$key]);
$title = $found ? $strings['title'][$key] : lang_get('title_help');
$content = $found ? $strings['body'][$key] : sprintf(
    'Please, ask administrator to update localization file ' .
    '(&lt;testlink_root&gt;/locale/%s/texts.php) - missing key: %s',
    $locale, htmlspecialchars($key, ENT_QUOTES, 'UTF-8')
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