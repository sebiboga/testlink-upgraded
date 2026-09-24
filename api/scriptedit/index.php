<?php
/**
 * TestLink 2.0.1 — Execution Script create/edit + delete popup BFF
 *
 * Modernizes the two last standalone `lib/testcases/*` legacy action popups
 * that still render Smarty and are still pointed at by the shared client JS:
 *   - lib/testcases/scriptAdd.php    (user_action=link|create VCS script popup)
 *   - lib/testcases/scriptDelete.php (confirm-delete script popup)
 *
 * The standalone Dashio popup `gui/templates/testcases/scriptEdit.html` drives
 * this BFF. Persistence + audit mirror the legacy parity code shared with the
 * modern `api/tcscripts` screen (testcase_script_links composite-key writes).
 *
 * Routes (session auth + bffSameOriginGuard, JSON I/O):
 *   GET  ?action=init &tcversion_id=N [&tproject_id=M][&tplan_id=P][&user_action=X]
 *        context (tcversion, project/plan), linked code tracker cfg, the VCS
 *        select metadata (projects/repos/branches/commits/files with session
 *        defaults — scriptAdd.php initEnv/getCodeTracker parity), plus the
 *        existing linked scripts for the tcversion.
 *   GET  ?action=meta &project_key=..[&repository_name=..][&branch_name=..]
 *        refresh repos/branches/commits metadata (legacy projectSelected /
 *        repoSelected / branchSelected parity)
 *   GET  ?action=files &path=/dir [&branch=..]&project_key=..&repository_name=..
 *        directory listing for the file-browser tree (expand/collapse parity)
 *   POST ?action=save  body: {tproject_id, tcversion_id, project_key,
 *                             repository_name, code_path, branch_name, commit_id}
 *        validate path on the CTS, de-dupe, INSERT testcase_script_links,
 *        sync the "Test Script" custom field, session memory + audit
 *   POST ?action=delete body: {tproject_id, tcversion_id, script_id}
 *        script_id = "project&&repository&&code_path" (scriptDelete.php parity)
 *
 * Rights: legacy checkRights() = mgt_modify_tc on the owning project (403).
 * Contract: 401 anonymous / 403 no rights + CSRF / 404 unknown tcversion /
 * 400 bad params / 405 method not allowed / 409 no code tracker / 500 guarded.
 *
 * @since 2.0.1
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
require_once(__DIR__ . '/../../lib/functions/testcase.class.php');

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

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';
$BODY = json_decode(file_get_contents('php://input'), true) ?? [];

function out($data) { echo json_encode($data); exit; }
function badRequest($msg) {
    http_response_code(400);
    out(['status' => 'error', 'message' => $msg]);
}

$ctmgr = new tlCodeTracker($db);

function resolveProjectId()
{
    if (isset($_GET['tproject_id']) && intval($_GET['tproject_id']) > 0) {
        return intval($_GET['tproject_id']);
    }
    if (isset($_POST['tproject_id']) && intval($_POST['tproject_id']) > 0) {
        return intval($_POST['tproject_id']);
    }
    if (isset($GLOBALS['BODY']['tproject_id']) && intval($GLOBALS['BODY']['tproject_id']) > 0) {
        return intval($GLOBALS['BODY']['tproject_id']);
    }
    if (isset($_SESSION['testprojectID']) && intval($_SESSION['testprojectID']) > 0) {
        return intval($_SESSION['testprojectID']);
    }
    return null;
}

/**
 * Linked code tracker for a test project (legacy getCodeTracker parity).
 */
function linkedTracker($db, $tproject_id)
{
    $tprojectMgr = new testproject($db);
    $info = $tprojectMgr->get_by_id($tproject_id);
    if (is_null($info)) {
        return [null, null, 'Test project not found'];
    }
    if (empty($info['code_tracker_enabled'])) {
        return [null, null, 'Test project has no code tracker enabled'];
    }
    $ct_mgr = new tlCodeTracker($db);
    $tracker = $ct_mgr->getLinkedTo($tproject_id);
    if (is_null($tracker)) {
        return [null, null, 'No code tracker linked to this test project'];
    }
    $row = $GLOBALS['ctmgr']->getByID($tracker['codetracker_id']);
    if (is_null($row)) {
        return [$tracker, null, 'Code tracker not found'];
    }
    $cts = null;
    $impl = $row['implementation'] ?? '';
    if ($impl !== '' && class_exists($impl)) {
        try {
            $cts = new $impl($row['type'], $row['cfg'], $row['name']);
        } catch (Exception $e) {
            tLog(__METHOD__ . ' ' . $e->getMessage(), 'ERROR');
        }
    }
    return [$tracker, $cts, null];
}

/**
 * Parse a code tracker cfg (XML or JSON) into a flat array.
 */
function parseTrackerCfg($rowCfg)
{
    $cfg = ['type' => '', 'repository' => '', 'owner' => '', 'repo' => '',
            'branch' => '', 'token' => '', 'apibase' => 'https://api.github.com/',
            'viewbase' => 'https://github.com', 'name' => ''];
    if (empty($rowCfg)) {
        return $cfg;
    }
    $cfgStr = trim($rowCfg);
    if ($cfgStr[0] === '{') {
        $j = json_decode($cfgStr);
        if (is_object($j)) {
            $cfg['repository'] = isset($j->repository) ? trim((string)$j->repository) : (isset($j->url) ? trim((string)$j->url) : '');
            $cfg['branch'] = isset($j->branch) ? trim((string)$j->branch) : '';
            $cfg['token'] = isset($j->token) ? trim((string)$j->token) : '';
            $cfg['apibase'] = isset($j->apibase) && trim((string)$j->apibase) !== '' ? trim((string)$j->apibase) : $cfg['apibase'];
            if (!isset($j->repository) && isset($j->owner)) {
                $cfg['owner'] = trim((string)$j->owner);
                $cfg['repo'] = isset($j->repo) ? trim((string)$j->repo) : '';
            }
        }
    } else {
        $m = [];
        if (preg_match('/<repository>(.*?)<\/repository>/is', $cfgStr, $m)) { $cfg['repository'] = trim($m[1]); }
        if (preg_match('/<owner>(.*?)<\/owner>/is', $cfgStr, $m)) { $cfg['owner'] = trim($m[1]); }
        if (preg_match('/<repo>(.*?)<\/repo>/is', $cfgStr, $m)) { $cfg['repo'] = trim($m[1]); }
        if (preg_match('/<branch>(.*?)<\/branch>/is', $cfgStr, $m)) { $cfg['branch'] = trim($m[1]); }
        if (preg_match('/<token>(.*?)<\/token>/is', $cfgStr, $m)) { $cfg['token'] = trim($m[1]); }
        if (preg_match('/<uribase>(.*?)<\/uribase>/is', $cfgStr, $m) && trim($m[1]) !== '') {
            $cfg['viewbase'] = rtrim(trim($m[1]), '/');
        }
        if (preg_match('/<apibase>(.*?)<\/apibase>/is', $cfgStr, $m) && trim($m[1]) !== '') {
            $cfg['apibase'] = rtrim(trim($m[1]), '/') . '/';
        }
    }
    if ($cfg['repository'] === '' && ($cfg['owner'] === '' || $cfg['repo'] === '')) {
        return $cfg;
    }
    if ($cfg['owner'] !== '' && $cfg['repo'] !== '') {
        return $cfg;
    }
    $repo = $cfg['repository'];
    $repo = preg_replace('#^https?://[^/]+/#', '', $repo);
    $repo = trim($repo, '/');
    $parts = explode('/', $repo);
    $cfg['owner'] = isset($parts[0]) ? trim($parts[0]) : '';
    $cfg['repo'] = isset($parts[1]) ? trim($parts[1]) : '';
    return $cfg;
}

/**
 * Authenticated GET against the GitHub API (same contract as api/tcscripts).
 */
function ghGet($url, $token)
{
    $headers = array(
        'Accept: application/vnd.github+json',
        'User-Agent: TestLink-CodeTracker',
        'X-GitHub-Api-Version: 2022-11-28',
    );
    if (!empty($token)) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ));
    $proxy = config_get('proxy');
    if (is_object($proxy) && isset($proxy->host) && $proxy->host) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy->host);
        if (isset($proxy->port) && $proxy->port) {
            curl_setopt($ch, CURLOPT_PROXYPORT, $proxy->port);
        }
    }
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($result === false || $httpCode >= 400) {
        return null;
    }
    return json_decode($result);
}

/**
 * GitHub contents listing mirror of getRepoContentForHTMLSelect(): returns
 * [name, type(file|dir), path] rows for a directory.
 */
function ghContents($cfg, $path, $branch)
{
    if ($cfg['owner'] === '' || $cfg['repo'] === '') {
        return null;
    }
    $api = rtrim($cfg['apibase'], '/');
    $ref = $branch !== '' ? $branch : $cfg['branch'];
    $url = $api . '/repos/' . rawurlencode($cfg['owner']) . '/' . rawurlencode($cfg['repo'])
         . '/contents/' . ltrim((string)$path, '/');
    $url .= ($ref !== '') ? ('?ref=' . rawurlencode($ref)) : '';
    $data = ghGet($url, $cfg['token']);
    if (!is_array($data)) {
        return null;
    }
    $items = [];
    foreach ($data as $item) {
        if (!is_object($item) || !isset($item->name)) {
            continue;
        }
        $items[] = [
            'name' => $item->name,
            'path' => isset($item->path) ? $item->path : ltrim($path, '/') . '/' . $item->name,
            'type' => ($item->type ?? 'file') === 'dir' ? 'dir' : 'file',
        ];
    }
    return $items;
}

/**
 * True when the given path exists on the GitHub CTS.
 */
function ghPathExists($cfg, $path, $branch)
{
    if ($cfg['owner'] === '' || $cfg['repo'] === '') {
        return false;
    }
    $api = rtrim($cfg['apibase'], '/');
    $ref = $branch !== '' ? $branch : $cfg['branch'];
    $url = $api . '/repos/' . rawurlencode($cfg['owner']) . '/' . rawurlencode($cfg['repo'])
         . '/contents/' . ltrim((string)$path, '/');
    $url .= ($ref !== '') ? ('?ref=' . rawurlencode($ref)) : '';
    $data = ghGet($url, $cfg['token']);
    if (is_array($data)) {
        return count($data) > 0;
    }
    return is_object($data) && isset($data->name);
}

/**
 * Resolve the tcversion's owning test project through the tree.
 */
function tprojectForTcversion($db, $tcversion_id)
{
    $sql = " SELECT NH.parent_id AS tc_id, NH2.parent_id AS tsuite_id, NH3.parent_id AS tproject_id " .
           " FROM nodes_hierarchy NH " .
           " JOIN nodes_hierarchy NH2 ON NH2.id = NH.parent_id " .
           " JOIN nodes_hierarchy NH3 ON NH3.id = NH2.parent_id " .
           " WHERE NH.id = " . intval($tcversion_id) . " AND NH.node_type_id = 3";
    $rs = $db->get_recordset($sql);
    if (is_null($rs) || !isset($rs[0]['tproject_id'])) {
        return null;
    }
    return intval($rs[0]['tproject_id']);
}

/**
 * Sorted items list from a code-tracker map or GitHub contents data.
 */
function treeToItems($tree)
{
    $items = [];
    if (is_null($tree) || !is_array($tree)) {
        return $items;
    }
    foreach ($tree as $name => $val) {
        if (is_array($val) && array_key_exists(0, $val)) {
            $items[] = ['name' => $name, 'path' => $val[1] === '' ? $name : trim($val[1], '/') . '/' . $name, 'type' => 'dir'];
        } else {
            $items[] = ['name' => $name, 'path' => $val[1] === '' ? $name : trim($val[1], '/') . '/' . $name, 'type' => 'file'];
        }
    }
    usort($items, function ($a, $b) {
        if ($a['type'] !== $b['type']) { return $a['type'] === 'dir' ? -1 : 1; }
        return strcasecmp($a['name'], $b['name']);
    });
    return $items;
}

/**
 * Build metadata maps for the popup selects (scriptAdd.php parity):
 * projects, repos, branches, commits and the session defaults.
 */
function buildMetadata($db, $tproject_id, $cts, $cfg, $select)
{
    $meta = [
        'projects' => [], 'project_key' => '',
        'repos' => [], 'repository_name' => '',
        'branches' => [], 'branch_name' => '',
        'commits' => [], 'commit_id' => '',
    ];

    // projects
    if (!is_null($cts) && method_exists($cts, 'getProjectsForHTMLSelect')) {
        try {
            $meta['projects'] = $cts->getProjectsForHTMLSelect();
            if (!is_array($meta['projects'])) { $meta['projects'] = []; }
        } catch (Throwable $e) {
            $meta['projects'] = [];
        }
    } elseif ($cfg['owner'] !== '' && $cfg['repo'] !== '') {
        $meta['projects'] = [$cfg['owner'] => $cfg['owner']];
    }

    // session default project key (legacy parity)
    $projKey = isset($_SESSION['testscript_projectKey']) ? $_SESSION['testscript_projectKey'] : '';
    if ($projKey === '') {
        $projKey = $select['project_key'] ?? '';
    }
    if ($projKey === '' && $cfg['owner'] !== '' ) {
        $projKey = $cfg['owner'];
    }
    $meta['project_key'] = $projKey;

    // repos
    if ($projKey !== '') {
        if (!is_null($cts) && method_exists($cts, 'getReposForHTMLSelect')) {
            try {
                $repos = $cts->getReposForHTMLSelect($projKey);
                $meta['repos'] = is_array($repos) ? $repos : [];
            } catch (Throwable $e) {
                $meta['repos'] = [];
            }
        } elseif ($cfg['repo'] !== '') {
            $meta['repos'] = [$cfg['repo'] => $cfg['repo']];
        }
    }
    $repoName = $meta['repository_name'];
    $repoName = isset($_SESSION['testscript_repositoryName']) ? $_SESSION['testscript_repositoryName'] : '';
    if ($repoName === '') {
        $repoName = $select['repository_name'] ?? '';
    }
    if ($repoName === '' && $repoName !== '0' && count($meta['repos']) === 1) {
        $keys = array_keys($meta['repos']);
        $repoName = $keys[0];
    }
    if ($repoName === '' && $cfg['repo'] !== '' && count($meta['repos']) === 0) {
        $repoName = $cfg['repo'];
    }
    $meta['repository_name'] = $repoName;

    // branches
    $branchName = $select['branch_name'] ?? '';
    if ($projKey !== '' && $repoName !== '') {
        if (!is_null($cts) && method_exists($cts, 'getBranchesForHTMLSelect')) {
            try {
                $branches = $cts->getBranchesForHTMLSelect($projKey, $repoName);
                $meta['branches'] = is_array($branches) ? $branches : [];
            } catch (Throwable $e) {
                $meta['branches'] = [];
            }
        } elseif ($cts instanceof githubrestCodeTrackerInterface) {
            try {
                $bs = $cts->getBranches();
                $meta['branches'] = [];
                if (is_array($bs)) {
                    foreach ($bs as $b) { $meta['branches'][$b] = $b; }
                }
            } catch (Throwable $e) {
                $meta['branches'] = [];
            }
        } else {
            $meta['branches'] = [];
        }
    }
    if ($branchName === '' && count($meta['branches']) === 1) {
        $keys = array_keys($meta['branches']);
        $branchName = $keys[0];
    }
    if ($branchName === '' && $meta['branches'] === [] && $cfg['branch'] !== '') {
        $branchName = $cfg['branch'];
    }
    $meta['branch_name'] = $branchName;

    // commits (only once a branch is known — legacy parity)
    if ($projKey !== '' && $repoName !== '' && $branchName !== '') {
        if (!is_null($cts) && method_exists($cts, 'getCommitsForHTMLSelect')) {
            try {
                $commits = $cts->getCommitsForHTMLSelect($projKey, $repoName, $branchName);
                $meta['commits'] = is_array($commits) ? $commits : [];
            } catch (Throwable $e) {
                $meta['commits'] = [];
            }
        }
    }

    return $meta;
}

// ---------------------------------------------------------------------------

if ($method === 'GET' && $action === 'init') {
    $tcversion_id = intval($_GET['tcversion_id'] ?? 0);
    if ($tcversion_id <= 0) { badRequest('tcversion_id is required'); }
    $tproject_id = resolveProjectId();
    if (is_null($tproject_id)) { $tproject_id = tprojectForTcversion($db, $tcversion_id); }
    if (is_null($tproject_id)) { badRequest('Unable to resolve test project'); }
    $tplan_id = intval($_GET['tplan_id'] ?? 0);
    $user_action = isset($_GET['user_action']) && trim($_GET['user_action']) !== '' ? trim($_GET['user_action']) : 'link';

    // tcversion context
    $sql = " SELECT TV.id, TV.version, TV.tc_external_id, NH.name AS tc_name " .
           " FROM tcversions TV JOIN nodes_hierarchy NH ON NH.id = TV.id " .
           " WHERE TV.id = " . intval($tcversion_id) . " AND NH.node_type_id = 3";
    $rs = $db->get_recordset($sql);
    if (is_null($rs) || !isset($rs[0]['id'])) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Test case version not found']);
    }
    $tv = $rs[0];

    // project + plan names
    $tprojectName = '';
    $sql = " SELECT NH.name FROM nodes_hierarchy NH WHERE NH.id = " . intval($tproject_id) . " AND NH.node_type_id = 1";
    $rs = $db->get_recordset($sql);
    if (!is_null($rs) && isset($rs[0]['name'])) { $tprojectName = $rs[0]['name']; }

    $tplanName = '';
    if ($tplan_id > 0) {
        $sql = " SELECT NH.name FROM nodes_hierarchy NH WHERE NH.id = " . intval($tplan_id) . " AND NH.node_type_id = 2";
        $rs = $db->get_recordset($sql);
        if (!is_null($rs) && isset($rs[0]['name'])) { $tplanName = $rs[0]['name']; }
    }

    $canModify = $user->hasRight($db, 'mgt_modify_tc', $tproject_id);
    if (!$canModify) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'mgt_modify_tc right required']);
    }

    list($tracker, $cts, $err) = linkedTracker($db, $tproject_id);
    $trackerInfo = null;
    $metadata = null;
    $scripts = [];
    if (is_null($err)) {
        $row = $GLOBALS['ctmgr']->getByID($tracker['codetracker_id']);
        $cfg = parseTrackerCfg($row['cfg'] ?? '');
        $trackerInfo = [
            'name' => $tracker['codetracker_name'] ?? '',
            'verboseType' => $tracker['verboseType'] ?? '',
            'verboseID' => $tracker['codetracker_name'] ?? '',
            'createCodeURL' => (!is_null($cts) && method_exists($cts, 'getEnterCodeURL')) ? $cts->getEnterCodeURL() : '',
            'repository' => $cfg['repository'] === '' ? ($cfg['owner'] . '/' . $cfg['repo']) : $cfg['repository'],
            'owner' => $cfg['owner'],
            'repo' => $cfg['repo'],
            'branch' => $cfg['branch'],
        ];
        $metadata = buildMetadata($db, $tproject_id, $cts, $cfg,
            ['project_key' => '', 'repository_name' => '', 'branch_name' => '', 'commit_id' => '']);

        // existing linked scripts (scriptDelete.php list context)
        $tbk = ['testcase_script_links'];
        $tbl = tlObjectWithDB::getDBTables($tbk);
        $sql = " SELECT * FROM `{$tbl['testcase_script_links']}` " .
               " WHERE tcversion_id = " . intval($tcversion_id) .
               " ORDER BY repository_name, code_path";
        $rsx = $db->get_recordset($sql);
        if (!is_null($rsx)) {
            foreach ($rsx as $s) {
                $script_id = (string)$s['project_key'] . '&&' . (string)$s['repository_name'] . '&&' . (string)$s['code_path'];
                $viewUrl = '';
                if (!is_null($cts) && method_exists($cts, 'buildViewCodeURL')) {
                    try {
                        $viewUrl = $cts->buildViewCodeURL($s['project_key'], $s['repository_name'],
                                                          $s['code_path'], $s['branch_name'] ?? null, $s['commit_id'] ?? null);
                    } catch (Exception $e) { $viewUrl = ''; }
                } elseif ($cfg['owner'] !== '' && $cfg['repo'] !== '') {
                    $branch = ($s['branch_name'] ?? '') !== '' ? $s['branch_name'] : $cfg['branch'];
                    if (($s['commit_id'] ?? '') !== '' && strlen((string)$s['commit_id']) >= 7) {
                        $viewUrl = $cfg['viewbase'] . '/' . $cfg['owner'] . '/' . $cfg['repo'] . '/commit/' . $s['commit_id'];
                    } elseif ($branch !== '') {
                        $viewUrl = $cfg['viewbase'] . '/' . $cfg['owner'] . '/' . $cfg['repo'] . '/blob/' . $branch . '/' . ltrim((string)$s['code_path'], '/');
                    }
                }
                $scripts[] = [
                    'script_id' => $script_id,
                    'project_key' => $s['project_key'],
                    'repository_name' => $s['repository_name'],
                    'code_path' => $s['code_path'],
                    'branch_name' => $s['branch_name'] ?? '',
                    'commit_id' => $s['commit_id'] ?? '',
                    'link_label' => ltrim((string)$s['code_path'], '/'),
                    'view_url' => $viewUrl,
                ];
            }
        }
    } else {
        $trackerInfo = null;
        $metadata = null;
    }

    out([
        'status' => 'ok',
        'context' => [
            'tproject_id' => $tproject_id,
            'tplan_id' => $tplan_id,
            'tcversion_id' => $tcversion_id,
            'user_action' => $user_action,
            'tprojectName' => $tprojectName,
            'tplanName' => $tplanName,
            'tcversionName' => $tv['tc_name'] ?? '',
            'tc_external_id' => $tv['tc_external_id'] ?? '',
            'version' => $tv['version'] ?? '',
        ],
        'can_modify' => $canModify ? 'yes' : 'no',
        'code_tracker' => $trackerInfo,
        'tracker_message' => is_null($err) ? null : $err,
        'metadata' => $metadata,
        'scripts' => $scripts,
    ]);
}

if ($method === 'GET' && $action === 'meta') {
    $tproject_id = resolveProjectId();
    if (is_null($tproject_id)) { badRequest('Unable to resolve test project'); }
    list($tracker, $cts, $err) = linkedTracker($db, $tproject_id);
    if (!is_null($err)) { http_response_code(409); out(['status' => 'error', 'message' => $err]); }
    $row = $GLOBALS['ctmgr']->getByID($tracker['codetracker_id']);
    $cfg = parseTrackerCfg($row['cfg'] ?? '');
    $metadata = buildMetadata($db, $tproject_id, $cts, $cfg, [
        'project_key' => isset($_GET['project_key']) && trim($_GET['project_key']) !== '' ? urldecode(trim($_GET['project_key'])) : '',
        'repository_name' => isset($_GET['repository_name']) && trim($_GET['repository_name']) !== '' ? urldecode(trim($_GET['repository_name'])) : '',
        'branch_name' => isset($_GET['branch_name']) && trim($_GET['branch_name']) !== '' ? urldecode(trim($_GET['branch_name'])) : '',
        'commit_id' => isset($_GET['commit_id']) && trim($_GET['commit_id']) !== '' ? urldecode(trim($_GET['commit_id'])) : '',
    ]);
    out(['status' => 'ok', 'metadata' => $metadata]);
}

if ($method === 'GET' && $action === 'files') {
    $tproject_id = resolveProjectId();
    if (is_null($tproject_id)) { badRequest('Unable to resolve test project'); }
    $path = isset($_GET['path']) ? trim($_GET['path']) : '';
    $branch = isset($_GET['branch']) ? trim($_GET['branch']) : '';
    $project_key = isset($_GET['project_key']) ? urldecode(trim($_GET['project_key'])) : '';
    $repository_name = isset($_GET['repository_name']) ? urldecode(trim($_GET['repository_name'])) : '';

    list($tracker, $cts, $err) = linkedTracker($db, $tproject_id);
    if (!is_null($err)) { http_response_code(409); out(['status' => 'error', 'message' => $err]); }
    $row = $GLOBALS['ctmgr']->getByID($tracker['codetracker_id']);
    $cfg = parseTrackerCfg($row['cfg'] ?? '');
    $items = [];
    if ($cfg['owner'] !== '' && $cfg['repo'] !== '') {
        $items = ghContents($cfg, $path, $branch);
        if (is_null($items)) {
            http_response_code(502);
            out(['status' => 'error', 'message' => 'Unable to list repository contents (check branch, path and token)']);
        }
    } elseif (!is_null($cts) && method_exists($cts, 'getRepoContentForHTMLSelect')) {
        $pk = $project_key !== '' ? $project_key : $cfg['owner'];
        $rn = $repository_name !== '' ? $repository_name : $cfg['repo'];
        $br = $branch !== '' ? $branch : $cfg['branch'];
        try {
            $tree = $cts->getRepoContentForHTMLSelect($pk, $rn, $path, $br, '');
            $items = treeToItems($tree);
        } catch (Throwable $e) {
            $items = [];
        }
    } else {
        http_response_code(502);
        out(['status' => 'error', 'message' => 'Code tracker interface does not support file browsing']);
    }
    usort($items, function ($a, $b) {
        if ($a['type'] !== $b['type']) { return $a['type'] === 'dir' ? -1 : 1; }
        return strcasecmp($a['name'], $b['name']);
    });
    out(['status' => 'ok', 'items' => $items, 'path' => '/' . trim($path, '/')]);
}

if ($method === 'POST' && $action === 'save') {
    $tproject_id = intval($BODY['tproject_id'] ?? 0);
    $tcversion_id = intval($BODY['tcversion_id'] ?? 0);
    $project_key = isset($BODY['project_key']) ? trim((string)$BODY['project_key']) : '';
    $repository_name = isset($BODY['repository_name']) ? trim((string)$BODY['repository_name']) : '';
    $code_path = isset($BODY['code_path']) ? trim((string)$BODY['code_path']) : '';
    $branch_name = isset($BODY['branch_name']) && $BODY['branch_name'] !== '' ? trim((string)$BODY['branch_name']) : null;
    $commit_id = isset($BODY['commit_id']) && $BODY['commit_id'] !== '' ? trim((string)$BODY['commit_id']) : null;

    if ($tproject_id <= 0 || $tcversion_id <= 0) { badRequest('tproject_id and tcversion_id are required'); }
    if ($project_key === '' || $repository_name === '' || $code_path === '') {
        badRequest('project_key, repository_name and code_path are required');
    }
    if (!$user->hasRight($db, 'mgt_modify_tc', $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'mgt_modify_tc right required']);
    }

    list($tracker, $cts, $err) = linkedTracker($db, $tproject_id);
    if (!is_null($err)) { http_response_code(409); out(['status' => 'error', 'message' => $err]); }
    $row = $GLOBALS['ctmgr']->getByID($tracker['codetracker_id']);
    $cfg = parseTrackerCfg($row['cfg'] ?? '');

    // Legacy scriptAdd.php path normalisation parity (baseURL strip + ?at= ref).
    if (!is_null($cts) && method_exists($cts, 'buildViewCodeURL')) {
        $baseURL = $cts->buildViewCodeURL($project_key, $repository_name, '');
        if ($baseURL !== '' && strpos($code_path, $baseURL) === 0) {
            $code_path = substr($code_path, strlen($baseURL));
        }
    } elseif ($cfg['owner'] !== '' && $cfg['repo'] !== '') {
        $viewBase = $cfg['viewbase'];
        if ($code_path !== '' && strpos($code_path, $viewBase) === 0) {
            $code_path = substr($code_path, strlen($viewBase));
        }
        $code_path = ltrim($code_path, '/');
        if (preg_match('#^' . preg_quote($cfg['owner'], '#') . '/' . preg_quote($cfg['repo'], '#') . '/(blob|tree)/#', $code_path)) {
            $seg = explode('/', $code_path);
            $refPtr = isset($seg[3]) ? urldecode($seg[3]) : '';
            $code_path = implode('/', array_slice($seg, 4));
            if ($refPtr !== '' && is_null($branch_name) && is_null($commit_id)) {
                if (strpos($refPtr, 'refs/') === 0) {
                    $branch_name = (strpos($refPtr, 'refs/heads/') === 0) ? substr($refPtr, 11) : $refPtr;
                } elseif (preg_match('/^[0-9a-f]{7,40}$/', $refPtr)) {
                    $commit_id = $refPtr;
                } else {
                    $branch_name = $refPtr;
                }
            }
        }
    }
    $refPos = strpos($code_path, '?at=');
    if ($refPos !== false) {
        $refStr = substr($code_path, $refPos);
        if (is_null($branch_name) && strpos($refStr, '?at=refs%2Fheads%2F') !== false) {
            $branch_name = str_replace('?at=refs%2Fheads%2F', '', $refStr);
        } elseif (is_null($commit_id)) {
            $commit_id = str_replace('?at=', '', $refStr);
        }
        $code_path = substr($code_path, 0, $refPos);
    }

    // Validate the path exists on the CTS (legacy write_testcase_script parity).
    $valid = false;
    if ($cfg['owner'] !== '' && $cfg['repo'] !== '') {
        $branch = $branch_name ?: $cfg['branch'];
        $valid = ghPathExists($cfg, $code_path, $branch);
    } elseif (!is_null($cts) && method_exists($cts, 'getRepoContent')) {
        $cont = $cts->getRepoContent($project_key, $repository_name, $code_path,
                                     is_null($branch_name) ? $cfg['branch'] : $branch_name, $commit_id);
        $valid = !is_null($cont) && !(property_exists($cont, 'errors') || ($cont === false) || (is_array($cont) && isset($cont['errors'])));
    }
    if (!$valid) {
        $msg = sprintf(lang_get('error_code_does_not_exist_on_cts'), $code_path);
        http_response_code(400);
        out(['status' => 'error', 'message' => $msg]);
    }

    // Write the link row (dedupe-first — legacy write_testcase_script parity).
    $tbk = ['testcase_script_links'];
    $tbl = tlObjectWithDB::getDBTables($tbk);
    $sql = " SELECT * FROM `{$tbl['testcase_script_links']}` " .
           " WHERE tcversion_id = " . intval($tcversion_id) .
           " AND project_key = '" . $db->prepare_string($project_key) . "'" .
           " AND repository_name = '" . $db->prepare_string($repository_name) . "'" .
           " AND code_path = '" . $db->prepare_string($code_path) . "'";
    $rs = $db->get_recordset($sql);
    if (is_null($rs)) {
        $fields = "(tcversion_id, project_key, repository_name, code_path";
        $values = "(" . intval($tcversion_id) . ", '" . $db->prepare_string($project_key) . "', '" .
                  $db->prepare_string($repository_name) . "', '" . $db->prepare_string($code_path) . "'";
        if (!is_null($branch_name)) {
            $fields .= ", branch_name";
            $values .= ", '" . $db->prepare_string($branch_name) . "'";
        }
        if (!is_null($commit_id)) {
            $fields .= ", commit_id";
            $values .= ", '" . $db->prepare_string($commit_id) . "'";
        }
        $fields .= ")";
        $values .= ")";
        $inserted = $db->exec_query(" INSERT INTO `{$tbl['testcase_script_links']}` {$fields} VALUES {$values}");
        if (!$inserted) {
            http_response_code(500);
            out(['status' => 'error', 'message' => 'DB error inserting testcase script link']);
        }
    }

    // Session selection persistence (legacy parity).
    $_SESSION['testscript_projectKey'] = $project_key;
    $_SESSION['testscript_repositoryName'] = $repository_name;

    // Custom Field "Test Script" sync (write_cfield_testscript parity).
    $note = '';
    $tcase_mgr = new testcase($db);
    $linked_cfields = $tcase_mgr->cfield_mgr->get_linked_cfields_at_design($tproject_id, 1, null, 'testcase', $tcversion_id);
    unset($tcase_mgr);
    $tScriptFieldID = null;
    if (!is_null($linked_cfields)) {
        foreach ($linked_cfields as $cfieldID => $cfieldValue) {
            if (strpos(strtolower(str_replace(' ', '', $cfieldValue['name'])), 'testscript') !== false) {
                $tScriptFieldID = $cfieldID;
                break;
            }
        }
    }
    if (!is_null($tScriptFieldID)) {
        $written = false;
        $sql = " SELECT id, active, is_open, baseline, reviewer_id FROM tcversions WHERE id = " . intval($tcversion_id);
        $rs = $db->get_recordset($sql);
        if (!is_null($rs) && isset($rs[0])) {
            $rs = $rs[0];
            if ($rs['active'] == 1 && $rs['is_open'] == 1 && is_null($rs['baseline']) && is_null($rs['reviewer_id'])) {
                $sql = " SELECT id FROM executions WHERE tcversion_id = " . intval($tcversion_id);
                $rsExec = $db->get_recordset($sql);
                if (is_null($rsExec) || $user->hasRight($db, 'testproject_edit_executed_testcases', $tproject_id)) {
                    $written = $db->exec_query(" UPDATE cfield_design_values SET value = '" .
                        $db->prepare_string($code_path) . "' WHERE field_id = " . intval($tScriptFieldID) .
                        " AND node_id = " . intval($tcversion_id));
                }
            }
        }
        if (!$written) {
            $note = ' - But Custom Field \'Test Script\' could not be updated';
        }
    }

    $directLink = '';
    if (!is_null($cts) && method_exists($cts, 'buildViewCodeURL')) {
        $directLink = $cts->buildViewCodeURL($project_key, $repository_name, $code_path, $branch_name);
    } elseif ($cfg['owner'] !== '' && $cfg['repo'] !== '') {
        $branch = $branch_name ?: $cfg['branch'];
        $directLink = $cfg['viewbase'] . '/' . $cfg['owner'] . '/' . $cfg['repo'] . '/blob/' . $branch . '/' . $code_path;
    }
    logAuditEvent(TLS('audit_testcasescript_added', $directLink), 'CREATE', $tcversion_id, 'testcase_script_links');

    out(['status' => 'ok',
         'message' => lang_get('script_added') . $note,
         'link' => ['script_id' => $project_key . '&&' . $repository_name . '&&' . $code_path,
                    'project_key' => $project_key,
                    'repository_name' => $repository_name,
                    'code_path' => $code_path,
                    'branch_name' => $branch_name,
                    'commit_id' => $commit_id,
                    'link_label' => $code_path,
                    'view_url' => $directLink]]);
}

if ($method === 'POST' && $action === 'delete') {
    $tproject_id = intval($BODY['tproject_id'] ?? 0);
    $tcversion_id = intval($BODY['tcversion_id'] ?? 0);
    $script_id = isset($BODY['script_id']) ? trim((string)$BODY['script_id']) : '';
    if ($tcversion_id <= 0 || $script_id === '') { badRequest('tcversion_id and script_id are required'); }
    if ($tproject_id <= 0) { $tproject_id = resolveProjectId(); }
    if ($tproject_id <= 0) { $tproject_id = tprojectForTcversion($db, $tcversion_id); }
    if ($tproject_id <= 0) { badRequest('Unable to resolve test project'); }

    if (!$user->hasRight($db, 'mgt_modify_tc', $tproject_id)) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'mgt_modify_tc right required']);
    }

    $scriptArray = explode('&&', $script_id);
    if (count($scriptArray) < 3) { badRequest('script_id must be project&&repository&&code_path'); }
    $project_key = $scriptArray[0];
    $repository_name = $scriptArray[1];
    $code_path = implode('&&', array_slice($scriptArray, 2));

    $tbk = ['testcase_script_links'];
    $tbl = tlObjectWithDB::getDBTables($tbk);
    $sql = " DELETE FROM `{$tbl['testcase_script_links']}` " .
           " WHERE tcversion_id = " . intval($tcversion_id) .
           " AND project_key = '" . $db->prepare_string($project_key) . "'" .
           " AND repository_name = '" . $db->prepare_string($repository_name) . "'" .
           " AND code_path = '" . $db->prepare_string($code_path) . "'";
    $result = $db->exec_query($sql);
    if (!$result) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'DB error deleting testcase script link']);
    }
    logAuditEvent(TLS('audit_testcasescript_deleted', $script_id), 'DELETE', $tcversion_id, 'testcase_script_links');
    out(['status' => 'ok', 'message' => lang_get('scriptdeleting_was_ok')]);
}

http_response_code(405);
out(['status' => 'error', 'message' => 'Method not allowed']);