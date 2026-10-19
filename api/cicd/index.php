<?php
/**
 * BFF API — CI/CD Integration hub (cicdHub)
 *
 * Backs gui/templates/documentation/cicdHub.html — the net-new in-app
 * "CI/CD Integration hub" screen planned in #1940 (Refs #1064). 1.9.20 and
 * 2.0.1 shipped no CI/CD integration screen at all; the sibling
 * `cicdPublish` screen (gui/templates/results/cicdPublish.html +
 * api/cicdpublish) is the results *half* — it actually publishes parsed
 * JUnit/JSON results. This hub is the documentation/reference half: it
 * explains the generic API-based publish workflow, the supported result
 * formats, the token/session auth, and ships copy-ready example pipelines
 * (GitHub Actions / GitLab CI / Jenkins / curl) that call the existing
 * modern publish endpoint, plus deep links to the related modern screens.
 *
 * Plain PHP, session-based auth, JSON I/O, same-origin guard — the same
 * contract as every other api/* BFF. Every human label is returned as an
 * i18n KEY (cicdhub.*) so the client renders it through TLi18n and no
 * string is hardcoded server-side; the same keys live in every client
 * locale bundle. Workflow snippets are returned as DATA (never HTML) so
 * the client renders them through .text() — no server-side markup.
 *
 * Route: GET|HEAD ?action=init (default)
 *
 * Refs #1940, #1064.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../_guard.php');

require_once('common.php');

doSessionStart();
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Session gate BEFORE the DB connect (the #1677 lesson): an anonymous caller
// must get a clean 401 JSON, never a leaked raw dbms_msg on an outage.
$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
                           'message' => 'Not authenticated'));
    exit;
}

$db = new database(DB_TYPE);
doDBConnect($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
                           'message' => 'User not found'));
    exit;
}

// Refs #1614: enforce the session-inactivity timeout once the user resolves, so
// a stale tab cannot keep serving past sessionInactivityTimeout.
bffEnforceSession($db);

$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? 'init');

if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(array('status' => 'error', 'code' => 'method_not_allowed',
                           'message' => 'Method not allowed'));
    exit;
}
if ($action !== 'init') {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
                           'message' => 'Unknown or missing action'));
    exit;
}

$ctx = 'tproject_id=' . intval($_SESSION['testprojectID'] ?? 0) .
       '&tplan_id=' . intval($_SESSION['testplanID'] ?? 0);

// The publish endpoint plus its two documented request shapes (multipart
// upload / raw body). Kept here so the hub and the apiReference day never drift.
$endpoint = '/api/cicdpublish/index.php?action=publish';

// Ordered publish workflow — the steps a pipeline must perform. Text is KEY only.
$steps = array(
    array('id' => 'prepare'),
    array('id' => 'configure'),
    array('id' => 'run'),
    array('id' => 'publish'),
    array('id' => 'review'),
);

// Supported result formats (what the publish endpoint can parse).
$formats = array(
    array('id' => 'junit', 'badge' => 'JUnit XML', 'icon' => 'fas fa-file-code'),
    array('id' => 'json',  'badge' => 'JSON',      'icon' => 'fas fa-file-code'),
);

// Authentication options (session cookie or API key token).
$authModes = array(
    array('id' => 'session', 'icon' => 'fas fa-cookie-bite'),
    array('id' => 'apikey',  'icon' => 'fas fa-key'),
);

// Copy-ready example pipelines. `snippet` is DATA (plain text), rendered
// client-side with .text(); every snippet carries the header the shared CSRF
// guard accepts (X-Requested-With: XMLHttpRequest) — the #1940 BLOCKER lesson:
// a pipeline without it gets a 403 on every real call.
$examples = array(
    array('id' => 'github', 'icon' => 'fab fa-github',
          'snippet' =>
            "name: TestLink publish\n" .
            "on: [push]\n" .
            "jobs:\n" .
            "  publish:\n" .
            "    runs-on: ubuntu-latest\n" .
            "    steps:\n" .
            "      - uses: actions/checkout@v4\n" .
            "      - name: Publish JUnit results to TestLink\n" .
            "        run: |\n" .
            "          curl -sS -X POST \\\n" .
            "            \"\$TESTLINK_BASE{$endpoint}&tplan_id=\$PLAN_ID\" \\\n" .
            "            -H \"X-Requested-With: XMLHttpRequest\" \\\n" .
            "            -b \"\$TESTLINK_COOKIE\" \\\n" .
            "            -F \"file=@build/reports/junit.xml\" \\\n" .
            "            -F \"format=junit\"\n"),
    array('id' => 'gitlab', 'icon' => 'fab fa-gitlab',
          'snippet' =>
            "publish-testlink:\n" .
            "  stage: test\n" .
            "  script:\n" .
            "    - |\n" .
            "      curl -sS -X POST \\\n" .
            "        \"\$TESTLINK_BASE{$endpoint}&tplan_id=\$PLAN_ID\" \\\n" .
            "        -H \"X-Requested-With: XMLHttpRequest\" \\\n" .
            "        -b \"\$TESTLINK_COOKIE\" \\\n" .
            "        -F \"file=@build/reports/junit.xml\" \\\n" .
            "        -F \"format=junit\"\n"),
    array('id' => 'jenkins', 'icon' => 'fas fa-robot',
          'snippet' =>
            "sh '''\n" .
            "  curl -sS -X POST \\\n" .
            "    \"\$TESTLINK_BASE{$endpoint}&tplan_id=\$PLAN_ID\" \\\n" .
            "    -H \"X-Requested-With: XMLHttpRequest\" \\\n" .
            "    -b \"\$TESTLINK_COOKIE\" \\\n" .
            "    -F \"file=@build/reports/junit.xml\" \\\n" .
            "    -F \"format=junit\"\n" .
            "'''\n"),
    array('id' => 'curl', 'icon' => 'fas fa-terminal',
          'snippet' =>
            "curl -sS -X POST \\\n" .
            "  \"\$TESTLINK_BASE{$endpoint}&tplan_id=\$PLAN_ID\" \\\n" .
            "  -H \"X-Requested-With: XMLHttpRequest\" \\\n" .
            "  -b \"\$TESTLINK_COOKIE\" \\\n" .
            "  -F \"file=@build/reports/junit.xml\" \\\n" .
            "  -F \"format=junit\"\n"),
);

// Documented error codes the publish endpoint returns, so a pipeline can
// branch on them. IDs map to cicdhub.code.<id> keys.
$errors = array(
    array('id' => 'missing_file',        'http' => 400),
    array('id' => 'unsupported_format',  'http' => 400),
    array('id' => 'parse_error',         'http' => 422),
    array('id' => 'invalid_request',     'http' => 400),
    array('id' => 'no_right',            'http' => 403),
    array('id' => 'not_authenticated',   'http' => 401),
    array('id' => 'session_expired',     'http' => 401),
    array('id' => 'method_not_allowed',  'http' => 405),
    array('id' => 'file_too_large',      'http' => 413),
);

// Related modern screens inside TestLink (deep-link the current context).
$related = array(
    array('labelKey' => 'cicdhub.link.publish',
          'href' => "/gui/templates/results/cicdPublish.html?{$ctx}", 'external' => false),
    array('labelKey' => 'cicdhub.link.automation',
          'href' => "/gui/templates/results/automationReports.html?{$ctx}", 'external' => false),
    array('labelKey' => 'cicdhub.link.apiReference',
          'href' => "/gui/templates/documentation/apiReference.html?{$ctx}", 'external' => false),
    array('labelKey' => 'cicdhub.link.openApi',
          'href' => "/gui/templates/documentation/openApiSpec.html?{$ctx}", 'external' => false),
);

echo json_encode(array(
    'status'    => 'ok',
    'endpoint'  => $endpoint,
    'methods'   => array('POST'),
    'steps'     => $steps,
    'formats'   => $formats,
    'authModes' => $authModes,
    'examples'  => $examples,
    'errors'    => $errors,
    'related'   => $related,
));
