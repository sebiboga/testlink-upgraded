<?php
/**
 * OpenAPI Specification viewer - BFF
 *
 * Backs gui/templates/documentation/openApiSpec.html - the net-new
 * "OpenAPI Specification" screen requested as the remaining slice of parent
 * enhancement #1072 (documented REST API / OpenAPI / webhooks). The REST-API
 * documentation half was closed by the apiReference hub (#1937); 1.9.20 and
 * 2.0.1 shipped no machine-readable OpenAPI artifact at all. This BFF
 * GENERATES a real OpenAPI 3.1 document describing the BFF layer (servers,
 * the shared session/X-Requested-With security contract, the curated
 * integrator endpoints, the shared error envelope, and the full discovered
 * module inventory as x-testlink-modules) and serves it as JSON or YAML.
 *
 * Plain PHP, session-based auth, JSON I/O, same-origin guard - the same
 * contract as every other api/* BFF. Human labels on the SCREEN are returned
 * as i18n KEYS (oas.*) so the client renders them through TLi18n; the spec
 * document itself is a machine-facing technical artifact and its
 * title/descriptions are deliberately English (that is what OpenAPI tools
 * expect) - no user-facing UI string is hardcoded.
 *
 * Routes:
 *   GET|HEAD ?action=init
 *     200 {status, auth, ctx, specMeta{}, featured[], related[]}
 *   GET|HEAD ?action=spec[&format=json|yaml]
 *     200 application/json | text/yaml  (the OpenAPI document)
 *   400 unknown_action | bad_format
 *   401 not_authenticated | session_expired
 *   403 foreign-origin (bffSameOriginGuard on state-changing methods)
 *   405 method_not_allowed
 *
 * Refs #1944 (tracking), parent feature #1072.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../_guard.php');

require_once('common.php');

doSessionStart();
bffSameOriginGuard();

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo json_encode(array('status' => 'error', 'code' => 'method_not_allowed',
                           'message' => 'Method not allowed'));
    exit;
}

$action = (string)($_GET['action'] ?? 'init');

// Session gate BEFORE the DB connect (the #1677 lesson).
$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
                           'message' => 'Not authenticated'));
    exit;
}

$db = new database(DB_TYPE);
doDBConnect($db);

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'code' => 'not_authenticated',
                           'message' => 'User not found'));
    exit;
}

// Refs #1614: enforce the session-inactivity timeout once the user resolves.
bffEnforceSession($db);

/**
 * Discover every api/<area>/index.php module on disk (same authoritative
 * inventory as api/apireference). Machine paths only.
 *
 * @return string[] sorted area names
 */
function oas_discover_modules()
{
    $areas = array();
    foreach (glob(__DIR__ . '/../' . '*/index.php') ?: array() as $file) {
        $area = basename(dirname($file));
        if ($area !== '' && $area[0] !== '_') {
            $areas[] = $area;
        }
    }
    $areas = array_values(array_unique($areas));
    sort($areas, SORT_STRING);
    return $areas;
}

/**
 * The curated integrator endpoint catalogue. Every row was verified against
 * the shipped BFF contract (method + action) by the apiReference screen.
 *
 * @return array[]
 */
function oas_featured_endpoints()
{
    return array(
        array('tag' => 'session', 'method' => 'GET',
              'path' => '/api/auth/index.php',
              'summary' => 'Probe the current session (logged-in state, user id, login).'),
        array('tag' => 'session', 'method' => 'GET',
              'path' => '/api/aside/index.php?action=init',
              'summary' => 'Render the ASIDE menu for the current user and context.'),
        array('tag' => 'projects', 'method' => 'GET',
              'path' => '/api/projects/index.php',
              'summary' => 'List the test projects visible to the current user.'),
        array('tag' => 'projects', 'method' => 'POST',
              'path' => '/api/projects/index.php',
              'summary' => 'Create or update a test project (mgt_modify_project).'),
        array('tag' => 'plans', 'method' => 'GET',
              'path' => '/api/plans/index.php',
              'summary' => 'List the test plans of a project.'),
        array('tag' => 'plans', 'method' => 'POST',
              'path' => '/api/plans/index.php',
              'summary' => 'Create or update a test plan (mgt_testplan_create).'),
        array('tag' => 'specification', 'method' => 'GET',
              'path' => '/api/testcasesedit/index.php',
              'summary' => 'Load a test case version for editing (summary, steps, preconditions).'),
        array('tag' => 'specification', 'method' => 'POST',
              'path' => '/api/testcasesedit/index.php',
              'summary' => 'Save a test case version (mgt_modify_tc / canEditExecuted gate).'),
        array('tag' => 'execution', 'method' => 'GET',
              'path' => '/api/execsetresults/index.php',
              'summary' => 'Load the execution context for setting results on a build.'),
        array('tag' => 'execution', 'method' => 'POST',
              'path' => '/api/execsetresults/index.php',
              'summary' => 'Record an execution result (testplan_execute on the owning project).'),
        array('tag' => 'reports', 'method' => 'GET',
              'path' => '/api/resultsimport/index.php?action=init',
              'summary' => 'Prepare a TestLink-XML results import for a plan and build.'),
        array('tag' => 'reports', 'method' => 'POST',
              'path' => '/api/cicdpublish/index.php?action=publish',
              'summary' => 'Publish a JUnit XML or JSON CI/CD result artifact (testplan_execute).'),
        array('tag' => 'attachments', 'method' => 'GET',
              'path' => '/api/attachments/index.php',
              'summary' => 'List attachments of an object (uploadedFile[] contract on upload).'),
        array('tag' => 'reports', 'method' => 'GET',
              'path' => '/api/reports/index.php?action=<report_action>',
              'summary' => 'Dispatch to a modern report by action id (testplan_metrics).'),
        array('tag' => 'meta', 'method' => 'GET',
              'path' => '/api/openapi/index.php?action=spec',
              'summary' => 'This OpenAPI 3.1 document (JSON or ?format=yaml).'),
    );
}

/**
 * The shared error envelope: HTTP status -> machine codes a client may see.
 *
 * @return array[]
 */
function oas_error_rows()
{
    return array(
        array(400, 'invalid_request, unknown_action, bad_param, missing_file, bad_format'),
        array(401, 'not_authenticated, session_expired'),
        array(403, 'no_right, foreign_origin, csrf_failed'),
        array(404, '*_not_found, project_mismatch, unknown_action'),
        array(405, 'method_not_allowed, modern_endpoint_only'),
        array(409, 'duplicate_name, conflict, already_assigned'),
        array(413, 'file_too_large'),
        array(422, 'parse_error, unsupported_format'),
        array(500, 'internal_error, db_error'),
    );
}

/**
 * Build the full OpenAPI 3.1 document for the BFF layer.
 *
 * @param string[] $modules discovered api areas
 * @return array
 */
function oas_build_spec($modules)
{
    $paths = array();
    foreach (oas_featured_endpoints() as $ep) {
        $op = array(
            'tags'       => array($ep['tag']),
            'summary'    => $ep['summary'],
            'security'   => array(array('sessionCookie' => array())),
            'responses'  => array(
                '200' => array('description' => 'Success (JSON envelope, usually {"status":"ok",...}).'),
                '400' => array('$ref' => '#/components/responses/E400'),
                '401' => array('$ref' => '#/components/responses/E401'),
                '403' => array('$ref' => '#/components/responses/E403'),
                '404' => array('$ref' => '#/components/responses/E404'),
                '405' => array('$ref' => '#/components/responses/E405'),
            ),
        );
        if ($ep['method'] === 'POST') {
            $op['requestBody'] = array(
                'required' => true,
                'content'  => array(
                    'application/json' => array(
                        'schema' => array('$ref' => '#/components/schemas/JsonObject'),
                    ),
                    'multipart/form-data' => array(
                        'schema' => array('$ref' => '#/components/schemas/JsonObject'),
                    ),
                ),
            );
            // State-changing verbs must carry the shared CSRF header.
            $op['parameters'] = array(array(
                'name'        => 'X-Requested-With',
                'in'          => 'header',
                'required'    => true,
                'description' => 'Shared CSRF guard: must be XMLHttpRequest.',
                'schema'      => array('type' => 'string', 'enum' => array('XMLHttpRequest')),
            ));
        }
        $paths[$ep['path']][$ep['method']] = $op;
    }

    $errResponses = array();
    foreach (oas_error_rows() as $row) {
        list($http, $codes) = $row;
        $errResponses['E' . $http] = array(
            'description' => 'HTTP ' . $http . ' - machine codes: ' . $codes,
            'content'     => array(
                'application/json' => array(
                    'schema' => array('$ref' => '#/components/schemas/Error'),
                ),
            ),
        );
    }

    return array(
        'openapi' => '3.1.0',
        'info'    => array(
            'title'       => 'TestLink 2.0.1 REST BFF API',
            'summary'     => 'Session-authenticated browser-facing-facade API that backs every modern TestLink screen.',
            'description' => "Every modern TestLink 2.0.1 screen is a static HTML+JS page backed by a plain-PHP BFF module under /api/<area>/index.php. All modules share one contract: session-cookie authentication, a same-origin guard, an X-Requested-With CSRF header on state-changing verbs, JSON request/response envelopes with {status:'ok'|'error', code, message}, and stable machine-readable error codes. This document is generated live by /api/openapi/index.php?action=spec and lists the curated integrator endpoints plus the complete module inventory.",
            'version'     => '2.0.1',
            'license'     => array('name' => 'GPL-2.0-or-later',
                                   'url' => 'https://www.gnu.org/licenses/old-licenses/gpl-2.0.html'),
        ),
        'servers' => array(
            array('url' => '/',
                  'description' => 'This TestLink installation (relative URLs).'),
        ),
        'tags' => array(
            array('name' => 'session', 'description' => 'Session and shell endpoints.'),
            array('name' => 'projects', 'description' => 'Test project CRUD.'),
            array('name' => 'plans', 'description' => 'Test plan CRUD.'),
            array('name' => 'specification', 'description' => 'Test specification (cases, suites, steps).'),
            array('name' => 'execution', 'description' => 'Test execution and result capture.'),
            array('name' => 'reports', 'description' => 'Reports, imports and CI/CD publishing.'),
            array('name' => 'attachments', 'description' => 'Attachment upload/download.'),
            array('name' => 'meta', 'description' => 'API self-description (this document).'),
        ),
        'paths' => $paths,
        'components' => array(
            'securitySchemes' => array(
                'sessionCookie' => array(
                    'type' => 'apiKey',
                    'in'   => 'cookie',
                    'name' => 'PHPSESSID',
                    'description' => 'Session cookie issued by POST /api/auth/index.php (login admin/password flow). Anonymous callers receive HTTP 401 on every protected module.',
                ),
            ),
            'schemas' => array(
                'Error' => array(
                    'type' => 'object',
                    'required' => array('status', 'code', 'message'),
                    'properties' => array(
                        'status'  => array('type' => 'string', 'enum' => array('error')),
                        'code'    => array('type' => 'string',
                            'description' => 'Stable machine code, e.g. not_authenticated, no_right, unknown_action.'),
                        'message' => array('type' => 'string'),
                    ),
                ),
                'JsonObject' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                    'description' => 'Module-specific payload; see the module source for the exact fields.',
                ),
            ),
            'responses' => $errResponses,
        ),
        'security' => array(array('sessionCookie' => array())),
        'x-testlink-contract' => array(
            'csrfHeader' => 'X-Requested-With: XMLHttpRequest on every POST/PUT/DELETE.',
            'originGuard' => 'bffSameOriginGuard() rejects cross-origin state-changing calls (HTTP 403 foreign_origin).',
            'sessionTimeout' => 'bffEnforceSession() enforces the configured session-inactivity timeout (HTTP 401 session_expired).',
            'envelope' => 'Success: {status:"ok", ...payload}. Error: {status:"error", code, message}.',
        ),
        'x-testlink-modules' => array_map(
            function ($area) { return '/api/' . $area . '/index.php'; },
            $modules
        ),
    );
}

/**
 * Minimal deterministic JSON -> YAML emitter for the (string/number/bool/null
 * /array) document we generate. Associative arrays map to block mappings,
 * sequential arrays to block sequences; strings are quoted only when YAML
 * would otherwise misread them.
 *
 * @param mixed $data
 * @param int   $indent
 * @return string
 */
function oas_to_yaml($data, $indent = 0)
{
    $pad = str_repeat('  ', $indent);
    if (is_array($data)) {
        $seq = $data === array() ? false : array_keys($data) === range(0, count($data) - 1);
        $out = '';
        foreach ($data as $key => $value) {
            if ($seq) {
                if (is_array($value) && $value !== array()) {
                    $out .= $pad . "-\n" . oas_to_yaml($value, $indent + 1);
                } elseif (is_array($value)) {
                    $out .= $pad . "- {}\n";
                } else {
                    $out .= $pad . '- ' . oas_to_yaml($value, $indent + 1) . "\n";
                }
            } else {
                $k = preg_match('/^[A-Za-z0-9_.\/-]+$/', (string)$key) ? (string)$key : "'" . str_replace("'", "''", (string)$key) . "'";
                if (is_array($value) && $value !== array()) {
                    $out .= $pad . $k . ":\n" . oas_to_yaml($value, $indent + 1);
                } elseif (is_array($value)) {
                    $out .= $pad . $k . ": {}\n";
                } else {
                    $out .= $pad . $k . ': ' . oas_to_yaml($value, $indent + 1) . "\n";
                }
            }
        }
        return $out;
    }
    if ($data === null) {
        return 'null';
    }
    if (is_bool($data)) {
        return $data ? 'true' : 'false';
    }
    if (is_int($data) || is_float($data)) {
        return (string)$data;
    }
    $s = (string)$data;
    if ($s === '') {
        return "''";
    }
    if (preg_match('/[\n\t:#\r]|^[\s\-?:,\[\]{}&*!|>\'"%@`]|^\d|^\.\.|^ | $/', $s)
        || in_array(strtolower($s), array('true', 'false', 'null', 'yes', 'no', 'on', 'off'), true)
        || !mb_check_encoding($s, 'UTF-8')) {
        return "'" . str_replace("'", "''", $s) . "'";
    }
    return $s;
}

$modules = oas_discover_modules();

if ($action === 'init') {
    $ctx = 'tproject_id=' . intval($_SESSION['testprojectID'] ?? 0) .
           '&tplan_id=' . intval($_SESSION['testplanID'] ?? 0);

    $featured = array();
    foreach (oas_featured_endpoints() as $ep) {
        $featured[] = array('method' => $ep['method'], 'path' => $ep['path'],
                            'tag' => $ep['tag'], 'summary' => $ep['summary']);
    }

    $errors = array();
    foreach (oas_error_rows() as $row) {
        $errors[] = array('http' => $row[0], 'codes' => $row[1]);
    }

    $related = array(
        array('labelKey' => 'oas.link.apiReference',
              'href' => "/gui/templates/documentation/apiReference.html?{$ctx}", 'external' => false),
        array('labelKey' => 'oas.link.cicdPublish',
              'href' => "/gui/templates/results/cicdPublish.html?{$ctx}", 'external' => false),
        array('labelKey' => 'oas.link.automationReports',
              'href' => "/gui/templates/results/automationReports.html?{$ctx}", 'external' => false),
        array('labelKey' => 'oas.link.documentation',
              'href' => "/gui/templates/documentation/documentation.html?{$ctx}", 'external' => false),
    );

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode(array(
        'status'  => 'ok',
        'auth'    => array(
            'logged_in'  => true,
            'user_id'    => (int)$userId,
            'user_login' => (string)$user->login,
        ),
        'ctx'     => $ctx,
        'specMeta' => array(
            'openapi'      => '3.1.0',
            'title'        => 'TestLink 2.0.1 REST BFF API',
            'version'      => '2.0.1',
            'moduleCount'  => count($modules),
            'pathCount'    => count(array_unique(array_map(
                function ($ep) { return $ep['path']; }, oas_featured_endpoints()))),
            'opCount'      => count(oas_featured_endpoints()),
            'specUrl'      => '/api/openapi/index.php?action=spec',
            'specUrlYaml'  => '/api/openapi/index.php?action=spec&format=yaml',
        ),
        'featured' => $featured,
        'errors'   => $errors,
        'related'  => $related,
        'modules'  => array_map(
            function ($area) { return '/api/' . $area . '/index.php'; },
            $modules
        ),
    ));
    exit;
}

if ($action === 'spec') {
    $format = strtolower((string)($_GET['format'] ?? 'json'));
    if ($format !== 'json' && $format !== 'yaml') {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'code' => 'bad_format',
                               'message' => 'Unknown or missing format'));
        exit;
    }

    $spec = oas_build_spec($modules);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if ($format === 'yaml') {
        header('Content-Type: text/yaml; charset=utf-8');
        header('Content-Disposition: attachment; filename="testlink-bff-openapi.yaml"');
        echo "# Generated by TestLink 2.0.1 - /api/openapi/index.php?action=spec&format=yaml\n";
        echo oas_to_yaml($spec);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="testlink-bff-openapi.json"');
        echo json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
http_response_code(400);
echo json_encode(array('status' => 'error', 'code' => 'unknown_action',
                       'message' => 'Unknown or missing action'));
