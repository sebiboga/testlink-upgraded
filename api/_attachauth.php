<?php
/**
 * Shared object-level authorization for the attachment BFF
 * (api/attachments/index.php).
 *
 * WHY: the attachment API only knew session authentication. `checkFk()`
 * validated that `table` is one of the whitelisted tables TestLink stores
 * attachments for and that the id is positive - that is an input check, not a
 * permission. Measured consequence (issue #1768): an authenticated user with
 * the global `<no rights>` role (users.role_id = 3, zero rows in role_rights,
 * no user_testproject_roles row) could
 *   - GET  ?action=list&table=testprojects&id=<any>      -> 200 + metadata
 *   - POST ?action=upload table=testprojects&id=<any>    -> 200, row created
 *   - GET  ?action=download&id=<any>                     -> 200 + file bytes
 * i.e. read and WRITE files on every object of the installation.
 *
 * THE GATE: resolve the OWNING object of the attachment set (never the session
 * context, so a forged table/id pair cannot gate rights against a project the
 * user may see) and require the visibility right that the legacy screen showing
 * those attachments already required:
 *
 *   executions                                -> testplan_execute | exec_ro_access
 *                                               | exec_edit_notes   (on the plan)
 *   testplans / builds                        -> testplan_execute | testplan_planning
 *                                               | testplan_create_build | testplan_metrics
 *   testprojects                              -> mgt_view_tc | mgt_modify_tc
 *                                               | mgt_view_req | mgt_modify_req
 *                                               | mgt_modify_product | testproject_metrics_dashboard
 *                                               (the grant set api/projectinfo and the
 *                                                dashboard already expose)
 *   nodes_hierarchy / testsuites / testcases / -> mgt_view_tc | mgt_modify_tc
 *   tcversions / tcsteps                        (legacy suite viewer / containerEdit)
 *   execution_tcsteps                          -> same as executions (the row
 *                                               points at its execution)
 *   req_specs / requirement_specs /            -> mgt_view_req | mgt_modify_req
 *   requirements / req_versions                  | req_tcase_link_management | monitor_requirement
 *                                                (legacy reqView.php / requirement_spec_mgr)
 *
 * For action=upload the read-only entries of each set are replaced by their
 * modify counterpart (attAuthOwnerAllowed($forWrite = true)).
 *
 * tlUser::hasRight() itself already refuses a private test project the user
 * holds no role on (tlUser.class.php:935-944, Refs #1763), and admin keeps the
 * legacy exception, so this helper inherits both behaviours instead of
 * re-implementing them.
 *
 * FAIL CLOSED: any owner that cannot be resolved (unknown table, deleted
 * object, broken hierarchy) is denied, never allowed.
 *
 * The public share-link download path (lnl.php ?type=file, Refs #1541) is NOT
 * routed through here: it authenticates with a 64-char OBJECT key, which is
 * already bound to the owning entity by bffAttachBindObjectKey() in
 * api/attachments/index.php. A 32-char key is a USER key and resolves to that
 * user, whose rights are then evaluated here exactly like a session login.
 */

if (count(get_included_files()) === 1) {
    http_response_code(403);
    exit;
}

if (!function_exists('attAuthNodeTypes')) {
    /**
     * node_type_id map of the 2.0.1 `node_types` table (testproject, testsuite,
     * testcase, testcase_version, testcase_step, requirement_spec, requirement,
     * requirement_version). Resolved from the DB like every other BFF does
     * (api/suitemove/index.php:83-100, api/tcreorder/index.php:88-100) and
     * backed by the ids shipped in
     * install/sql/mysql/testlink_create_default_data.sql:12-20, so a fork that
     * renumbered the table still works. Cached per request.
     *
     * @return array map description => node_type_id
     */
    function attAuthNodeTypes(&$db)
    {
        static $types = null;
        if (!is_null($types)) {
            return $types;
        }
        $types = array(
            'testproject' => 1, 'testsuite' => 2, 'testcase' => 3,
            'testcase_version' => 4, 'testplan' => 5,
            'requirement_spec' => 6, 'requirement' => 7,
            'requirement_version' => 8, 'testcase_step' => 9,
        );
        $tables = tlObjectWithDB::getDBTables(array('node_types'));
        $rows = $db->get_recordset(
            "SELECT id, description FROM {$tables['node_types']}");
        if (!is_null($rows)) {
            foreach ($rows as $row) {
                $descr = strval($row['description']);
                if (isset($types[$descr])) {
                    $types[$descr] = intval($row['id']);
                }
            }
        }
        return $types;
    }
}

if (!function_exists('attAuthFirstRow')) {
    /** @return array|null first row or null (never false). */
    function attAuthFirstRow(&$db, $sql)
    {
        $rows = $db->get_recordset($sql);
        if (is_null($rows) || count($rows) === 0) {
            return null;
        }
        return $rows[0];
    }
}

if (!function_exists('attAuthNode')) {
    /** @return array|null nodes_hierarchy row (id, parent_id, node_type_id). */
    function attAuthNode(&$db, $nodeId)
    {
        $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
        return attAuthFirstRow($db,
            "SELECT id, parent_id, node_type_id FROM {$tables['nodes_hierarchy']} " .
            "WHERE id = " . intval($nodeId) . " LIMIT 1");
    }
}

if (!function_exists('attAuthProjectOfNode')) {
    /**
     * Walk up nodes_hierarchy to the tree root (node_type_id = testproject),
     * which carries the same id as testprojects.id. Returns 0 when the walk
     * dead-ends - the caller then denies.
     */
    function attAuthProjectOfNode(&$db, $nodeId)
    {
        $types = attAuthNodeTypes($db);
        $seen = array();
        $nodeId = intval($nodeId);
        while ($nodeId > 0 && !isset($seen[$nodeId])) {
            $seen[$nodeId] = 1;
            $node = attAuthNode($db, $nodeId);
            if (is_null($node)) {
                return 0;
            }
            if (intval($node['node_type_id']) === $types['testproject']) {
                return intval($node['id']);
            }
            $nodeId = intval($node['parent_id']);
        }
        return 0;
    }
}

if (!function_exists('attAuthReqSpecProject')) {
    /** Requirement -> its req spec -> the test project of that spec. */
    function attAuthReqSpecProject(&$db, $reqSpecId)
    {
        $tables = tlObjectWithDB::getDBTables(array('req_specs'));
        $row = attAuthFirstRow($db,
            "SELECT testproject_id FROM {$tables['req_specs']} WHERE id = " .
            intval($reqSpecId) . " LIMIT 1");
        return is_null($row) ? 0 : intval($row['testproject_id']);
    }
}

if (!function_exists('attAuthResolveContext')) {
    /**
     * Resolve the object that owns an attachment set.
     *
     * @param string $fkTable fk_table of the attachment set (prefix stripped)
     * @param int    $fkId    id of the owning object
     *
     * @return array ['domain' => 'exec'|'plan'|'project'|'tc'|'req',
     *                'tproject_id' => int, 'tplan_id' => int]
     *               or ['domain' => '', ...] when the owner cannot be resolved.
     */
    function attAuthResolveContext(&$db, $fkTable, $fkId)
    {
        $types = attAuthNodeTypes($db);
        $fkTable = strval($fkTable);
        $fkId = intval($fkId);
        $ctx = array('domain' => '', 'tproject_id' => 0, 'tplan_id' => 0);

        if ($fkId <= 0) {
            return $ctx;
        }

        $tables = tlObjectWithDB::getDBTables(
            array('testplans', 'builds', 'executions', 'requirements',
                  'testprojects', 'execution_tcsteps'));

        switch ($fkTable) {
            case 'testprojects':
                // the project IS the owner; prove the row exists
                $row = attAuthFirstRow($db,
                    "SELECT id FROM {$tables['testprojects']} WHERE id = {$fkId} LIMIT 1");
                if (is_null($row)) {
                    return $ctx;
                }
                $ctx = array('domain' => 'project', 'tproject_id' => $fkId,
                             'tplan_id' => 0);
                break;

            case 'testplans':
                $row = attAuthFirstRow($db,
                    "SELECT testproject_id FROM {$tables['testplans']} " .
                    "WHERE id = {$fkId} LIMIT 1");
                if (is_null($row)) {
                    return $ctx;
                }
                $ctx = array('domain' => 'plan',
                             'tproject_id' => intval($row['testproject_id']),
                             'tplan_id' => $fkId);
                break;

            case 'builds':
                $row = attAuthFirstRow($db,
                    "SELECT testproject_id FROM {$tables['builds']} " .
                    "WHERE id = {$fkId} LIMIT 1");
                if (is_null($row)) {
                    return $ctx;
                }
                $ctx = array('domain' => 'plan',
                             'tproject_id' => intval($row['testproject_id']),
                             'tplan_id' => 0);
                break;

            case 'executions':
                $row = attAuthFirstRow($db,
                    "SELECT testplan_id FROM {$tables['executions']} " .
                    "WHERE id = {$fkId} LIMIT 1");
                if (is_null($row)) {
                    return $ctx;
                }
                $tplanId = intval($row['testplan_id']);
                $prs = attAuthFirstRow($db,
                    "SELECT testproject_id FROM {$tables['testplans']} " .
                    "WHERE id = {$tplanId} LIMIT 1");
                if (is_null($prs)) {
                    return $ctx;
                }
                $ctx = array('domain' => 'exec',
                             'tproject_id' => intval($prs['testproject_id']),
                             'tplan_id' => $tplanId);
                break;

            case 'execution_tcsteps':
                // lib/functions/exec.inc.php:226 stores the attachments of the
                // PREVIOUS run of a step under fk_table = execution_tcsteps
                // (api/execsetresults/index.php:745-747 lists them and hands out
                // the download_url). The row points at its execution, hence at
                // that execution's test plan.
                $row = attAuthFirstRow($db,
                    "SELECT execution_id FROM {$tables['execution_tcsteps']} " .
                    "WHERE id = {$fkId} LIMIT 1");
                if (is_null($row)) {
                    return $ctx;
                }
                $er = attAuthFirstRow($db,
                    "SELECT testplan_id FROM {$tables['executions']} " .
                    "WHERE id = " . intval($row['execution_id']) . " LIMIT 1");
                if (is_null($er)) {
                    return $ctx;
                }
                $tplanId = intval($er['testplan_id']);
                $prs = attAuthFirstRow($db,
                    "SELECT testproject_id FROM {$tables['testplans']} " .
                    "WHERE id = {$tplanId} LIMIT 1");
                if (is_null($prs)) {
                    return $ctx;
                }
                $ctx = array('domain' => 'exec',
                             'tproject_id' => intval($prs['testproject_id']),
                             'tplan_id' => $tplanId);
                break;

            case 'nodes_hierarchy':
            case 'testsuites':
            case 'testcases':
                // a hierarchy node owns its attachment set; the project is the
                // tree root (legacy containerEdit/testSpec resolve it the same
                // way, see api/suiteview owningProjectOf()). Project / suite /
                // test case nodes are design-time (tc right set); the
                // requirement nodes 6/7/8 that share the table are requirement
                // management, so they take the req right set.
                $node = attAuthNode($db, $fkId);
                if (is_null($node)) {
                    return $ctx;
                }
                $nodeType = intval($node['node_type_id']);
                $isReqNode = in_array($nodeType,
                    array($types['requirement_spec'], $types['requirement'],
                          $types['requirement_version']), true);
                $ctx = array('domain' => $isReqNode ? 'req' : 'tc',
                             'tproject_id' => attAuthProjectOfNode($db, $fkId),
                             'tplan_id' => 0);
                break;

            case 'tcversions':
                // 2.0.1 schema: a test case version is a nodes_hierarchy node
                // (node_type_id = testcase_version) whose id IS tcversions.id.
                $node = attAuthNode($db, $fkId);
                if (is_null($node) ||
                    intval($node['node_type_id']) !== $types['testcase_version']) {
                    return $ctx;
                }
                $ctx = array('domain' => 'tc',
                             'tproject_id' => attAuthProjectOfNode($db, $fkId),
                             'tplan_id' => 0);
                break;

            case 'tcsteps':
                // same node tree: testcase_step node -> testcase_version node
                $node = attAuthNode($db, $fkId);
                if (is_null($node) ||
                    intval($node['node_type_id']) !== $types['testcase_step']) {
                    return $ctx;
                }
                $ctx = array('domain' => 'tc',
                             'tproject_id' => attAuthProjectOfNode($db, $fkId),
                             'tplan_id' => 0);
                break;

            case 'req_specs':
            case 'requirement_specs':
                $ctx = array('domain' => 'req',
                             'tproject_id' => attAuthReqSpecProject($db, $fkId),
                             'tplan_id' => 0);
                break;

            case 'requirements':
                $row = attAuthFirstRow($db,
                    "SELECT srs_id FROM {$tables['requirements']} " .
                    "WHERE id = {$fkId} LIMIT 1");
                if (is_null($row)) {
                    return $ctx;
                }
                $ctx = array('domain' => 'req',
                             'tproject_id' => attAuthReqSpecProject(
                                 $db, $row['srs_id']),
                             'tplan_id' => 0);
                break;

            case 'req_versions':
                // requirement_mgr.class.php:68 binds requirement attachments to
                // req_versions; the version node (node_type_id =
                // requirement_version) hangs off the requirement node.
                $node = attAuthNode($db, $fkId);
                if (is_null($node) ||
                    intval($node['node_type_id']) !== $types['requirement_version']) {
                    return $ctx;
                }
                $reqRow = attAuthFirstRow($db,
                    "SELECT srs_id FROM {$tables['requirements']} " .
                    "WHERE id = " . intval($node['parent_id']) . " LIMIT 1");
                if (is_null($reqRow)) {
                    return $ctx;
                }
                $ctx = array('domain' => 'req',
                             'tproject_id' => attAuthReqSpecProject(
                                 $db, $reqRow['srs_id']),
                             'tplan_id' => 0);
                break;

            default:
                // unknown fk_table: no opinion -> deny (fail closed)
                return $ctx;
        }

        if ($ctx['tproject_id'] <= 0) {
            // owner could not be resolved to a project: fail closed
            $ctx['domain'] = '';
        }
        return $ctx;
    }
}

if (!function_exists('attAuthOwnerAllowed')) {
    /**
     * The gate for an attachment set.
     *
     * READ (list / download) accepts the visibility right that the legacy screen
     * showing those attachments already required. WRITE (upload) does NOT: a
     * read-only grant (exec_ro_access, mgt_view_tc, mgt_view_req,
     * testproject_metrics_dashboard) must not authorize writing a file into the
     * object, so the write set is the modify counterpart of each domain - the
     * rights the legacy upload screens themselves required
     * (containerEdit.php mgt_modify_tc, reqEdit.php mgt_modify_req,
     * execSetResults.php exec_edit_notes, planEdit.php testplan_planning).
     *
     * @param object $user     tlUser of the caller
     * @param array  $ctx      attAuthResolveContext() result
     * @param bool   $forWrite true for action=upload
     *
     * @return bool true when the caller may read (or write) this object's
     *              attachments. Unknown domain / unresolvable owner => false.
     */
    function attAuthOwnerAllowed(&$db, $user, $ctx, $forWrite = false)
    {
        $domain = strval($ctx['domain'] ?? '');
        $tprojectId = intval($ctx['tproject_id'] ?? 0);
        $tplanId = intval($ctx['tplan_id'] ?? 0);
        if ($domain === '' || $tprojectId <= 0 || is_null($user)) {
            return false;
        }

        // any of the listed rights, evaluated at the right scope
        $anyOf = function ($rights, $withPlan) use ($db, $user, $tprojectId,
                                                    $tplanId) {
            foreach ($rights as $right) {
                // $getAccess = true: the TEST PLAN accessibility flag has to
                // be filled, exactly like every other plan-scoped check in the
                // repo (api/execute/index.php:379, api/execsetresults/index.php:102
                // - both filter the plan through getAccessibleTestPlans() first).
                // Measured: with $getAccess left false, tlUser.class.php:962
                // reads the never-set $accessPublic['tplan'] -> "Undefined array
                // key \"tplan\"" E_WARNING x N per call in the Event Viewer AND
                // `null == 0` makes it return false, i.e. EVERY user without a
                // user_testplan_roles row - admin included - would be refused.
                // (the latent tlUser bug is filed separately).
                if ($withPlan) {
                    if ($user->hasRight($db, $right, $tprojectId, $tplanId,
                                        true)) {
                        return true;
                    }
                } else if ($user->hasRight($db, $right, $tprojectId)) {
                    return true;
                }
            }
            return false;
        };

        switch ($domain) {
            case 'exec':
                if ($forWrite) {
                    return $anyOf(array('testplan_execute', 'exec_edit_notes'),
                                  true);
                }
                return $anyOf(array('testplan_execute', 'exec_ro_access',
                                    'exec_edit_notes'), true);

            case 'plan':
                if ($forWrite) {
                    return $anyOf(array('testplan_planning',
                                        'testplan_create_build'), true);
                }
                return $anyOf(array('testplan_execute', 'testplan_planning',
                                    'testplan_create_build',
                                    'testplan_metrics'), true);

            case 'project':
                if ($forWrite) {
                    return $anyOf(array('mgt_modify_tc', 'mgt_modify_req',
                                        'mgt_modify_product'), false);
                }
                return $anyOf(array('mgt_view_tc', 'mgt_modify_tc',
                                    'mgt_view_req', 'mgt_modify_req',
                                    'mgt_modify_product',
                                    'testproject_metrics_dashboard'), false);

            case 'tc':
                if ($forWrite) {
                    return $anyOf(array('mgt_modify_tc'), false);
                }
                return $anyOf(array('mgt_view_tc', 'mgt_modify_tc'), false);

            case 'req':
                if ($forWrite) {
                    return $anyOf(array('mgt_modify_req'), false);
                }
                return $anyOf(array('mgt_view_req', 'mgt_modify_req',
                                    'req_tcase_link_management',
                                    'monitor_requirement'), false);

            default:
                return false;
        }
    }
}

if (!function_exists('attAuthCheckOwner')) {
    /**
     * Convenience wrapper: resolve + gate in one call.
     *
     * @param string $fkTable  fk_table of the attachment set
     * @param int    $fkId     id of the owning object
     * @param bool   $forWrite true when the caller wants to WRITE (upload)
     *
     * @return bool
     */
    function attAuthCheckOwner(&$db, $user, $fkTable, $fkId, $forWrite = false)
    {
        $ctx = attAuthResolveContext($db, $fkTable, $fkId);
        return attAuthOwnerAllowed($db, $user, $ctx, $forWrite);
    }
}