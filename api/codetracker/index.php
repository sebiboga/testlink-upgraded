<?php
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once('users.inc.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();


header('Content-Type: application/json');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

// Legacy lib/codetrackers/codeTrackerView.php:16,68-70 gates the whole page on
// `codetracker_view` OR `codetracker_management` (checkRights passed to
// testlinkInitPage -> redirect-to-login for users without either right).
// The modern BFF used to serve every route to any authenticated user; mirror
// the legacy page-level gate here so all routes (list, meta, detail, CRUD and
// the GitHub native endpoints) return 403 for users holding neither right.
$canView = $user->hasRight($db, 'codetracker_view') || $user->hasRight($db, 'codetracker_management');
if (!$canView) {
    // Match legacy lib/functions/common.php:1010-1032 (checkUserRightsFor): a
    // denied attempt is trailed into the Event Viewer BEFORE the denial is
    // served. The JSON BFF cannot redirect home like the legacy page, so the
    // event is the only trace left.
    logAuditEvent(TLS('audit_security_user_right_missing', $user->login, 'api/codetracker/index.php', 'view'),
                  'VIEW', $userId, 'codetrackers');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No permission']);
    exit;
}

// Write gate (gap vs legacy #970): legacy gates create/update/delete AND the
// connection check on `codetracker_management` — lib/codetrackers/codeTrackerEdit.php:181-184
// (checkRights() = hasRight('codetracker_management'), denying the whole edit
// controller) and codeTrackerView.tpl:51-61 (wrench "check connection" rendered
// only when canManage). The modern BFF used to let any viewer POST/PUT/DELETE/
// test_github (measured escalation in issue #970), so those routes now require
// codetracker_management and trail a denial into the Event Viewer first, exactly
// like the legacy read gate above.
//
// Issue #1576: the same $canManage also gates READ access to the raw cfg (the
// stored XML may contain <token>/<apikey> credentials in plaintext) — legacy
// codeTrackerView.tpl only rendered name/type/env-check and never the cfg.
// hasRight() returns the string 'yes' or null (lib/functions/roles.inc.php:254-273),
// so '== "yes"' yields a clean bool mirroring legacy $gui->canManage
// (lib/codetrackers/codeTrackerView.php:24).
$canManage = ($user->hasRight($db, 'codetracker_management') == 'yes');

function denyWrite($user, $userId, $action) {
    logAuditEvent(TLS('audit_security_user_right_missing', $user->login, 'api/codetracker/index.php', $action),
                  'WRITE', $userId, 'codetrackers');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'No permission']);
    exit;
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/codetracker(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getBody() {
    $body = json_decode(file_get_contents('php://input'));
    if (!is_object($body)) {
        http_response_code(400);
        out(['status' => 'error', 'code' => 'invalid_body']);
    }
    return get_object_vars($body);
}

function normalizeTrackerType($type) {
    if (is_int($type)) {
        return $type;
    }
    if (is_string($type) && preg_match('/^-?\d+$/D', $type)) {
        return intval($type);
    }
    return false;
}

function isEnabledTrackerType($mgr, $type) {
    return array_key_exists($type, $mgr->getSystems(['status' => 'enabled']));
}

function rejectInvalidTrackerType($type) {
    http_response_code(400);
    out(['status' => 'error', 'code' => 'invalid_type', 'type' => $type]);
}

function storedTrackerType($db, $id) {
    $tables = tlObject::getDBTables(['codetrackers']);
    $rows = $db->get_recordset(
        "SELECT type FROM {$tables['codetrackers']} WHERE id = " . intval($id)
    );
    return $rows[0]['type'] ?? null;
}

function attachLinks($mgr, $id, &$item, $canPurge) {
    // Port of legacy initializeGui (lib/codetrackers/codeTrackerEdit.php:144-172,
    // issue #974). The 1.9.20 edit page did TWO things with the link table that
    // the modern BFF dropped:
    //   1. purges DEAD links on load — getLinks($id,['getDeadLinks'=>true])
    //      returns rows whose testproject node no longer exists
    //      (tlCodeTracker.class.php:490-500, LEFT OUTER JOIN ... IS NULL) and
    //      legacy unlinks each of them, "just to fix erroneous test project
    //      delete" (codeTrackerEdit.php:150-157). Without the purge a NULL
    //      project name would be LEFT JOINed into the used-by list below and
    //      would also inflate link_count.
    //   2. exposes $gui->testProjectSet = getLinks($id) — the map
    //      testproject_id => testproject_name that the info-icon toggle renders
    //      as "Used on Test Project" / "Code Tracker Not Used (Linked)"
    //      (codeTrackerEdit.tpl:73-116,105-113).
    // The purge is $canPurge-gated so it fires ONLY where legacy ran it AND only
    // for the role legacy required: the edit screen was gated on
    // codetracker_management (codeTrackerEdit.php:180-184), so a view-only user
    // (right 52) must not be able to cause a DB write through a GET. The purge
    // is idempotent self-healing, but "read routes never write for viewers" is
    // the invariant the #970 write-gate established, so it is kept here too.
    // Same shape as the issue-tracker port (api/issuetracker/index.php:266-291,
    // issue #964) so all three integration screens behave identically.
    if ($canPurge) {
        $dead = $mgr->getLinks($id, array('getDeadLinks' => true));
        if ($dead) {
            foreach ($dead as $tpid => $dummy) {
                $mgr->unlink($id, intval($tpid));
            }
        }
    }
    $item['links'] = [];
    $links = $mgr->getLinks($id);
    if (is_array($links)) {
        foreach ($links as $link) {
            // A dead row (its nodes_hierarchy node is gone) LEFT JOINs to a NULL
            // name. A manager never sees one because the purge above just removed
            // it, but a view-only caller must not be shown a phantom project
            // either — and link_count must match what the grid shows for the same
            // tracker, or the delete gating (#971) and this list would disagree.
            $name = isset($link['testproject_name']) ? $link['testproject_name'] : null;
            if ($name === null || $name === '') {
                continue;
            }
            $item['links'][] = $name;
        }
    }
    $item['link_count'] = count($item['links']);
    return $item;
}

function trackerToJSON($item, $mgr, $canManage) {
    $typeDescr = '';
    if (isset($mgr->types[$item['type']])) {
        $typeDescr = $mgr->types[$item['type']];
    }
    $typeLabel = '';
    if (isset($mgr->systems[$item['type']])) {
        $spec = $mgr->systems[$item['type']];
        $typeLabel = $spec['type'];
    }
    $serverUrl = '';
    if (!empty($item['cfg'])) {
        $m = [];
        if (preg_match('/<uribase>(.*?)<\/uribase>/', $item['cfg'], $m)) {
            $serverUrl = $m[1];
        }
    }

    // Extract GitHub-native config fields (issue #433).
    $github = ['repository' => '', 'branch' => '', 'token' => ''];
    if ($typeLabel === 'github' && !empty($item['cfg'])) {
        foreach (['repository' => 'repository', 'branch' => 'branch'] as $key => $tag) {
            if (preg_match('/<' . $tag . '>(.*?)<\/' . $tag . '>/s', $item['cfg'], $m)) {
                $github[$key] = $m[1];
            }
        }
        if (preg_match('/<token>(.*?)<\/token>/s', $item['cfg'], $m) && $m[1] !== '') {
            $github['token'] = '********';
        }
    }

    // Issue #1576: raw cfg may contain plaintext <token>/<apikey> credentials.
    // Only management users get it (their edit modal prefills it); view-only
    // users get '' — matching legacy codeTrackerView.tpl which never surfaced
    // the raw XML on the list. serverUrl and the parsed github fields above
    // still work without it.
    $safeCfg = $canManage ? ($item['cfg'] ?? '') : '';

    return [
        'id' => intval($item['id']),
        'name' => $item['name'],
        'type' => intval($item['type']),
        'typeLabel' => $typeLabel,
        'typeDescr' => $typeDescr,
        // Issue #1597: false when the row's type is not a key of the manager's
        // systems map (import/migration, hand-edited DB, or an implementation
        // dropped in a later release). Such a row is now LISTED instead of
        // fataling the whole grid (tlCodeTracker::getImplementationForType()
        // returns null and getAll() degrades its env check to "not OK"), so the
        // grid has to be able to say WHY the Type/Environment cells are empty
        // instead of rendering two blank cells. Deliberately the SAME predicate
        // as the library guard (isset on $mgr->systems), not a derivation from
        // $typeLabel, so the two can never drift apart.
        'typeKnown' => isset($mgr->systems[$item['type']]),
        'cfg' => $safeCfg,
        'serverUrl' => $serverUrl,
        'github' => $github,
        'implementation' => $item['implementation'] ?? '',
        // Environment check (issue #973). Legacy lib/codetrackers/codeTrackerView.php:23
        // requested getAll(..., 'checkEnv' => true) so tlCodeTracker.class.php:578-601
        // runs the per-implementation $impl::checkEnv() and fills
        // env_check_ok / env_check_msg, rendered by the "Environment" column of
        // codeTrackerView.tpl:42,74 ($labels.th_codetracker_env =
        // 'Environment', locale/*/strings.txt). The modern BFF omitted the
        // option, so the probe never ran and the readiness of the PHP
        // environment (e.g. githubrestCodeTrackerInterface::checkEnv() at
        // lib/codetrackerintegration/githubrestCodeTrackerInterface.class.php:523
        // requiring cURL) was never surfaced. Same shape as
        // api/issuetracker/index.php:100-101.
        // Defaults mirror tlCodeTracker.class.php:574-575 (true / '') so routes
        // that do not request checkEnv (GET-by-id, POST, PUT, DELETE) stay
        // well-defined instead of dropping the key.
        'env_check_ok' => (bool)($item['env_check_ok'] ?? true),
        'env_check_msg' => (string)($item['env_check_msg'] ?? ''),
        'link_count' => intval($item['link_count'] ?? 0),
        // Linked test-project names, consumed by the edit modal's used-by
        // toggle (issue #974 — legacy codeTrackerEdit.php:158-159 set
        // $gui->testProjectSet the same way). attachLinks() fills it for every
        // per-tracker route (GET /{id} with the legacy dead-link purge, and the
        // create/update/delete responses); the LIST route deliberately keeps
        // only link_count (getAll 'add_link_count', tlCodeTracker.class.php:
        // 604-610) because the grid needs no names and resolving them per row
        // would be an N+1 query. Values are returned raw (JSON API): the screen
        // escapes them on render, exactly like the other columns (issue #1581).
        'links' => array_values((array)($item['links'] ?? [])),
    ];
}

$mgr = new tlCodeTracker($db);

if ($method === 'GET' && ($path === '/' || $path === '' || $path === '/index.php')) {
    // checkEnv parity with legacy codeTrackerView.php:23 — the per-tracker
    // environment probe ($impl::checkEnv(), tlCodeTracker.class.php:578-601)
    // runs here so env_check_ok/env_check_msg reach the grid's Environment
    // column. Omitting it silently downgrades every row to "OK" (issue #973).
    $all = $mgr->getAll(['output' => 'add_link_count', 'checkEnv' => true]);
    $items = [];
    if ($all) {
        foreach ($all as $item) {
            $items[] = trackerToJSON($item, $mgr, $canManage);
        }
    }
    // canManage mirrors legacy $gui->canManage
    // (lib/codetrackers/codeTrackerView.php:24) so the UI can gate the Create
    // button and the edit/delete action icons on codetracker_management.
    out(['status' => 'ok', 'items' => $items, 'total' => count($items), 'canManage' => $canManage]);
}

if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'types') {
    $systems = $mgr->getSystems(['status' => 'all']);
    $items = [];
    foreach ($systems as $code => $spec) {
        $items[] = [
            'code' => intval($code),
            'type' => $spec['type'],
            'api' => $spec['api'],
            'enabled' => $spec['enabled'],
            'label' => $spec['type'] . ' (Interface: ' . $spec['api'] . ')',
        ];
    }
    out(['status' => 'ok', 'items' => $items]);
}

// Legacy lib/ajax/getcodetrackercfgtemplate.php: the eye icon next to the
// Configuration field in codeTrackerEdit.tpl:194-196 calls displayCfgExample()
// (codeTrackerEdit.tpl:26-66), which GETs getcodetrackercfgtemplate.php?type=N
// and injects the selected interface's $cname::getCfgTemplate() as <pre><xmp>
// into #cfg_example — the PER-TYPE config example, plus the localized
// codetracker_interface_not_implemented / codetracker_invalid_type fallbacks.
// Modern BFF mirror: GET /cfg-template?type=N returns the raw template for an
// ENABLED type (getTypes() parity — disabled types are "invalid" like legacy),
// or a structured error code the client localizes via TLi18n (the JSON BFF has
// no lang_get). i18n keys: ct.msg.invalidType / ct.msg.interfaceMissing.
// Gap closed: issue #975 — until now the modal showed ONE hardcoded example
// (codetrackerView.html) that matched neither enabled interface.
if ($method === 'GET' && ($segments[0] ?? '') === 'cfg-template' && empty($segments[1])) {
    $type = intval($_GET['type'] ?? 0);
    // getTypes() = ENABLED types only (tlCodeTracker.class.php:150-160);
    // isset() on it reproduces the legacy "unknown type" branch for disabled
    // and out-of-map ids alike.
    $ctt = $mgr->getTypes();
    if (isset($ctt[$type])) {
        $iname = $mgr->getImplementationForType($type);
        // Legacy probes stream_resolve_include_path() BEFORE any include, so a
        // missing interface never makes the autoloader emit the E_WARNING that
        // class_exists() would log into the events table (measured during the
        // issue #965 interface_missing test on the sibling issuetracker BFF:
        // 2 E_WARNING rows were written). require_once() then loads the file
        // through the same include_path stream_resolve just validated.
        if (stream_resolve_include_path($iname . '.class.php') !== false) {
            if (!class_exists($iname, false)) {
                require_once($iname . '.class.php');
            }
            out(['status' => 'ok', 'type' => $type, 'template' => $iname::getCfgTemplate()]);
        }
        out(['status' => 'error', 'code' => 'interface_missing', 'iface' => $iname]);
    }
    out(['status' => 'error', 'code' => 'invalid_type', 'type' => $type]);
}

if ($method === 'GET' && isset($segments[0]) && is_numeric($segments[0]) && count($segments) === 1) {
    $id = intval($segments[0]);
    $item = $mgr->getByID($id);
    if (!$item) { http_response_code(404); out(['status' => 'error', 'message' => 'Code tracker not found']); }
    // The request the edit modal makes, so this is where legacy initializeGui()
    // ran: purge dead links, then return the linked test-project names that the
    // used-by toggle renders (issue #974 — see attachLinks()). The purge is a
    // write, so it is limited to codetracker_management exactly like the legacy
    // edit page; a view-only caller still gets the (read-only) links list.
    attachLinks($mgr, $id, $item, $canManage);
    out(['status' => 'ok', 'item' => trackerToJSON($item, $mgr, $canManage)]);
}

if ($method === 'POST' && empty($segments)) {
    if (!$canManage) { denyWrite($user, $userId, 'create'); }
    $body = getBody();
    $name = trim($body['name'] ?? '');
    $rawType = $body['type'] ?? null;
    $type = normalizeTrackerType($rawType);
    $cfg = $body['cfg'] ?? '';

    if (empty($name)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Name is required']);
    }

    if ($type === false || !isEnabledTrackerType($mgr, $type)) {
        rejectInvalidTrackerType($type === false ? 0 : $type);
    }

    $ct = new stdClass();
    $ct->name = $name;
    $ct->type = $type;
    $ct->cfg = $cfg;

    $result = $mgr->create($ct);
    if ($result['status_ok']) {
        $item = $mgr->getByID($result['id']);
        // Report the (empty) link set of a brand-new tracker instead of the
        // 0/[] defaults, so a create response is as truthful as an edit one
        // (issue #974). No purge: legacy only purged on the edit page load.
        attachLinks($mgr, intval($result['id']), $item, false);
        out(['status' => 'ok', 'item' => trackerToJSON($item, $mgr, $canManage)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => $result['msg']]);
    }
}

if ($method === 'PUT' && isset($segments[0]) && is_numeric($segments[0]) && count($segments) === 1) {
    if (!$canManage) { denyWrite($user, $userId, 'update'); }
    $id = intval($segments[0]);
    $body = getBody();
    $hasType = array_key_exists('type', $body);
    $rawType = $hasType ? $body['type'] : null;
    $type = $hasType ? normalizeTrackerType($rawType) : null;
    if ($hasType && ($type === false || !isEnabledTrackerType($mgr, $type))) {
        rejectInvalidTrackerType($type === false ? 0 : $type);
    }

    $existing = $mgr->getByID($id);
    if (!$existing) { http_response_code(404); out(['status' => 'error', 'message' => 'Code tracker not found']); }

    $ct = new stdClass();
    $ct->id = $id;
    $ct->name = isset($body['name']) ? trim($body['name']) : $existing['name'];
    $ct->type = $hasType ? $type : intval($existing['type']);
    $ct->cfg = isset($body['cfg']) ? $body['cfg'] : ($existing['cfg'] ?? '');

    $result = $mgr->update($ct);
    if ($result['status_ok']) {
        $item = $mgr->getByID($id);
        // An edit can rename the tracker but never touches its links; report
        // the real ones so the response cannot contradict the grid (issue #974).
        attachLinks($mgr, $id, $item, false);
        out(['status' => 'ok', 'item' => trackerToJSON($item, $mgr, $canManage)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => $result['msg']]);
    }
}

// --- GitHub native interface endpoints (issue #433) ------------------------

// Instantiate a code-tracker interface from a stored tracker record.
function githubInterfaceFor($mgr, $id) {
    $tracker = $mgr->getByID($id);
    if (!$tracker) { return [null, 'Code tracker not found']; }
    $type = normalizeTrackerType($tracker['type'] ?? null);
    if ($type === false || !array_key_exists($type, $mgr->systems)) {
        return [null, 'Unknown code tracker type'];
    }
    $impl = $tracker['implementation'] ?? '';
    if (!$impl || !class_exists($impl)) { return [null, 'Code tracker implementation not found']; }
    try {
        $iface = new $impl($type, $tracker['cfg'], $tracker['name']);
        return [$iface, null];
    } catch (Throwable $e) {
        tLog(__METHOD__ . ' ' . $e->getMessage(), 'ERROR');
        return [null, $e->getMessage()];
    }
}

// Test a GitHub connection using inline (unsaved) config, so the create modal
// can verify before saving. Body: { repository, token, branch }.
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'test_github') {
    if (!$canManage) { denyWrite($user, $userId, 'test_github'); }
    $body = getBody();
    $repository = trim($body['repository'] ?? '');
    $token = trim($body['token'] ?? '');
    $branch = trim($body['branch'] ?? '');

    if ($repository === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Repository is required']);
    }

    $cfgObj = new stdClass();
    $cfgObj->repository = $repository;
    $cfgObj->token = $token;
    $cfgObj->branch = $branch;
    $cfgJson = json_encode($cfgObj);

    if (!class_exists('githubrestCodeTrackerInterface')) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'GitHub interface implementation not available']);
    }

    try {
        $iface = new githubrestCodeTrackerInterface('github', $cfgJson, 'test');
        $connected = $iface->isConnected();
        $branches = $connected ? $iface->getBranches() : false;
        $branchList = is_array($branches) ? array_values($branches) : [];
        out([
            'status' => $connected ? 'ok' : 'error',
            'connected' => $connected,
            'message' => $connected ? 'Connection OK' : 'Connection failed (check repository and token)',
            'branchCount' => count($branchList),
            'branches' => $branchList,
            'defaultBranch' => ($connected && count($branchList) > 0) ? $branchList[0] : '',
        ]);
    } catch (Exception $e) {
        tLog(__METHOD__ . ' ' . $e->getMessage(), 'ERROR');
        http_response_code(502);
        out(['status' => 'error', 'message' => 'Connection test failed']);
    }
}

if (($method === 'GET' || $method === 'POST') && isset($segments[0]) && is_numeric($segments[0]) &&
    isset($segments[1])) {
    $id = intval($segments[0]);
    $action = strtolower($segments[1]);

    if ($action === 'test_connection' && $method !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        out(['status' => 'error', 'message' => 'Method not allowed']);
    }

    // Issue #1578: every /{id}/{branches|tags|commits|pulls|test_connection}
    // action instantiates the tracker's interface from the STORED cfg (which
    // may hold the plaintext token) and drives it server-side with the
    // manager's credentials. Legacy grants that only to managers: the wrench
    // connection check (codeTrackerView.tpl:51-61) is rendered only when
    // canManage, so a view-only user must never exercise the stored token.
    // Gate identical to the #970 write-gate (denyWrite) so the denial is also
    // trailed into the Event Viewer.
    if (!$canManage) { denyWrite($user, $userId, $action); }

    $knownActions = ['branches', 'tags', 'commits', 'pulls', 'test_connection'];
    if (!in_array($action, $knownActions, true)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Unknown action']);
    }

    $storedType = storedTrackerType($db, $id);
    if ($storedType === null) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Code tracker not found']);
    }
    $normalizedType = normalizeTrackerType($storedType);
    if ($normalizedType === false || !array_key_exists($normalizedType, $mgr->systems)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Unknown code tracker type']);
    }

    $tracker = $mgr->getByID($id);
    if (!$tracker) { http_response_code(404); out(['status' => 'error', 'message' => 'Code tracker not found']); }

    list($iface, $err) = githubInterfaceFor($mgr, $id);
    if (!$iface) { http_response_code(400); out(['status' => 'error', 'message' => $err]); }

    switch ($action) {
        case 'branches':
            $branches = $iface->getBranches();
            if ($branches === false) { http_response_code(502); out(['status' => 'error', 'message' => 'Unable to fetch branches (check repository and token)']); }
            out(['status' => 'ok', 'items' => array_values($branches)]);
            break;
        case 'tags':
            $tags = $iface->getTags();
            if ($tags === false) { http_response_code(502); out(['status' => 'error', 'message' => 'Unable to fetch tags (check repository and token)']); }
            out(['status' => 'ok', 'items' => array_values($tags)]);
            break;
        case 'commits':
            $branch = $_GET['branch'] ?? null;
            $commits = $iface->getCommits($branch);
            if ($commits === false) { http_response_code(502); out(['status' => 'error', 'message' => 'Unable to fetch commits (check repository and token)']); }
            out(['status' => 'ok', 'items' => $commits]);
            break;
        case 'pulls':
            $state = $_GET['state'] ?? 'open';
            $pulls = $iface->getPullRequests($state);
            if ($pulls === false) { http_response_code(502); out(['status' => 'error', 'message' => 'Unable to fetch pull requests (check repository and token)']); }
            out(['status' => 'ok', 'items' => $pulls]);
            break;
        case 'test_connection':
            try {
                $connected = (bool)$iface->isConnected();
                out(['status' => $connected ? 'ok' : 'error',
                     'connected' => $connected,
                     'message' => $connected ? 'Connection OK' : 'Connection failed (check repository, branch and token)']);
            } catch (Throwable $e) {
                tLog(__METHOD__ . ' ' . $e->getMessage(), 'ERROR');
                http_response_code(502);
                out(['status' => 'error', 'connected' => false, 'message' => 'Connection test failed']);
            }
            break;
    }
}

if ($method === 'DELETE' && isset($segments[0]) && is_numeric($segments[0])) {
    if (!$canManage) { denyWrite($user, $userId, 'delete'); }
    $id = intval($segments[0]);
    $existing = $mgr->getByID($id);
    if (!$existing) { http_response_code(404); out(['status' => 'error', 'message' => 'Code tracker not found']); }

    $result = $mgr->delete($id);
    if ($result['status_ok']) {
        // The legacy delete form refused to delete a LINKED tracker and showed
        // the very same used-by list (codeTrackerView.tpl:66-72 / #971 gating
        // mirrored in the grid). The response carries the pre-delete state so
        // a client can still explain the refusal (issue #974).
        attachLinks($mgr, $id, $existing, false);
        out(['status' => 'ok', 'item' => trackerToJSON($existing, $mgr, $canManage)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => $result['msg']]);
    }
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
