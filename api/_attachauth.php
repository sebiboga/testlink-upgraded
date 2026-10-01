<?php
/**
 * Shared object-level authorization for the attachment BFF endpoints
 * (api/attachments/index.php, api/attachmentsdelete/index.php).
 *
 * Why this file exists - issue #1647:
 * both endpoints PROVED that the attachment belongs to the object the caller
 * named (table + fk_id match, or the legacy session allow-list), but neither
 * ever asked whether the CALLER MAY TOUCH that object. A user holding no right
 * at all (global role <no rights>, no user_testproject_roles row) could read
 * the attachment metadata and delete any attachment of any test project:
 *
 *   POST /api/attachmentsdelete/index.php?action=delete&id=<A>&table=<t>&fk_id=<id>
 *   -> 200 {"status":"ok","deleted_id":<A>}
 *
 * Ownership is not authorization: table/fk_id are caller supplied and are only
 * compared against the stored row.
 *
 * What this helper does:
 *   1. derives the TEST PROJECT that owns the attachment entity (every fk_table
 *      the two endpoints accept, plus the node walk used by nodes_hierarchy);
 *   2. maps that entity to the TestLink rights that grant visibility on it
 *      (this fork's right names - mgt_view_tc / mgt_view_req / mgt_view_key /
 *      cfield_view / testplan_* - NOT the upstream testcase_view naming);
 *   3. requires the CURRENT USER to hold at least one of them, through
 *      tlUser::hasRight(), which since #1763 also enforces the private-project
 *      rule (no user_testproject_roles row + private project + non admin = no
 *      access).
 *
 * FAIL CLOSED: when the owning test project cannot be derived (unknown table,
 * slimmed schema, deleted owner row) the candidate rights are evaluated
 * against the GLOBAL right set only. A caller without the right globally is
 * refused; an admin (global role 8, every right) keeps working. An unknown
 * fk_table therefore never means "allowed".
 *
 * Rationale for using the VISIBILITY right rather than a manage right: legacy
 * attachmentdelete.php had no right check at all, and every screen that offers
 * the delete popup (suiteView / testSpec / reqSpecView / reqView / execTest /
 * projectInfoView / attachmentUpload) is itself already gated on the manage
 * right, so the popup is not reachable without it. The visibility right here is
 * the missing server-side floor that stops a rights-less account from
 * destroying data through a hand-crafted call.
 */

if (count(get_included_files()) === 1) {
    http_response_code(403);
    exit;
}

/**
 * Prefixed physical table name. tlObject::getDBTables() cannot be used for the
 * lookups below: it throws on any name outside its own fixed list
 * (object.class.php:328) and e.g. 'latest_req_version' / 'node_types' are not
 * on it. DB_TABLE_PREFIX is exactly what it would have concatenated.
 *
 * @return string
 */
function attAuthTbl($name) {
    return DB_TABLE_PREFIX . $name;
}

/**
 * Cached, defensive column probe. This fork ships a slimmed schema (e.g.
 * tcversions has no testcase_id, req_versions has no req_id), and an unknown
 * column/table raises a DB error page in get_recordset() instead of
 * degrading, so every optional join is probed first.
 *
 * @return bool
 */
function attAuthHasColumn(&$db, $table, $col) {
    static $cache = array();
    $key = $table . '.' . $col;
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = false;
        // NOT tlObject::getDBTables(): it THROWS for a name outside its fixed
        // list (object.class.php:328) and several tables used below
        // (latest_req_version, node_types) are not on that list.
        $real = attAuthTbl($table);
        // 'SHOW COLUMNS' on a missing table emits a DB error; guard with a
        // cheap information_schema probe instead so a typo degrades to false.
        $rows = $db->get_recordset(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE " .
            "TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" .
            $db->prepare_string($real) . "' AND COLUMN_NAME = '" .
            $db->prepare_string($col) . "' LIMIT 1");
        if (is_array($rows) && count($rows) > 0) {
            $cache[$key] = true;
        }
    }
    return $cache[$key];
}

/**
 * @return int first integer value of the first row, 0 when nothing was found
 */
function attAuthFirstInt(&$db, $sql) {
    $rows = $db->get_recordset($sql);
    if (is_array($rows) && isset($rows[0])) {
        return intval(reset($rows[0]));
    }
    return 0;
}

/**
 * Walk nodes_hierarchy up to the testproject root (node_types id 1), like
 * arOwnerProjectId() of api/requirements/index.php:2249.
 *
 * @return int test project id, 0 when not derivable
 */
function attAuthNodeProjectId(&$db, $nodeId) {
    static $projectType = null;
    if (is_null($projectType)) {
        $rows = $db->get_recordset(
            "SELECT id FROM node_types WHERE description = 'testproject' LIMIT 1");
        $projectType = (is_array($rows) && isset($rows[0]))
            ? intval($rows[0]['id']) : 1;
    }
    $nh = attAuthTbl('nodes_hierarchy');
    $cursor = intval($nodeId);
    $guard = 0;
    while ($cursor > 0 && $guard++ < 200) {
        $row = $db->get_recordset(
            "SELECT parent_id,node_type_id FROM {$nh} " .
            "WHERE id = {$cursor} LIMIT 1");
        if (!is_array($row) || !isset($row[0])) {
            break;
        }
        if (intval($row[0]['node_type_id']) === $projectType) {
            return $cursor;
        }
        $cursor = intval($row[0]['parent_id']);
    }
    return 0;
}

/**
 * Test project of the entity that owns the attachment.
 *
 * @return int test project id, 0 when it cannot be derived
 */
function attAuthOwnerProjectId(&$db, $table, $fkId) {
    $table = strval($table);
    $fkId = intval($fkId);
    if ($fkId <= 0) {
        return 0;
    }
    $plans = attAuthTbl('testplans');

    if ($table === 'nodes_hierarchy') {
        return attAuthNodeProjectId($db, $fkId);
    }
    if ($table === 'testprojects') {
        return $fkId;
    }
    if ($table === 'testplans') {
        return attAuthFirstInt($db, "SELECT testproject_id FROM {$plans} " .
            "WHERE id = {$fkId} LIMIT 1");
    }
    if ($table === 'builds') {
        $builds = attAuthTbl('builds');
        return attAuthFirstInt($db, "SELECT testproject_id FROM {$builds} " .
            "WHERE id = {$fkId} LIMIT 1");
    }
    if ($table === 'executions') {
        $execs = attAuthTbl('executions');
        $planId = attAuthFirstInt($db,
            "SELECT testplan_id FROM {$execs} WHERE id = {$fkId} LIMIT 1");
        if ($planId <= 0) {
            return 0;
        }
        return attAuthFirstInt($db,
            "SELECT testproject_id FROM {$plans} WHERE id = {$planId} LIMIT 1");
    }
    if ($table === 'execution_tcsteps') {
        $ets = attAuthTbl('execution_tcsteps');
        $execId = attAuthFirstInt($db,
            "SELECT execution_id FROM {$ets} WHERE id = {$fkId} LIMIT 1");
        return ($execId > 0)
            ? attAuthOwnerProjectId($db, 'executions', $execId) : 0;
    }
    if ($table === 'req_specs' || $table === 'requirement_specs') {
        $specs = attAuthTbl('req_specs');
        return attAuthFirstInt($db,
            "SELECT testproject_id FROM {$specs} WHERE id = {$fkId} LIMIT 1");
    }
    if ($table === 'requirements') {
        $reqs = attAuthTbl('requirements');
        $srsId = attAuthFirstInt($db,
            "SELECT srs_id FROM {$reqs} WHERE id = {$fkId} LIMIT 1");
        return ($srsId > 0) ? attAuthOwnerProjectId($db, 'req_specs', $srsId) : 0;
    }
    if ($table === 'req_versions') {
        // latest_req_version(req_id, version) -> requirements.id -> req_specs
        $lrv = attAuthTbl('latest_req_version');
        $reqId = attAuthFirstInt($db,
            "SELECT req_id FROM {$lrv} WHERE version = {$fkId} LIMIT 1");
        return ($reqId > 0) ? attAuthOwnerProjectId($db, 'requirements', $reqId) : 0;
    }
    if ($table === 'keywords') {
        $kw = attAuthTbl('keywords');
        return attAuthFirstInt($db,
            "SELECT testproject_id FROM {$kw} WHERE id = {$fkId} LIMIT 1");
    }
    if ($table === 'cfields' || $table === 'custom_fields') {
        // a custom field may belong to several projects; any one the user can
        // see is enough.
        $cftp = attAuthTbl('cfield_testprojects');
        $rows = $db->get_recordset("SELECT testproject_id FROM {$cftp} " .
            "WHERE field_id = {$fkId} LIMIT 1");
        if (is_array($rows) && isset($rows[0])) {
            return intval($rows[0]['testproject_id']);
        }
        return 0;
    }
    if ($table === 'tcversions') {
        // this fork's tcversions has no testcase_id; the reachable link is
        // testplan_tcversions -> test plan -> project.
        $ptv = attAuthTbl('testplan_tcversions');
        $rows = $db->get_recordset(
            "SELECT t.testproject_id FROM {$ptv} pv " .
            "INNER JOIN {$plans} t ON t.id = pv.testplan_id " .
            "WHERE pv.tcversion_id = {$fkId} LIMIT 1");
        if (is_array($rows) && isset($rows[0])) {
            return intval($rows[0]['testproject_id']);
        }
        return 0;
    }
    return 0;
}

/**
 * Rights that grant visibility on the owning entity. The node type decides for
 * nodes_hierarchy, which holds EVERY container kind (testproject 1, testsuite
 * 2, testcase 3, build 12, testplan 5, requirement_spec 6 ...).
 *
 * @return array right names; empty means "unknown owner - deny"
 */
function attAuthOwnerRights(&$db, $table, $fkId) {
    $table = strval($table);
    switch ($table) {
        case 'nodes_hierarchy':
            $nt = attAuthFirstInt($db,
                "SELECT node_type_id FROM " . attAuthTbl('nodes_hierarchy') . " WHERE id = " .
                intval($fkId) . " LIMIT 1");
            if ($nt === 5) {            // testplan
                return array('mgt_view_tc', 'testplan_planning', 'testplan_metrics');
            }
            if ($nt === 1) {            // testproject
                return array('mgt_view_tc', 'mgt_view_req', 'mgt_view_key');
            }
            if ($nt === 6 || $nt === 7 || $nt === 8 || $nt === 10 || $nt === 11) {
                return array('mgt_view_req');
            }
            if ($nt === 13) {           // platform
                return array('platform_view');
            }
            if ($nt === 14) {           // user
                return array('mgt_users');
            }
            return array('mgt_view_tc'); // 2 testsuite, 3 testcase, 4 version, 12 build
        case 'testprojects':
            return array('mgt_view_tc', 'mgt_view_req', 'mgt_view_key');
        case 'testplans':
            return array('mgt_view_tc', 'testplan_planning', 'testplan_metrics',
                         'testplan_execute');
        case 'builds':
            return array('mgt_view_tc', 'testplan_create_build');
        case 'executions':
        case 'execution_tcsteps':
        case 'tcsteps':
            return array('mgt_view_tc', 'testplan_execute', 'exec_ro_access');
        case 'tcversions':
        case 'testcases':
            return array('mgt_view_tc');
        case 'req_specs':
        case 'requirement_specs':
        case 'req_versions':
        case 'requirements':
            return array('mgt_view_req');
        case 'keywords':
            return array('mgt_view_key');
        case 'cfields':
        case 'custom_fields':
            return array('cfield_view');
        default:
            // Unknown owner: stay fail closed (no rights -> no access).
            return array();
    }
}

/**
 * THE gate used by both attachment endpoints.
 *
 * @return bool true when the user may act on the owner of the attachment
 */
function attAuthOwnerAllowed(&$db, $user, $table, $fkId) {
    if (is_null($user)) {
        return false;
    }
    $rights = attAuthOwnerRights($db, $table, $fkId);
    if (count($rights) === 0) {
        return false;
    }
    $tprojectId = attAuthOwnerProjectId($db, $table, $fkId);
    foreach ($rights as $right) {
        // With a derivable project the right is judged on that project (tlUser
        // merges global + project role rights and enforces the private-project
        // rule). Without one, only the global right set is consulted, which is
        // what a global-only owner (platform, user) means anyway.
        $ok = ($tprojectId > 0)
            ? $user->hasRight($db, $right, $tprojectId)
            : $user->hasRight($db, $right);
        if ($ok) {
            return true;
        }
    }
    return false;
}