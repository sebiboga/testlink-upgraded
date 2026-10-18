<?php
/**
 * REST API Reference hub - BFF
 *
 * Backs gui/templates/documentation/apiReference.html - the net-new
 * "REST API Reference" screen requested by the parent enhancement #1072
 * (documented REST API / OpenAPI / webhooks). 1.9.20 shipped no API
 * documentation at all; 2.0.1 exposes a large api/** BFF surface that was
 * previously documented only outside the product.
 *
 * Plain PHP, session-based auth, JSON I/O, same-origin guard - the same
 * contract as every other api/* BFF. Every human label is returned as an
 * i18n KEY (apiref.*) so the client renders it through TLi18n and no string
 * is hardcoded server-side; the same keys live in every client locale bundle.
 *
 * Route: GET|HEAD ?action=init (default)
 *   200 {status, auth, ctx, totalModules, groups[], featured[], errors[],
 *        publish{}, related[]}
 *   400 unknown_action | 401 not_authenticated/session_expired
 *   405 method_not_allowed | 403 foreign-origin on state-changing methods
 *   (bffSameOriginGuard skips GET/HEAD/OPTIONS)
 *
 * The endpoint CATALOGUE is discovered from the filesystem (glob over every
 * api/<area>/index.php module) so it can never drift from reality, then
 * bucketed into curated functional
 * groups; anything unmapped lands in the "other" group instead of vanishing.
 *
 * Refs #1937 (tracking), parent feature #1072.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once(__DIR__ . '/../_guard.php');

require_once('common.php');

doSessionStart();
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// Session gate BEFORE the DB connect (the #1677 lesson).
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

// Refs #1614: enforce the session-inactivity timeout once the user resolves.
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

/**
 * Discover every api/<area>/index.php module on disk. Machine paths only -
 * never echoed as HTML by the client (rendered with .text()).
 *
 * @return string[] sorted area names
 */
function apiref_discover_modules()
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

// Curated functional groups: area => group id. Discovery is authoritative for
// WHAT exists; this map only decides WHERE a module is presented.
$groupOf = array(
    // Authentication / shell
    'auth' => 'session', 'navbar' => 'session', 'aside' => 'session',
    'mainpage' => 'session', 'error' => 'session', 'info' => 'session',
    'noaccess' => 'session', 'install' => 'session',
    // System administration
    'users' => 'admin', 'usersedit' => 'admin', 'usersexport' => 'admin',
    'userinfo' => 'admin', 'roles' => 'admin', 'rolesedit' => 'admin',
    'eventviewer' => 'admin', 'eventinfo' => 'admin', 'notifications' => 'admin',
    'configcheck' => 'admin', 'severityconfig' => 'admin',
    // Projects, keywords, custom fields, registries
    'projects' => 'project', 'projectedit' => 'project', 'projectinfo' => 'project',
    'projectreqspecmgmt' => 'project', 'keywords' => 'project',
    'keywordsedit' => 'project', 'keywordsxml' => 'project', 'cfields' => 'project',
    'cfieldsedit' => 'project', 'cfieldstproject' => 'project', 'cfieldsx' => 'project',
    'plugins' => 'project', 'inventory' => 'project', 'environments' => 'project',
    'testdata' => 'project', 'risk' => 'project',
    // Test specification
    'testcases' => 'spec', 'testcasesedit' => 'spec', 'testcasescompare' => 'spec',
    'testcasesimport' => 'spec', 'testcasesexport' => 'spec',
    'testcasesprint' => 'spec', 'tcbulkop' => 'spec', 'tcmovecopy' => 'spec',
    'tcreorder' => 'spec', 'tcstepsreorder' => 'spec', 'tcsummary' => 'spec',
    'tcprintlaunch' => 'spec', 'tcassign2tplan' => 'spec', 'tcassigned' => 'spec',
    'tcassignments' => 'spec', 'tcprojecttree' => 'spec', 'suiteview' => 'spec',
    'suiteedit' => 'spec', 'suitemove' => 'spec', 'namecheck' => 'spec',
    'tcscripts' => 'spec', 'scriptedit' => 'spec', 'reviews' => 'spec',
    'tccreatefromissue' => 'spec', 'tccreatefromissues' => 'spec',
    'printoptions' => 'spec', 'ltx' => 'spec', 'attachments' => 'spec',
    'attachmentsdelete' => 'spec', 'attachmentsdownload' => 'spec',
    // Requirements
    'requirements' => 'reqs', 'reqspec' => 'reqs', 'reqedit' => 'reqs',
    'reqimport' => 'reqs', 'reqexport' => 'reqs', 'reqcompare' => 'reqs',
    'reqreorder' => 'reqs', 'reqmonitors' => 'reqs', 'reqcoveragetree' => 'reqs',
    'reqtcassign' => 'reqs', 'reqtcbassign' => 'reqs', 'reqmgrsystems' => 'reqs',
    'reqmgrsystemedit' => 'reqs', 'reqspeccopy' => 'reqs',
    'reqcreatetestcases' => 'reqs', 'reqfromissues' => 'reqs', 'reqrevision' => 'reqs',
    'reqdoc' => 'reqs', 'reqspecsearchform' => 'reqs', 'reqspectreelist' => 'reqs',
    'reqtreereorder' => 'reqs', 'logviewer' => 'reqs',
    // Plans, builds, platforms, assignment
    'plans' => 'plans', 'planedit' => 'plans', 'planexport' => 'plans',
    'planimport' => 'plans', 'builds' => 'plans', 'milestones' => 'plans',
    'milestoneedit' => 'plans', 'platforms' => 'plans', 'platformedit' => 'plans',
    'platformsexport' => 'plans', 'platformsimport' => 'plans', 'plannav' => 'plans',
    'fixplans' => 'plans', 'testclosure' => 'plans', 'execassignment' => 'plans',
    'execassignmentcopy' => 'plans', 'execdashboard' => 'plans',
    'execnavigator' => 'plans', 'testautomationspec' => 'plans',
    'tcunassignall' => 'plans',
    // Execution & results capture
    'execute' => 'execute', 'execsetresults' => 'execute',
    'executionedit' => 'execute', 'execnotes' => 'execute',
    'execnotespicker' => 'execute', 'execnotesreadonly' => 'execute',
    'executionprint' => 'execute', 'executeexport' => 'execute',
    'bugadd' => 'execute', 'bugdelete' => 'execute', 'tcautoexec' => 'execute',
    'cicdpublish' => 'execute', 'exploratory' => 'execute',
    // Reports & analytics
    'reports' => 'reports', 'reportsexport' => 'reports', 'reportsprint' => 'reports',
    'reportmail' => 'reports', 'results' => 'reports', 'resultsimport' => 'reports',
    'resultsnav' => 'reports', 'rtm' => 'reports', 'qualitygate' => 'reports',
    'defectintelligence' => 'reports', 'automationreports' => 'reports',
    'perfreports' => 'reports', 'metricstrends' => 'reports', 'metrics' => 'reports',
    'strategyreport' => 'reports', 'exitcriteria' => 'reports', 'nfr' => 'reports',
    'nfrtype' => 'reports', 'overallpiechart' => 'reports',
    'keywordbarchart' => 'reports', 'toplevelsuitesbarchart' => 'reports',
    'platformpiechart' => 'reports', 'prioritybarchart' => 'reports',
    'riskcoverage' => 'reports', 'strategy' => 'reports',
    // Integrations & in-product reference
    'trackers' => 'integrations', 'codetracker' => 'integrations',
    'issuetracker' => 'integrations', 'search' => 'integrations',
    'searchmgmt' => 'integrations', 'directlink' => 'integrations',
    'publiclink' => 'integrations', 'help' => 'integrations',
    'staticpage' => 'integrations', 'documentation' => 'integrations',
    'learntesting' => 'integrations', 'istqb' => 'integrations',
    'accessibilityresources' => 'integrations', 'accessibilityaudit' => 'integrations',
    'apireference' => 'integrations',
);

$groupMeta = array(
    'session'      => array('icon' => 'fas fa-key'),
    'admin'        => array('icon' => 'fas fa-cogs'),
    'project'      => array('icon' => 'fas fa-folder-open'),
    'spec'         => array('icon' => 'fas fa-flask'),
    'reqs'         => array('icon' => 'fas fa-file-alt'),
    'plans'        => array('icon' => 'fas fa-calendar-check'),
    'execute'      => array('icon' => 'fas fa-play-circle'),
    'reports'      => array('icon' => 'fas fa-chart-bar'),
    'integrations' => array('icon' => 'fas fa-plug'),
    'other'        => array('icon' => 'fas fa-cube'),
);

$modules = apiref_discover_modules();

$bucketed = array();
foreach ($modules as $area) {
    $gid = $groupOf[$area] ?? 'other';
    $bucketed[$gid][] = '/api/' . $area . '/';
}
$groups = array();
foreach (array_keys($groupMeta) as $gid) {
    if (empty($bucketed[$gid])) {
        continue;
    }
    $groups[] = array(
        'id'       => $gid,
        'titleKey' => 'apiref.group.' . $gid . '.title',
        'descKey'  => 'apiref.group.' . $gid . '.desc',
        'icon'     => $groupMeta[$gid]['icon'],
        'count'    => count($bucketed[$gid]),
        'areas'    => $bucketed[$gid],
    );
}

// Curated deep-dive rows: the endpoints an integrator needs first. Every row
// was verified against the shipped BFF contract (method + action).
$featured = array(
    array('id' => 'auth', 'method' => 'GET|POST',
          'path' => '/api/auth/index.php',
          'descKey' => 'apiref.feat.auth'),
    array('id' => 'aside', 'method' => 'GET',
          'path' => '/api/aside/index.php?action=init',
          'descKey' => 'apiref.feat.aside'),
    array('id' => 'projects', 'method' => 'GET|POST|PUT|DELETE',
          'path' => '/api/projects/index.php',
          'descKey' => 'apiref.feat.projects'),
    array('id' => 'plans', 'method' => 'GET|POST|PUT|DELETE',
          'path' => '/api/plans/index.php',
          'descKey' => 'apiref.feat.plans'),
    array('id' => 'tcaseEdit', 'method' => 'GET|POST',
          'path' => '/api/testcasesedit/index.php',
          'descKey' => 'apiref.feat.tcaseEdit'),
    array('id' => 'setResults', 'method' => 'GET|POST',
          'path' => '/api/execsetresults/index.php',
          'descKey' => 'apiref.feat.setResults'),
    array('id' => 'resultsImport', 'method' => 'GET|POST',
          'path' => '/api/resultsimport/index.php',
          'descKey' => 'apiref.feat.resultsImport'),
    array('id' => 'cicd', 'method' => 'POST',
          'path' => '/api/cicdpublish/index.php?action=publish',
          'descKey' => 'apiref.feat.cicd'),
    array('id' => 'attachments', 'method' => 'GET|POST',
          'path' => '/api/attachments/index.php',
          'descKey' => 'apiref.feat.attachments'),
    array('id' => 'reports', 'method' => 'GET',
          'path' => '/api/reports/index.php?action=<report_action>',
          'descKey' => 'apiref.feat.reports'),
    array('id' => 'reportExport', 'method' => 'GET|POST',
          'path' => '/api/reportsexport/index.php?act=<action>',
          'descKey' => 'apiref.feat.reportExport'),
);

// The shared error envelope: HTTP status -> machine codes a client may see.
$errors = array(
    array('http' => 400, 'descKey' => 'apiref.err.400',
          'codes' => 'invalid_request, unknown_action, bad_param, missing_file'),
    array('http' => 401, 'descKey' => 'apiref.err.401',
          'codes' => 'not_authenticated, session_expired'),
    array('http' => 403, 'descKey' => 'apiref.err.403',
          'codes' => 'no_right, foreign_origin, csrf_failed'),
    array('http' => 404, 'descKey' => 'apiref.err.404',
          'codes' => '*_not_found, project_mismatch, unknown_action'),
    array('http' => 405, 'descKey' => 'apiref.err.405',
          'codes' => 'method_not_allowed, modern_endpoint_only'),
    array('http' => 409, 'descKey' => 'apiref.err.409',
          'codes' => 'duplicate_name, conflict, already_assigned'),
    array('http' => 413, 'descKey' => 'apiref.err.413',
          'codes' => 'file_too_large'),
    array('http' => 422, 'descKey' => 'apiref.err.422',
          'codes' => 'parse_error, unsupported_format'),
    array('http' => 500, 'descKey' => 'apiref.err.500',
          'codes' => 'internal_error, db_error'),
);

$publish = array(
    'endpoint' => '/api/cicdpublish/index.php?action=publish',
    'formats'  => array('junit', 'json'),
    'descKey'  => 'apiref.publish.desc',
);

// Related modern screens inside TestLink (deep-link the current context).
$related = array(
    array('labelKey' => 'apiref.link.documentation',
          'href' => "/gui/templates/documentation/documentation.html?{$ctx}", 'external' => false),
    array('labelKey' => 'apiref.link.learnTesting',
          'href' => "/gui/templates/documentation/learnTesting.html?{$ctx}", 'external' => false),
    array('labelKey' => 'apiref.link.istqb',
          'href' => "/gui/templates/documentation/istqbSyllabus.html?{$ctx}", 'external' => false),
    array('labelKey' => 'apiref.link.accessibility',
          'href' => "/gui/templates/documentation/accessibilityResources.html?{$ctx}", 'external' => false),
    array('labelKey' => 'apiref.link.strategy',
          'href' => "/gui/templates/strategy/testStrategy.html?{$ctx}", 'external' => false),
);

echo json_encode(array(
    'status'       => 'ok',
    'auth'         => array(
        'logged_in' => true,
        'user_id'   => (int)$userId,
        'user_login'=> (string)$user->login,
    ),
    'ctx'          => $ctx,
    'totalModules' => count($modules),
    'groups'       => $groups,
    'featured'     => $featured,
    'errors'       => $errors,
    'publish'      => $publish,
    'related'      => $related,
));
