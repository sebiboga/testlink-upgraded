<?php
/**
 * api/bugadd — Bug Add / Link popup BFF (Refs #1560)
 *
 * Modernizes the last standalone legacy execute popup: the bug link / create
 * / add-note dialog lib/execute/bugAdd.php (+ legacy dashio bugAdd.tpl).
 * Legacy parity — the exact same 3 user flows:
 *   - user_action=link   -> validate + normalize a bug id against the
 *     configured issue tracker, write_execution_bug(exec, bug, tcstep),
 *     AUDIT audit_executionbug_added, then (when the tracker supports notes and
 *     the user asked for it) add a note containing the execution context
 *     (tag substitution %%EXECID%% / %%EXECTS%% / ...) plus optional direct
 *     links (addLinkToTL / addLinkToTLPrintView).
 *   - user_action=doCreate -> generate the default issue summary from the
 *     execution audit signature + timestamp, call its->addIssue() (the
 *     tracker must expose addIssue() = tlCanCreateIssue), write_execution_bug,
 *     AUDIT audit_executionbug_added.
 *   - user_action=add_note -> append a note to an ALREADY-linked bug id
 *     (readonly id field in the legacy dialog).
 *
 * The issue-meta selects (issue type / priority / version / component) mirror
 * the legacy policy: when the tracker cfg has userinteraction == 0 the
 * defaults come straight from cfg (single value per attribute); otherwise they
 * come from the tracker adapter's get*ForHTMLSelect() helpers
 * (getIssueTrackerMetaData).
 *
 * Routes:
 *   GET  ?action=init&exec_id=N[&tcstep_id=N][&user_action=link|create|add_note]
 *        -> execution context + BTS config + (defaults|metadata). 401 anon,
 *           403 wrong rights, 404 unknown exec.
 *   POST ?action=link      {exec_id,tcstep_id,bug_id,bug_notes,
 *                           addLinkToTL,addLinkToTLPrintView}
 *   POST ?action=create    {exec_id,tcstep_id,bug_summary,bug_notes,
 *                           issueType,issuePriority,artifactComponent[],
 *                           artifactVersion[],addLinkToTL,addLinkToTLPrintView}
 *   POST ?action=add_note  {exec_id,bug_id,bug_notes}
 *
 * Session-based auth, JSON I/O, no Smarty.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

require_once(__DIR__ . '/../../lib/functions/exec.inc.php');

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

function out($data) {
    echo json_encode($data);
    exit;
}

function getBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function bugAddNotFound() {
    http_response_code(404);
    out(['status' => 'error', 'message' => 'Execution not found']);
}

function bugAddForbidden() {
    http_response_code(403);
    out(['status' => 'error',
         'message' => 'You do not have rights to manage bugs on this execution']);
}

// Resolve the execution to its owning test plan + test project and probe the
// right on the OWNING project (legacy checkRights used the session context via
// hasRightOnProj(); probing the owning project is the same superset used by
// api/bugdelete and keeps the popup safe against foreign executions).
function bugAddResolveCtx($execId) {
    global $db;
    if (!ctype_digit(strval($execId)) || intval($execId) <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid execution id']);
    }
    $rs = get_execution($db, $execId);
    if (!$rs || count($rs) === 0) {
        bugAddNotFound();
    }
    $row = $rs[0];
    $tplanId = intval($row['testplan_id'] ?? 0);
    if ($tplanId <= 0) {
        bugAddNotFound();
    }
    $tables = tlObjectWithDB::getDBTables(array('testplans', 'testprojects'));
    $tpRs = $db->get_recordset(
        "SELECT tp.testproject_id FROM {$tables['testplans']} tp " .
        "WHERE tp.id=" . $tplanId);
    if (!$tpRs || count($tpRs) === 0) {
        bugAddNotFound();
    }
    return array('row' => $row,
                 'testplan_id' => $tplanId,
                 'testproject_id' => intval($tpRs[0]['testproject_id']));
}

// Issue tracker resolution, ported 1:1 from legacy bugAdd.php getIssueTracker():
// returns array with the tracker interface object (or null) and the resolved
// configuration block that the modern screen mirrors from the legacy dialog.
function bugAddIssueTracker($tprojectId) {
    global $db;
    $gui = new stdClass();
    $gui->issueTrackerCfg = new stdClass();
    $gui->issueTrackerCfg->createIssueURL = null;
    $gui->issueTrackerCfg->VerboseID = '';
    $gui->issueTrackerCfg->VerboseType = '';
    $gui->issueTrackerCfg->bugIDMaxLength = 0;
    $gui->issueTrackerCfg->bugSummaryMaxLength = 100; // MAGIC (legacy)
    $gui->issueTrackerCfg->tlCanCreateIssue = false;
    $gui->issueTrackerCfg->tlCanAddIssueNote = true;

    $its = null;
    $tprojectMgr = new testproject($db);
    $info = $tprojectMgr->get_by_id($tprojectId);

    if (!empty($info['issue_tracker_enabled'])
        && config_get('exec_cfg')->features->issue_tracker->enabled) {
        $itMgr = new tlIssueTracker($db);
        $issueTrackerCfg = $itMgr->getLinkedTo($tprojectId);
        if (!is_null($issueTrackerCfg)) {
            $its = $itMgr->getInterfaceObject($tprojectId);
            $gui->issueTrackerCfg->VerboseType = $issueTrackerCfg['verboseType'];
            $gui->issueTrackerCfg->VerboseID = $issueTrackerCfg['issuetracker_name'];
            if (method_exists($its, 'getBugIDMaxLength')) {
                $gui->issueTrackerCfg->bugIDMaxLength = $its->getBugIDMaxLength();
            }
            if (method_exists($its, 'getEnterBugURL')) {
                $gui->issueTrackerCfg->createIssueURL = $its->getEnterBugURL();
            }
            if (method_exists($its, 'getBugSummaryMaxLength')) {
                $gui->issueTrackerCfg->bugSummaryMaxLength =
                    $its->getBugSummaryMaxLength();
            }
            $gui->issueTrackerCfg->tlCanCreateIssue = method_exists($its, 'addIssue');
            $gui->issueTrackerCfg->tlCanAddIssueNote = method_exists($its, 'addNote');
        }
    }
    return array($its, $gui->issueTrackerCfg);
}

// Execution context card + defaults, mirroring legacy initEnv().
function bugAddContext($execId, $tplanId, $tprojectId, $tcstepId, $userAction) {
    global $db;
    $ctx = bugAddResolveCtx($execId);
    $ainfo = get_execution($db, $execId, ['output' => 'audit']);
    $a = ($ainfo && count($ainfo) > 0) ? $ainfo[0] : [];
    $row = $ctx['row'];

    $execCfg = config_get('exec_cfg');
    $isCreate = ($userAction === 'create' || $userAction === 'doCreate');
    $isNote = ($userAction === 'add_note');

    // Legacy: for link/create the bug-note textarea is prefilled with the
    // execution notes (only when no explicit bug_id was given).
    $defaultNotes = '';
    if (!$isNote && !$isCreate) {
        $defaultNotes = trim(strval($row['notes'] ?? ''));
    }

    // tplan api key (needed by generateIssueText for %%EXECPLINK%%).
    $tables = tlObjectWithDB::getDBTables(array('testplans'));
    $tprs = $db->get_recordset(
        "SELECT api_key FROM {$tables['testplans']} WHERE id=" . intval($tplanId));
    $tplanApiKey = '';
    if ($tprs && count($tprs) > 0) {
        $tplanApiKey = strval($tprs[0]['api_key'] ?? '');
    }

    return [
        'exec_id' => intval($row['id']),
        'tcversion_id' => intval($row['tcversion_id']),
        'testplan_id' => $tplanId,
        'testproject_id' => $tprojectId,
        'tcstep_id' => intval($tcstepId),
        'execution_ts' => strval($row['execution_ts'] ?? ''),
        'status_char' => strval($row['status'] ?? ''),
        'build_name' => strval($a['build_name'] ?? ''),
        'platform_name' => strval($a['platform_name'] ?? ''),
        'testplan_name' => strval($a['testplan_name'] ?? ''),
        'testcase_name' => strval($a['testcase_name'] ?? ''),
        'testproject_name' => strval($a['testproject_name'] ?? ''),
        'execution_notes' => strval($row['notes'] ?? ''),
        'default_bug_notes' => $defaultNotes,
        'tplan_api_key' => $tplanApiKey,
        'add_link_to_tl_checked' => intval($execCfg->exec_mode->addLinkToTLChecked ?? 0) === 1,
        'add_link_to_tl_print_view_checked' => intval($execCfg->exec_mode->addLinkToTLPrintViewChecked ?? 0) === 1,
    ];
}

// Build the $args helper object in the exact legacy shape consumed by
// exec.inc.php generateIssueText() / addIssue().
function bugAddArgs($ctx, $tplanId, $tprojectId, $tplanApiKey) {
    global $db, $user;
    $args = new stdClass();
    $args->exec_id = $ctx['exec_id'];
    $args->tcversion_id = $ctx['tcversion_id'];
    $args->tplan_id = $tplanId;
    $args->tproject_id = $tprojectId;
    $args->tplan_apikey = $tplanApiKey;
    $args->basehref = $_SESSION['basehref'] ?? '';
    $args->user = $user;
    return $args;
}

// Direct link for the "add link to TL" note options (legacy getDirectLinkToExec).
function bugAddDirectLink($execId) {
    global $db;
    $tbk = array('executions', 'testplan_tcversions');
    $tbl = tlObjectWithDB::getDBTables($tbk);
    $sql = " SELECT EX.id,EX.build_id,EX.testplan_id,EX.tcversion_id," .
           " TPTCV.id AS feature_id " .
           " FROM {$tbl['executions']} EX " .
           " JOIN {$tbl['testplan_tcversions']} TPTCV " .
           " ON TPTCV.testplan_id=EX.testplan_id " .
           " AND TPTCV.tcversion_id=EX.tcversion_id " .
           " AND TPTCV.platform_id=EX.platform_id " .
           " WHERE EX.id=" . intval($execId);
    $rs = $db->get_recordset($sql);
    if (!$rs || count($rs) === 0) {
        return '';
    }
    $rss = $rs[0];
    return trim($_SESSION['basehref'] ?? '/', '/') . '/ltx.php?item=exec&feature_id=' .
        intval($rss['feature_id']) . '&build_id=' . intval($rss['build_id']);
}

$action = isset($_REQUEST['action']) && is_scalar($_REQUEST['action'])
        ? strtolower(trim((string) $_REQUEST['action'])) : '';
$method = $_SERVER['REQUEST_METHOD'];

// GET ?action=init
if ($action === 'init') {
    if ($method !== 'GET') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'GET required']);
    }
    $execId = intval($_REQUEST['exec_id'] ?? 0);
    $tcstepId = intval($_REQUEST['tcstep_id'] ?? 0);
    $userAction = isset($_REQUEST['user_action']) && is_scalar($_REQUEST['user_action'])
        ? strtolower(trim((string) $_REQUEST['user_action'])) : 'link';
    if (!in_array($userAction, array('link', 'create', 'doCreate', 'add_note'), true)) {
        $userAction = 'link';
    }

    $ctx = bugAddResolveCtx($execId);
    $tprojectId = $ctx['testproject_id'];
    $tplanId = $ctx['testplan_id'];
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId)) {
        bugAddForbidden();
    }

    list($its, $itsCfg) = bugAddIssueTracker($tprojectId);
    $info = bugAddContext($execId, $tplanId, $tprojectId, $tcstepId, $userAction);

    // Defaults vs metadata for the create-issue attribute selects.
    $issueType = null;
    $issuePriority = null;
    $artifactVersion = null;
    $artifactComponent = null;
    $editIssueAttr = 0;
    $metadata = null;
    if (!is_null($its)) {
        try {
            $itsDefaults = $its->getCfg();
            $editIssueAttr = intval($itsDefaults->userinteraction ?? 0);
            if ($editIssueAttr == 0) {
                $singleVal = array('issuetype' => 'issueType',
                                   'issuepriority' => 'issuePriority');
                foreach ($singleVal as $kj => $attr) {
                    if (property_exists($itsDefaults, $kj)) {
                        $$attr = $itsDefaults->$kj;
                    }
                }
                $multiVal = array('version' => 'artifactVersion',
                                  'component' => 'artifactComponent');
                foreach ($multiVal as $kj => $attr) {
                    if (property_exists($itsDefaults, $kj)) {
                        $$attr = (array) $itsDefaults->$kj;
                    }
                }
            } else {
                try {
                    $metadata = getIssueTrackerMetaData($its);
                } catch (Throwable $e) {
                    $metadata = null;
                }
            }
        } catch (Throwable $e) {
            // tracker cfg not readable — keep defaults
        }
    }

    out([
        'status' => 'ok',
        'action' => 'init',
        'user_action' => $userAction,
        'context' => $info,
        'issue_tracker' => [
            'enabled' => !is_null($its),
            'verbose_id' => strval($itsCfg->VerboseID ?? ''),
            'verbose_type' => strval($itsCfg->VerboseType ?? ''),
            'bug_id_max_length' => intval($itsCfg->bugIDMaxLength ?? 0),
            'bug_summary_max_length' => intval($itsCfg->bugSummaryMaxLength ?? 100),
            'create_issue_url' => $itsCfg->createIssueURL,
            'tl_can_create_issue' => boolVal($itsCfg->tlCanCreateIssue),
            'tl_can_add_issue_note' => boolVal($itsCfg->tlCanAddIssueNote),
        ],
        'edit_issue_attr' => $editIssueAttr,
        'issue_type' => $issueType,
        'issue_priority' => $issuePriority,
        'artifact_version' => $artifactVersion,
        'artifact_component' => $artifactComponent,
        'issue_metadata' => $metadata,
    ]);
}

// Shared POST handling for link / create / add_note.
if (in_array($action, array('link', 'create', 'add_note'), true)) {
    if ($method !== 'POST') {
        http_response_code(405);
        out(['status' => 'error', 'message' => 'POST required']);
    }
    $payload = getBody();
    if (!is_array($payload)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid JSON body']);
    }

    $execId = intval($payload['exec_id'] ?? 0);
    $ctx = bugAddResolveCtx($execId);
    $tprojectId = $ctx['testproject_id'];
    $tplanId = $ctx['testplan_id'];
    if (!$user->hasRight($db, 'testplan_execute', $tprojectId, $tplanId)) {
        bugAddForbidden();
    }

    list($its, $itsCfg) = bugAddIssueTracker($tprojectId);
    $info = bugAddContext($execId, $tplanId, $tprojectId,
                          intval($payload['tcstep_id'] ?? 0), $action);
    $args = bugAddArgs($info, $tplanId, $tprojectId, $info['tplan_api_key']);

    // --- link a bug ---------------------------------------------------------
    if ($action === 'link') {
        if (is_null($its)) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Issue tracker is not configured for this project']);
        }
        $bugId = isset($payload['bug_id']) && is_scalar($payload['bug_id'])
            ? trim((string) $payload['bug_id']) : '';
        $tcstepId = intval($payload['tcstep_id'] ?? 0);
        $args->tcstep_id = $tcstepId;

        if ($bugId === '') {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Missing bug id']);
        }

        if (!method_exists($its, 'checkBugIDSyntax') || !$its->checkBugIDSyntax($bugId)) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'wrong bug ID format (' . $bugId . ')']);
        }
        $bugID = method_exists($its, 'normalizeBugID') ? $its->normalizeBugID($bugId) : $bugId;
        if (method_exists($its, 'checkBugIDExistence') && !$its->checkBugIDExistence($bugID)) {
            http_response_code(400);
            out(['status' => 'error',
                 'message' => 'bug ' . $bugID . ' does not exist on the bug tracker']);
        }

        if (!write_execution_bug($db, $execId, $bugID, $tcstepId)) {
            http_response_code(500);
            out(['status' => 'error', 'message' => 'Link failed']);
        }
        logAuditEvent(TLS('audit_executionbug_added', $bugID), 'CREATE', $execId, 'executions');

        // Note append: blank notes are not added (legacy).
        $args->bug_notes = isset($payload['bug_notes']) && is_scalar($payload['bug_notes'])
            ? trim((string) $payload['bug_notes']) : '';
        $addLinkToTL = !empty($payload['addLinkToTL']);
        $addLinkToTLPrintView = !empty($payload['addLinkToTLPrintView']);
        $hasNotes = strlen($args->bug_notes) > 0;
        $noteMsg = '';
        if ($itsCfg->tlCanAddIssueNote && ($hasNotes || $addLinkToTL || $addLinkToTLPrintView)) {
            try {
                if ($addLinkToTL || $addLinkToTLPrintView) {
                    $args->direct_link = bugAddDirectLink($execId);
                    $aop = array('addLinkToTL' => $addLinkToTL,
                                 'addLinkToTLPrintView' => $addLinkToTLPrintView);
                    $dummy = generateIssueText($db, $args, $its, $aop);
                    $args->bug_notes = $dummy->description;
                }
                $opt = new stdClass();
                $opt->reporter = $user->login;
                $opt->reporter_email = trim($user->emailAddress);
                if ('' == $opt->reporter_email) {
                    $opt->reporter_email = $opt->reporter;
                }
                $nr = $its->addNote($bugID, $args->bug_notes, $opt);
                if (!is_array($nr)) {
                    $nr = array('status_ok' => true, 'msg' => '');
                }
                if (empty($nr['status_ok'])) {
                    $noteMsg = strval($nr['msg'] ?? 'Note could not be added');
                }
            } catch (Throwable $e) {
                $noteMsg = 'Note could not be added: ' . $e->getMessage();
            }
        }

        out([
            'status' => 'ok',
            'action' => 'link',
            'bug_id' => $bugID,
            'tcstep_id' => $tcstepId,
            'exec_id' => $execId,
            'note_message' => $noteMsg,
            'note_added' => $noteMsg === '',
        ]);
    }

    // --- create a bug -------------------------------------------------------
    if ($action === 'create') {
        if (is_null($its) || !$itsCfg->tlCanCreateIssue) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Issue tracker cannot create issues']);
        }
        $args->bug_summary = isset($payload['bug_summary']) && is_scalar($payload['bug_summary'])
            ? trim((string) $payload['bug_summary']) : '';
        $args->bug_notes = isset($payload['bug_notes']) && is_scalar($payload['bug_notes'])
            ? trim((string) $payload['bug_notes']) : '';
        $args->tcstep_id = intval($payload['tcstep_id'] ?? 0);
        $args->direct_link = bugAddDirectLink($execId);
        $args->issueType = isset($payload['issueType']) ? $payload['issueType'] : null;
        $args->issuePriority = isset($payload['issuePriority']) ? $payload['issuePriority'] : null;
        $args->artifactComponent = isset($payload['artifactComponent']) ? $payload['artifactComponent'] : null;
        $args->artifactVersion = isset($payload['artifactVersion']) ? $payload['artifactVersion'] : null;

        $addLinkToTL = !empty($payload['addLinkToTL']);
        $addLinkToTLPrintView = !empty($payload['addLinkToTLPrintView']);
        $aop = array('addLinkToTL' => $addLinkToTL,
                     'addLinkToTLPrintView' => $addLinkToTLPrintView);

        try {
            $ret = addIssue($db, $args, $its, $aop);
        } catch (Throwable $e) {
            http_response_code(500);
            out(['status' => 'error',
                 'message' => 'Issue creation failed: ' . $e->getMessage()]);
        }
        if (empty($ret['status_ok'])) {
            http_response_code(500);
            out(['status' => 'error',
                 'message' => strval($ret['msg'] ?? 'Issue creation failed')]);
        }
        out([
            'status' => 'ok',
            'action' => 'create',
            'bug_id' => strval($ret['bug_id'] ?? ''),
            'exec_id' => $execId,
            'message' => strval($ret['msg'] ?? ''),
        ]);
    }

    // --- add a note to an existing bug ---------------------------------------
    if ($action === 'add_note') {
        if (is_null($its)) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Issue tracker is not configured for this project']);
        }
        $bugId = isset($payload['bug_id']) && is_scalar($payload['bug_id'])
            ? trim((string) $payload['bug_id']) : '';
        $args->bug_id = $bugId;
        if ($bugId === '') {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Missing bug id']);
        }
        $args->bug_notes = isset($payload['bug_notes']) && is_scalar($payload['bug_notes'])
            ? trim((string) $payload['bug_notes']) : '';

        $noteMsg = '';
        if ($itsCfg->tlCanAddIssueNote && strlen($args->bug_notes) > 0) {
            $opt = new stdClass();
            $opt->reporter = $user->login;
            $opt->reporter_email = trim($user->emailAddress);
            if ('' == $opt->reporter_email) {
                $opt->reporter_email = $opt->reporter;
            }
            try {
                $nr = $its->addNote($bugId, $args->bug_notes, $opt);
                if (!is_array($nr)) {
                    $nr = array('status_ok' => true, 'msg' => '');
                }
                if (empty($nr['status_ok'])) {
                    $noteMsg = strval($nr['msg'] ?? 'Note could not be added');
                }
            } catch (Throwable $e) {
                $noteMsg = 'Note could not be added: ' . $e->getMessage();
            }
        }
        if ($noteMsg !== '') {
            http_response_code(400);
            out(['status' => 'error', 'message' => $noteMsg]);
        }
        out([
            'status' => 'ok',
            'action' => 'add_note',
            'bug_id' => $bugId,
            'exec_id' => $execId,
        ]);
    }
}

http_response_code(400);
out(['status' => 'error', 'message' => 'Unknown or missing action']);