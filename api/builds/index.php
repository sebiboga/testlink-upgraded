<?php
/**
 * Builds & Releases BFF API
 * URL: /api/builds/
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/plan/buildView.php + lib/plan/buildEdit.php (TestLink 1.9.20
 * behavior): list builds of a test plan, create/update/delete, active/open
 * toggles, closed_on_date handling, copy build to all test plans of the
 * project and copy tester assignments from a source build.
 *
 * Rights (same as legacy screens):
 *   everything -> testplan_create_build (buildView.php checkRights rightsAnd)
 *   delete with existing executions additionally requires exec_delete
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();


header('Content-Type: application/json; charset=utf-8');

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

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/builds(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

function needTplanId() {
    $id = intval($_GET['tplan_id'] ?? ($_POST['tplan_id'] ?? 0));
    if ($id <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    return $id;
}

/**
 * Resolve the test plan context exactly like legacy initEnv(): plan must be
 * a testplan node; tproject_id comes from its parent.
 */
function resolveTplan(&$db, $tplanId) {
    $tplanMgr = new testplan($db);
    $info = $tplanMgr->tree_manager->get_node_hierarchy_info(
        $tplanId, null, array('nodeType' => 'testplan'));
    if (is_null($info)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Invalid Test Plan ID']);
    }
    return [
        'tplan_mgr' => $tplanMgr,
        'tplan_id' => $tplanId,
        'tplan_name' => $info['name'],
        'tproject_id' => intval($info['parent_id']),
    ];
}

/**
 * Resolve context for a build row. Builds are scoped to the Test Project
 * (issue #503), so authorization derives from the build's testproject_id
 * rather than a (now ambiguous) owning test plan.
 */
function resolveBuild(&$db, $b) {
    $tprojectId = intval($b['testproject_id'] ?? 0);
    $tp = new testproject($db);
    $info = $tp->tree_manager->get_node_hierarchy_info($tprojectId);
    if (is_null($info)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Invalid Test Project ID']);
    }
    return [
        'tproject_id' => $tprojectId,
        'tproject_name' => $info['name'],
    ];
}

/**
 * Reject a build addressed through a test plan of ANOTHER project.
 *
 * resolveBuild() derives the owning project from build.testproject_id, so the
 * authorization below it is always checked against the build's real project -
 * that part was never wrong. What was missing is the scope check: every
 * GET/PUT/DELETE /{id} route accepted a tplan_id and then silently ignored it.
 * The permission was therefore right while the ADDRESS was not, so a stale or
 * mis-scoped page (a row action carrying a build_id from a different project,
 * a bookmark, a hand-typed URL) could read, rename or DELETE a build of
 * another project and have the result shown inside this plan's context.
 *
 * The plan is resolved first, so a foreign or non-existent tplan_id can never
 * be used as an existence oracle, and the failure is a plain 404 - identical
 * to a build that does not exist, so the route leaks nothing.
 */
function assertBuildInTplan(&$db, $b, $tplanId) {
    $tplanId = intval($tplanId);
    if ($tplanId <= 0) {
        return; // not addressed by plan: the build's own project governs
    }
    $tp = new testplan($db);
    $plan = $tp->get_by_id($tplanId);
    if (is_null($plan)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Invalid Test Plan ID']);
    }
    if (intval($plan['testproject_id']) !== intval($b['testproject_id'] ?? 0)) {
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Build not found',
             'error_code' => 'build_not_found']);
    }
}

/** Project display name for audit entries - resolved from ctx, not session. */
function tprojectName(&$tp, $tprojectId) {
    $info = $tp->tree_manager->get_node_hierarchy_info(intval($tprojectId));
    return is_null($info) ? '' : $info['name'];
}

function canManage(&$user, &$db, $tprojectId) {
    return (bool)$user->hasRight($db, 'testplan_create_build', $tprojectId);
}

function canDeleteExec(&$user, &$db, $tprojectId) {
    return (bool)$user->hasRight($db, 'exec_delete', $tprojectId);
}

/** Trim a string body field. */
function strField($body, $key) {
    $v = isset($body[$key]) ? trim((string)$body[$key]) : '';
    return ($v === '') ? null : $v;
}

/** Validate ISO release date (YYYY-MM-DD), empty is allowed. */
function isoDateOrNull($body, $key = 'release_date') {
    $v = isset($body[$key]) ? trim((string)$body[$key]) : '';
    if ($v === '') {
        return [null, null];
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ||
        !checkdate(intval($m[2]), intval($m[3]), intval($m[1]))) {
        return [false, 'invalid_release_date'];
    }
    return [$v, null];
}

/* ------------------------------------------------------------------ */
/* Build custom fields (legacy buildEdit.php / buildEdit.tpl)          */
/*                                                                     */
/* The 1.9.20 Build Create/Edit form rendered the project's build      */
/* design custom fields:                                                */
/*   - buildEdit.php initializeGui() -> buildMgr->html_custom_field_    */
/*     inputs($build_id, $tproject_id, 'design', '', $_REQUEST)        */
/*   - buildEdit.tpl: {foreach $gui->cfields} <tr><th>{$cf.label}     */
/*     </th><td>{$cf.input}</td></tr>                                  */
/*   - doCreate()/doUpdate() persisted them with                      */
/*     cfield_mgr->design_values_to_db($_REQUEST, $buildID, $cf_map,  */
/*     null, 'build')  -> table cfield_build_design_values            */
/* The modern inline Create/Edit modal of buildsView.html never grew   */
/* the CF block, so build custom fields were silently DROPPED: the     */
/* definitions were neither shown nor written. These helpers restore   */
/* the parity: /cfields returns them as DATA (never as HTML, so no      */
/* injected markup can reach the DOM) and saveBuildCfields() writes     */
/* them back with the legacy hash key format.                           */
/* ------------------------------------------------------------------ */

/**
 * Custom-field definitions of a test project, with the current value for
 * an optional build. Returns [] when the project has none.
 *
 * build::get_linked_cfields_at_design() passes $id=0 as NULL internally, so
 * create mode (build_id absent) returns the definitions with no value and
 * the client shows default_value instead.
 */
function buildCfieldDefs(&$buildMgr, $tprojectId, $buildId = 0) {
    $cfMap = $buildMgr->get_linked_cfields_at_design(
        intval($buildId) > 0 ? intval($buildId) : 0, intval($tprojectId));
    $out = [];
    $types = [0 => 'string', 1 => 'numeric', 2 => 'float', 4 => 'email',
              5 => 'checkbox', 6 => 'list', 7 => 'multiselection list',
              8 => 'date', 9 => 'radio', 10 => 'datetime',
              20 => 'text area', 500 => 'script', 501 => 'server'];
    foreach ((array)$cfMap as $fieldId => $cf) {
        $typeId = intval($cf['type'] ?? 0);
        $value = isset($cf['value']) ? (string)$cf['value'] : null;
        $default = isset($cf['default_value']) ? (string)$cf['default_value'] : '';
        // A date/datetime CF is stored as a UNIX timestamp (cfield_mgr
        // mktime()s it in _build_cfield), but an <input type="date"> needs
        // ISO - handing the raw epoch to the DOM produced an unusable
        // control, so it is converted here, server side, in the ONE place
        // that knows the storage format.
        if (($typeId === 8 || $typeId === 10) && $value !== null
            && $value !== '' && ctype_digit((string)$value)) {
            $value = epochToIsoDate((int)$value, $typeId === 10);
        }
        $out[] = [
            'field_id'   => intval($fieldId),
            'name'       => (string)($cf['name'] ?? ''),
            'label'      => (string)($cf['label'] ?? ($cf['name'] ?? '')),
            'type'       => $typeId,
            'type_label' => $types[$typeId] ?? 'string',
            'required'   => !empty($cf['required']) ? 1 : 0,   // cfield_testprojects.required
            'possible_values' => (string)($cf['possible_values'] ?? ''),
            'default_value'   => $default,
            'value'      => ($value === null || $value === '') ? $default : $value,
            'has_value'  => ($value === null) ? 0 : 1,
        ];
    }
    return $out;
}

/** Timestamp -> ISO 'Y-m-d' (or 'Y-m-d H:i:s'), the shape the HTML date
 *  inputs and the BFF payload both speak. Returns '' for an unusable stamp
 *  instead of a bogus date.
 *
 *  date() and NOT gmdate(): the stamp was produced by cfield_mgr's mktime(),
 *  which is local-midnight in the SERVER timezone, so the only format that can
 *  read it back as the same calendar day is the local one. gmdate() agreed by
 *  accident on a UTC host and silently shifted the day by the UTC offset on
 *  every non-UTC install (e.g. Europe/Bucharest, UTC+2/+3). */
function epochToIsoDate($epoch, $withTime = false) {
    if ($epoch <= 0) {
        return '';
    }
    $iso = date($withTime ? 'Y-m-d H:i:s' : 'Y-m-d', intval($epoch));
    return ($iso === '1970-01-01' || $iso === '1970-01-01 00:00:00') ? '' : $iso;
}

/**
 * ISO 'Y-m-d' -> the session locale's date format, which is what
 * cfield_mgr::_build_cfield() parses (it calls
 * split_localized_date($value['input'], $date_format) with the locale
 * format, `%` stripped: 'd/m/Y' for en_GB, 'm/d/Y' for en_US, ...).
 *
 * The client always speaks ISO, so the locale-dependent half of the date
 * round trip lives HERE, server side - otherwise the same stored build date
 * silently became a different day for every locale. A value that is already
 * in the locale format is passed through untouched, so a caller that still
 * submits the legacy shape keeps working.
 */
function isoToLocaleDate($iso, $withTime = false) {
    if ($iso === '' || $iso === null) {
        return '';
    }
    $iso = trim((string)$iso);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $iso, $m)) {
        return $iso; // not ISO -> hand it to split_localized_date() as-is
    }
    $fmt = cfLocaleDateFormat();
    if ($withTime && !isset($m[4])) {
        $m[4] = '00';
        $m[5] = '00';
        $m[6] = '00';
    }
    // strftime()/IntlDateFormatter with %d/%m/%Y would be locale-independent
    // here anyway; the token substitution keeps this free of the PHP 8.1
    // strftime() deprecation so no Warning reaches the Event Viewer.
    return strtr($fmt, [
        'd' => $m[3],
        'm' => $m[2],
        'Y' => $m[1],
        'y' => substr($m[1], 2, 2),
        'H' => $m[4] ?? '00',
        'i' => $m[5] ?? '00',
        's' => $m[6] ?? '00',
    ]);
}

/** The current session locale's date format with the `%` markers stripped -
 *  exactly what cfield_mgr::_build_cfield() builds for itself. */
function cfLocaleDateFormat() {
    $cfg = config_get('locales_date_format');
    $locale = isset($_SESSION['locale']) ? $_SESSION['locale'] : 'en_GB';
    if (!isset($cfg[$locale])) {
        $locale = 'en_GB';
    }
    return str_replace('%', '', $cfg[$locale]);
}

/**
 * Persist submitted build custom fields, legacy-parity.
 *
 * cfield_mgr::design_values_to_db() parses a $_REQUEST-shaped hash whose keys
 * are `custom_field_<type_id>_<field_id>` (and, for a date field,
 * `custom_field_8_<field_id>_input`) - "carved in the stone" per
 * cfield_mgr::_build_cfield(). We only ever build those keys ourselves, from
 * the field ids the SERVER resolved, so a submitted key can never name a field
 * outside this project; unknown ids are dropped.
 *
 * NOTE this is a FULL REPLACEMENT of the build's design values, which is the
 * legacy behaviour: design_values_to_db() writes EVERY field of the passed
 * $cfMap, the hash only supplies their values, so a field the caller omitted
 * is stored as ''. That is exactly what the 1.9.20 form did (an HTML form
 * always submits every input, so clearing a field cleared its value). Every
 * field of the map is therefore given an explicit entry below, which keeps the
 * date/datetime branches on their array path instead of relying on the
 * string-to-array normalization that was added to _build_cfield().
 *
 * @return int number of FIELDS actually written (not count($cfMap): an
 *                unchecked checkbox/multiselection field is deliberately skipped)
 */
function saveBuildCfields(&$buildMgr, $body, $cfMap, $buildId) {
    if (is_null($cfMap) || count($cfMap) == 0) {
        return 0;
    }
    /* The key's PRESENCE decides, not its content: a caller that sends no
     * 'cfields' at all is not editing custom fields. The legacy full-screen
     * form always submitted every input, so full replacement was right for it -
     * but the inline Edit modal of buildsView.html sends only name/notes/
     * release_date/active/open, and design_values_to_db() writes EVERY field of
     * $cfMap, so treating that as "clear all" silently wiped the custom fields
     * of a build renamed from the table. */
    if (!array_key_exists('cfields', $body) || !is_array($body['cfields'])) {
        return 0;
    }
    $in = $body['cfields'];
    $hash = [];
    $written = 0;
    foreach ($cfMap as $fieldId => $cf) {
        $fieldId = intval($fieldId);
        $typeId  = intval($cf['type'] ?? 0);
        $val = array_key_exists($fieldId, $in) ? $in[$fieldId] : '';
        $prefix = 'custom_field_' . $typeId . '_' . $fieldId;
        if (in_array($typeId, [5, 7], true)) {           // checkbox / multiselection
            $vals = is_array($val) ? array_map('strval', $val) : (($val === '') ? [] : [(string)$val]);
            $vals = array_values(array_filter($vals, function ($v) { return $v !== ''; }));
            /* Nothing selected: skip the key entirely so _build_cfield() keeps
             * its '' initializer. Passing [] instead makes it read $value[0] on
             * an empty array (E_WARNING "Undefined array key 0") and store NULL,
             * which then trips tlStringLen(null)/prepare_string(null) deprecations
             * downstream - reachable from the plain "nothing ticked" state of a
             * checkbox/multiselection field. Legacy never saw it: an unticked
             * HTML form simply submits nothing. */
            if (empty($vals)) {
                continue;
            }
            // _build_cfield() implodes a multi-valued entry with '|', exactly as
            // the legacy form's repeated inputs arrived, so pass the list on.
            $hash[$prefix] = $vals;
            $written++;
        } elseif ($typeId === 8 || $typeId === 10) {     // date / datetime
            $isDateTime = ($typeId === 10);
            $val = is_array($val) ? (string)($val['input'] ?? '') : (string)$val;
            $hash[$prefix . '_input'] = isoToLocaleDate($val, $isDateTime);
            $written++;
            if ($isDateTime) {
                /* The locale format carries the DAY only, so the time of day
                 * has to travel in its own three legacy keys
                 * (custom_field_10_<id>_hour/_minute/_second, which
                 * _build_cfield() mktime()s into the stamp). They used to be
                 * pinned to 0, which silently reduced a datetime custom field
                 * to midnight; a <input type="datetime-local"> submits
                 * 'YYYY-MM-DDTHH:MM'. */
                $hour = '0'; $minute = '0'; $second = '0';
                if (preg_match('/^\d{4}-\d{2}-\d{2}[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', trim((string)$val), $tm)) {
                    $hour = $tm[1]; $minute = $tm[2]; $second = isset($tm[3]) ? $tm[3] : '0';
                }
                $hash[$prefix . '_hour']   = $hour;
                $hash[$prefix . '_minute'] = $minute;
                $hash[$prefix . '_second'] = $second;
            }
        } else {
            $hash[$prefix] = is_array($val) ? '' : (string)$val;
            $written++;
        }
    }
    if (count($hash) == 0) {
        return 0;
    }
    $buildMgr->cfield_mgr->design_values_to_db($hash, intval($buildId), $cfMap, null, 'build');
    // count($hash) would be wrong (a datetime is 4 keys for one field) and
    // count($cfMap) would over-report the fields deliberately skipped above.
    return $written;
}

$tplanMgr = new testplan($db);
$buildMgr = new build($db);

/* ------------------------------------------------------------------ */
/* GET routes                                                          */
/* ------------------------------------------------------------------ */

// GET /?tplan_id=N  -> builds list + context + per-screen rights
if ($method === 'GET' && count($segments) === 0) {
    // Issue #1030: builds are project-scoped (issue #503), so a list may be
    // requested either for a concrete test plan (tplan_id>0, legacy callers
    // and plan-scoped screens) or for the whole active project
    // (tplan_id=0 -> "Builds & Releases" under the project submenu).
    $tplanId = intval($_GET['tplan_id'] ?? ($_POST['tplan_id'] ?? 0));
    if ($tplanId > 0) {
        $ctx = resolveTplan($db, $tplanId);
    } else {
        // Project-scoped list: resolve the project from the active session
        // (legacy buildEdit fallback, common.php:testprojectID key), clip the
        // project to the same tree hierarchy used by resolveTplan().
        $tproject_id = intval($_SESSION['testprojectID'] ?? 0);
        if ($tproject_id <= 0) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'No active test project']);
        }
        $tpMgr = new testproject($db);
        $pinfo = $tpMgr->tree_manager->get_node_hierarchy_info($tproject_id);
        if (is_null($pinfo)) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Invalid Test Project ID']);
        }
        $ctx = [
            'tplan_id' => 0,
            'tplan_name' => $pinfo['name'],
            'tproject_id' => $tproject_id,
        ];
    }
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    // Source-build selector data (legacy init_source_build_selector):
    // newest first + assignment count per build. Project-scoped lists
    // (tplan_id=0) reuse the whole project build set (issue #503/#1030).
    $srcItems = [];
    $buildSet = null;
    if ($tplanId > 0) {
        $opts = $tplanMgr->get_builds_for_html_options(
            $tplanId, null, null, array('orderByDir' => 'id:DESC'));
        if (!is_null($opts)) {
            foreach ($opts as $bid => $bname) {
                $count = $tplanMgr->assignment_mgr
                    ->get_count_of_assignments_for_build_id($bid);
                $srcItems[] = ['id' => intval($bid), 'name' => $bname,
                               'assignments' => intval($count)];
            }
        }
        $buildSet = $tplanMgr->get_builds($tplanId);
    } else {
        // Project scope (issue #1030): list all builds of the project.
        $buildSet = $tplanMgr->get_builds(0, null, null,
            array('tproject_id' => $ctx['tproject_id']));
        if (!is_null($buildSet)) {
            // Newest first to mirror legacy selector ordering.
            $tmp = $buildSet;
            usort($tmp, function ($x, $y) { return intval($y['id']) - intval($x['id']); });
            foreach ($tmp as $b) {
                $count = $tplanMgr->assignment_mgr
                    ->get_count_of_assignments_for_build_id($b['id']);
                $srcItems[] = ['id' => intval($b['id']), 'name' => $b['name'],
                               'assignments' => intval($count)];
            }
        }
    }

    $items = [];
    if (!is_null($buildSet)) {
        foreach ($buildSet as $b) {
            $items[] = [
                'id' => intval($b['id']),
                'name' => $b['name'],
                'notes' => (string)$b['notes'],
                'release_date' => (isset($b['release_date']) && $b['release_date'])
                    ? substr($b['release_date'], 0, 10) : '',
                'closed_on_date' => (isset($b['closed_on_date']) && $b['closed_on_date'])
                    ? substr($b['closed_on_date'], 0, 10) : '',
                'active' => intval($b['active']),
                'is_open' => intval($b['is_open']),
                'commit_id' => (string)($b['commit_id'] ?? ''),
                'tag' => (string)($b['tag'] ?? ''),
                'branch' => (string)($b['branch'] ?? ''),
                'release_candidate' => (string)($b['release_candidate'] ?? ''),
            ];
        }
    }

    // Sibling plans of this project for "copy to all test plans" info.
    $siblingPlans = 0;
    $tplanset = $tplanMgr->tproject_mgr->get_all_testplans($ctx['tproject_id']);
    if (!is_null($tplanset)) {
        foreach ($tplanset as $pid => $pinfo) {
            if (intval($pid) !== intval($tplanId)
                && isset($pinfo['active']) && intval($pinfo['active'])) {
                $siblingPlans++;
            }
        }
    }

    // Localized execution statuses for the "copy with exec status" filter
    // (legacy initializeGui(): results config + lang_get()).
    $resultsCfg = config_get('results');
    $execStatusOptions = [];
    foreach ($resultsCfg['status_label_for_exec_ui'] as $kv => $vl) {
        $execStatusOptions[] = [
            'code' => $resultsCfg['status_code'][$kv],
            'label' => lang_get($vl),
        ];
    }

    out([
        'status' => 'ok',
        'tplan' => ['id' => $tplanId, 'name' => $ctx['tplan_name'],
                    'tproject_id' => $ctx['tproject_id']],
        'tproject_name' => tprojectName($tplanMgr, $ctx['tproject_id']),
        'builds' => $items,
        'source_builds' => $srcItems,
        'exec_status_options' => $execStatusOptions,
        'other_plans_count' => $siblingPlans,
        'rights' => [
            'canManage' => true,
            // Legacy buildEdit.tpl:53 gated the "Show event history" button on
            // mgt_view_events, which api/eventviewer also enforces on every
            // read. The flag was missing from this payload, so the button
            // could never be offered.
            'canViewEvents' => (bool)$user->hasRight($db, 'mgt_view_events'),
            'canDeleteExec' => canDeleteExec($user, $db, $ctx['tproject_id']),
        ],
    ]);
}

// GET /{id} -> single build (edit modal prefill)
if ($method === 'GET' && count($segments) === 1 && ctype_digit($segments[0])) {
    $b = $buildMgr->get_by_id(intval($segments[0]));
    if (!$b) { // build::get_by_id returns bool(false) when missing
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Build not found']);
    }
    $ctx = resolveBuild($db, $b);
    assertBuildInTplan($db, $b, $_GET['tplan_id'] ?? 0);
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    out([
        'status' => 'ok',
        'build' => [
            'id' => intval($b['id']),
            'tproject_id' => intval($b['testproject_id']),
            'name' => $b['name'],
            'notes' => (string)$b['notes'],
            'release_date' => (isset($b['release_date']) && $b['release_date'])
                ? substr($b['release_date'], 0, 10) : '',
            'closed_on_date' => (isset($b['closed_on_date']) && $b['closed_on_date'])
                ? substr($b['closed_on_date'], 0, 10) : '',
            'active' => intval($b['active']),
            'is_open' => intval($b['is_open']),
            'commit_id' => (string)($b['commit_id'] ?? ''),
            'tag' => (string)($b['tag'] ?? ''),
            'branch' => (string)($b['branch'] ?? ''),
            'release_candidate' => (string)($b['release_candidate'] ?? ''),
        ],
    ]);
}

// GET /cfields?tplan_id=N[&build_id=M]  -> build design custom fields
// The Build Create/Edit form needs the project's build design custom fields
// as DATA (legacy buildEdit.tpl rendered html_custom_field_inputs()). The
// owning project is proven BEFORE the build is resolved, so a foreign or
// bogus tplan_id cannot be used as an existence oracle, and a build_id that
// does not belong to the addressed project is a 404, never a silent read of
// another project's values.
if ($method === 'GET' && count($segments) === 1 && $segments[0] === 'cfields') {
    $tplanId = intval($_GET['tplan_id'] ?? 0);
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id',
             'error_code' => 'no_tplan']);
    }
    $ctx = resolveTplan($db, $tplanId);
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights',
             'error_code' => 'no_right']);
    }
    $buildId = intval($_GET['build_id'] ?? 0);
    if ($buildId > 0) {
        $b = $buildMgr->get_by_id($buildId);
        if (!$b) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Build not found',
                 'error_code' => 'build_not_found']);
        }
        $bctx = resolveBuild($db, $b);
        if (intval($bctx['tproject_id']) !== intval($ctx['tproject_id'])) {
            http_response_code(404);
            out(['status' => 'error', 'message' => 'Build not found',
                 'error_code' => 'build_not_found']);
        }
        $buildId = intval($b['id']);
    }
    out([
        'status' => 'ok',
        'cfields' => buildCfieldDefs($buildMgr, $ctx['tproject_id'], $buildId),
    ]);
}

/* ------------------------------------------------------------------ */
/* POST routes                                                         */
/* ------------------------------------------------------------------ */
// POST /  -> create (legacy do_create incl. copy options)
if ($method === 'POST' && count($segments) === 0) {
    $body = getBody();
    $tplanId = intval($body['tplan_id'] ?? 0);
    if ($tplanId <= 0) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid test plan id']);
    }
    $ctx = resolveTplan($db, $tplanId);
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    $tp = $ctx['tplan_mgr'];

    $name = strField($body, 'name');
    if (is_null($name)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'empty_field_no', 'field' => 'name']);
    }
    [$rdate, $err] = isoDateOrNull($body);
    if ($err) {
        http_response_code(400);
        out(['status' => 'error', 'message' => $err]);
    }
    // Legacy crossChecks: duplicate name inside THIS test plan.
    if ($tp->check_build_name_existence($tplanId, $name, null)) {
        http_response_code(409);
        out(['status' => 'error', 'message' => 'warning_duplicate_build', 'detail' => $name]);
    }

    $isActive = empty($body['active']) ? 0 : 1;
    $isOpen = empty($body['open']) ? 0 : 1;

    // Validate the copy-assignment source build BEFORE creating anything:
    // legacy doCreate() creates first and silently leaves an orphan build if
    // the copy target is bad; evaluate source up-front so the new build is
    // not persisted when the request is rejected.
    $copyAssign = !empty($body['copy_tester_assignments']);
    $sourceBuildId = intval($body['source_build_id'] ?? 0);
    if ($copyAssign && $sourceBuildId > 0) {
        $src = $buildMgr->get_by_id($sourceBuildId);
        if (!$src || intval($src['testproject_id']) !== $ctx['tproject_id']) {
            http_response_code(400);
            out(['status' => 'error', 'message' => 'Invalid source build']);
        }
    }

    $oBuild = new stdClass();
    $oBuild->name = $name;
    $oBuild->tplan_id = $tplanId;
    $oBuild->release_date = $rdate;
    $oBuild->notes = isset($body['notes']) ? (string)$body['notes'] : '';
    $oBuild->commit_id = strField($body, 'commit_id');
    $oBuild->tag = strField($body, 'tag');
    $oBuild->branch = strField($body, 'branch');
    $oBuild->release_candidate = strField($body, 'release_candidate');
    $oBuild->is_active = $isActive;
    $oBuild->is_open = $isOpen;
    try {
        $buildID = $buildMgr->createFromObject($oBuild);
    } catch (Exception $ex) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'cannot_add_build']);
    }

    if (!$buildID) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'cannot_add_build']);
    }

    // Legacy do_create: design custom fields are written right after the
    // build row exists and BEFORE closed_on_date is stamped.
    $cfMap = $buildMgr->get_linked_cfields_at_design($buildID, $ctx['tproject_id']);
    $cfWritten = saveBuildCfields($buildMgr, $body, $cfMap, $buildID);

    // Legacy do_create: closing a build stamps closed_on_date.
    if (!$isOpen) {
        $buildMgr->setClosedOnDate($buildID, date('Y-m-d'));
    }

    // Copy tester assignments from source build (legacy behavior).
    if ($copyAssign && $sourceBuildId > 0) {
        $statusFilter = isset($body['exec_status_filter']) &&
                        is_array($body['exec_status_filter'])
            ? array_map('strval', $body['exec_status_filter']) : null;
        copyTesterAssignments($tp, $tplanId, $sourceBuildId, $buildID,
                              intval($userId), $statusFilter);
    }

    // Copy to all other active plans of this project (legacy doCopyToTestPlans).
    if (!empty($body['copy_to_all_tplans'])) {
        doCopyToTestPlans($tp, $db, $ctx, $name, (string)$oBuild->notes,
                          $isActive, $isOpen);
    }

    logAuditEvent(TLS('audit_build_created',
        tprojectName($tp, $ctx['tproject_id']), $ctx['tproject_name'], $name),
        'CREATE', $buildID, 'builds');

    out(['status' => 'ok', 'id' => intval($buildID), 'cfields_written' => $cfWritten]);
}

// POST /{id}/flags -> active/open toggles (legacy setActive/setInactive/open/close)
if ($method === 'POST' && count($segments) === 2 && ctype_digit($segments[0])
    && $segments[1] === 'flags') {
    $buildId = intval($segments[0]);
    $body = getBody();
    $b = $buildMgr->get_by_id($buildId);
    if (!$b) { // build::get_by_id returns bool(false) when missing
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Build not found']);
    }
    $ctx = resolveBuild($db, $b);
    // BODY first: buildsView.html sends tplan_id inside the JSON body for this
    // route (as it does for PUT), so a query-only read left the Active/Open
    // toggles of the table unprotected. The query is kept as the legacy-form
    // fallback; the check itself must never be side-stepped by picking one.
    assertBuildInTplan($db, $b, $body['tplan_id'] ?? ($_GET['tplan_id'] ?? 0));
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    if (!array_key_exists('active', $body) && !array_key_exists('open', $body)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Nothing to change']);
    }
    if (array_key_exists('active', $body)) {
        if ((bool)$body['active']) { $buildMgr->setActive($buildId); }
        else { $buildMgr->setInactive($buildId); }
    }
    if (array_key_exists('open', $body)) {
        // stamp/clear closure date only on real transitions (legacy parity)
        $wasOpen = intval($b['is_open']) === 1;
        $nowOpen = (bool)$body['open'];
        if ($nowOpen) {
            $buildMgr->setOpen($buildId);
            if (!$wasOpen) { $buildMgr->setClosedOnDate($buildId, null); }
        } else {
            $buildMgr->setClosed($buildId);
            if ($wasOpen) { $buildMgr->setClosedOnDate($buildId, date('Y-m-d')); }
        }
    }
    out(['status' => 'ok']);
}

/**
 * Copy tester assignments source->new build.
 * Mirrors legacy doCreate(): full copy when no status filter, otherwise
 * per-platform hits filtered by exec status.
 */
function copyTesterAssignments(&$tp, $tplanId, $srcId, $dstId, $userId, $statusFilter) {
    if (is_null($statusFilter) || count($statusFilter) === 0) {
        $tp->assignment_mgr->copy_assignments($srcId, $dstId, $userId);
        return;
    }
    $resultsCfg = config_get('results');
    $execVerboseDomain = array_flip($resultsCfg['status_code']);

    $getOpt = array('outputFormat' => 'mapAccessByID', 'addIfNull' => true,
                    'outputDetails' => 'name');
    $platformSet = $tp->getPlatforms($tplanId, $getOpt);
    $caOpt = array();
    $caOpt['keep_old_assignments'] = true;
    foreach ($platformSet as $platform_id => $pname) {
        $glf = array('filters' => array('platform_id' => $platform_id));
        foreach ($statusFilter as $ec) {
            // ignore unknown status codes silently (avoid E_WARNING noise)
            if (!isset($execVerboseDomain[$ec])) { continue; }
            if ($execVerboseDomain[$ec] === 'not_run') {
                $tcaseSet = $tp->getHitsNotRunForBuildAndPlatform(
                    $tplanId, $platform_id, $srcId);
            } else {
                $tcaseSet = $tp->getHitsSingleStatusFull(
                    $tplanId, $platform_id, $ec, array($srcId));
            }
            if (!is_null($tcaseSet)) {
                $targetSet = array_keys($tcaseSet);
                $features = $tp->getLinkedFeatures($tplanId, $glf['filters']);
                $caOpt['feature_set'] = null;
                foreach ($targetSet as $tcase_id) {
                    if (isset($features[$tcase_id][$platform_id]['feature_id'])) {
                        $caOpt['feature_set'][] =
                            $features[$tcase_id][$platform_id]['feature_id'];
                    }
                }
                if (!empty($caOpt['feature_set'])) {
                    $tp->assignment_mgr->copy_assignments($srcId, $dstId, $userId, $caOpt);
                }
            }
        }
    }
}

/**
 * Create same-named build in every other active plan of the project when the
 * name is free there (legacy doCopyToTestPlans()).
 */
function doCopyToTestPlans(&$tp, &$db, $ctx, $name, $notes, $active, $open) {
    $filters = array('tplan2exclude' => $ctx['tplan_id']);
    $tplanset = $tp->tproject_mgr->get_all_testplans($ctx['tproject_id'], $filters);
    if (!is_null($tplanset)) {
        $bm = new build($db);
        foreach ($tplanset as $pid => $info) {
            if (isset($info['active']) && !intval($info['active'])) {
                continue;
            }
            if (!$tp->check_build_name_existence(intval($pid), $name)) {
                $bm->create(intval($pid), $name, $notes, $active, $open);
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* PUT route - update (legacy do_update)                               */
/* ------------------------------------------------------------------ */
if ($method === 'PUT' && count($segments) === 1 && ctype_digit($segments[0])) {
    $buildId = intval($segments[0]);
    $body = getBody();
    $b = $buildMgr->get_by_id($buildId);
    if (!$b) { // build::get_by_id returns bool(false) when missing
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Build not found']);
    }
    $ctx = resolveBuild($db, $b);
    // PUT addresses the plan in the JSON body (the legacy form posted it),
    // so the scope check reads the body first and falls back to the query.
    assertBuildInTplan($db, $b, $body['tplan_id'] ?? ($_GET['tplan_id'] ?? 0));
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }
    $tp = new testplan($db);
    $ctx['tplan_mgr'] = $tp;

    $name = strField($body, 'name');
    if (is_null($name)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'empty_field_no', 'field' => 'name']);
    }
    [$rdate, $err] = isoDateOrNull($body);
    if ($err) {
        http_response_code(400);
        out(['status' => 'error', 'message' => $err]);
    }
    // Legacy crossChecks: duplicate name inside THIS project, excluding self.
    // build::checkNameExistence() returns a status ARRAY (['status_ok']), not a
    // bool - testing the array directly is always truthy and would 409 every rename.
    $chk = $buildMgr->checkNameExistence($ctx['tproject_id'], $name, $buildId);
    if (!$chk['status_ok']) {
        http_response_code(409);
        out(['status' => 'error', 'message' => 'warning_duplicate_build', 'detail' => $name]);
    }

    $attr = array(
        'release_date' => $rdate,
        'release_candidate' => strField($body, 'release_candidate'),
        'is_active' => empty($body['active']) ? 0 : 1,
        'is_open' => empty($body['open']) ? 0 : 1,
        'commit_id' => strField($body, 'commit_id'),
        'tag' => strField($body, 'tag'),
        'branch' => strField($body, 'branch'),
    );
    $notes = isset($body['notes']) ? (string)$body['notes'] : '';
    if (!$buildMgr->update($buildId, $name, $notes, $attr)) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'cannot_update_build']);
    }

    // Legacy do_update: design custom fields are persisted after build::update()
    // and before closed_on_date is re-stamped.
    $cfMap = $buildMgr->get_linked_cfields_at_design($buildId, $ctx['tproject_id']);
    $cfWritten = saveBuildCfields($buildMgr, $body, $cfMap, $buildId);

    // Legacy do_update semantics: build::update() unconditionally resets
    // closed_on_date to NULL (latent behavior of the class), so we must
    // restore/adjust afterwards:
    //   open->closed : stamp today
    //   closed->open : clear
    //   still closed : preserve the historical closure date
    $wasOpen = intval($b['is_open']) === 1;
    $nowOpen = !empty($attr['is_open']);
    if ($wasOpen && !$nowOpen) {
        $buildMgr->setClosedOnDate($buildId, date('Y-m-d'));
    } elseif (!$wasOpen && $nowOpen) {
        $buildMgr->setClosedOnDate($buildId, null);
    } elseif (!$nowOpen) {
        $hist = isset($b['closed_on_date']) ? substr((string)$b['closed_on_date'], 0, 10) : '';
        $buildMgr->setClosedOnDate($buildId, ($hist !== '') ? $hist : null);
    }

    logAuditEvent(TLS('audit_build_saved',
        tprojectName($tp, $ctx['tproject_id']), $ctx['tproject_name'], $name),
        'SAVE', $buildId, 'builds');

    out(['status' => 'ok', 'cfields_written' => $cfWritten]);
}

/* ------------------------------------------------------------------ */
/* DELETE route - delete (legacy do_delete)                            */
/* ------------------------------------------------------------------ */
if ($method === 'DELETE' && count($segments) === 1 && ctype_digit($segments[0])) {
    $buildId = intval($segments[0]);
    $b = $buildMgr->get_by_id($buildId);
    if (!$b) { // build::get_by_id returns bool(false) when missing
        http_response_code(404);
        out(['status' => 'error', 'message' => 'Build not found']);
    }
    $ctx = resolveBuild($db, $b);
    assertBuildInTplan($db, $b, $_GET['tplan_id'] ?? 0);
    if (!canManage($user, $db, $ctx['tproject_id'])) {
        http_response_code(403);
        out(['status' => 'error', 'message' => 'Insufficient rights']);
    }

    // Legacy doDelete(): executions on this build require exec_delete right.
    // Builds are project-scoped (issue #503), so count executions across all
    // plans of the project.
    $buildTables = tlObjectWithDB::getDBTables(array('executions', 'testplans'));
    $qry = "SELECT COUNT(0) AS qty FROM {$buildTables['executions']} E " .
           "JOIN {$buildTables['testplans']} TP ON TP.id = E.testplan_id " .
           "WHERE TP.testproject_id = {$ctx['tproject_id']} " .
           "AND E.build_id = {$buildId}";
    $rsq = $db->get_recordset($qry);
    $qty = $rsq[0]['qty'] ?? 0;
    if ($qty > 0 && !canDeleteExec($user, $db, $ctx['tproject_id'])) {
        http_response_code(409);
        out(['status' => 'error', 'message' => 'cannot_delete_build_no_exec_delete',
             'detail' => $b['name']]);
    }
    if (!$buildMgr->delete($buildId)) {
        http_response_code(500);
        out(['status' => 'error', 'message' => 'cannot_delete_build']);
    }
    logAuditEvent(TLS('audit_build_deleted',
        tprojectName($tplanMgr, $ctx['tproject_id']), $ctx['tproject_name'], $b['name']),
        'DELETE', $buildId, 'builds');

    out(['status' => 'ok']);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Unknown route']);
