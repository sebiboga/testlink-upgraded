<?php
/**
 * Keywords Assignment BFF API
 * URL: /api/keywords/assign.php
 * Plain PHP, no framework, no compilation
 *
 * Mirrors lib/keywords/keywordsAssign.php (TestLink 1.9.20 behavior):
 * option-transfer based keyword assignment to a single test case
 * (replace semantics on latest active version) or to every test case
 * inside a test suite subtree / direct children (add/remove/removeAll),
 * with the executed-version guard driven by
 * testproject_add_remove_keywords_executed_tcversions /
 * testproject_edit_executed_testcases rights.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

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

function kwaHasRight($db, $user, $right)
{
    return $user->hasRightOnProj($db, $right) ? true : false;
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^.*assign\.php#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }
}

$tprojectMgr = new testproject($db);
$tcaseMgr = new testcase($db);

try {
    // /context stays open for everyone: it drives the canAssign flag that the
    // screen uses to render the no-rights note. All data + mutation routes
    // (suites/tcases/keywords/case/suite) require keyword_assignment, matching
    // legacy testlinkInitPage(...,"checkRights") (Refs #1110).
    if ($path !== '/context' && !kwaHasRight($db, $user, 'keyword_assignment')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Insufficient rights']);
        exit;
    }

    // ------------------------------------------------------------------
    // GET /context
    // ------------------------------------------------------------------
    if ($method === 'GET' && $path === '/context') {
        $sid = intval($_SESSION['testprojectID'] ?? 0);
        $qid = intval($_GET['tproject_id'] ?? 0);
        $tproject_id = $qid > 0 ? $qid : $sid;

        $name = '';
        $hasKeywords = false;
        if ($tproject_id > 0) {
            $info = $tprojectMgr->get_by_id($tproject_id);
            if (!is_null($info)) {
                $name = $info['name'];
                $hasKeywords = true;
            } else {
                $tproject_id = 0;
            }
        }

        echo json_encode([
            'status' => 'ok',
            'tproject_id' => $tproject_id,
            'tproject_name' => $name,
            'canAssign' => kwaHasRight($db, $user, 'keyword_assignment'),
            'canEditExecuted' => kwaHasRight($db, $user,
                'testproject_add_remove_keywords_executed_tcversions')
                || kwaHasRight($db, $user, 'testproject_edit_executed_testcases'),
            'hasKeywordsFeature' => $hasKeywords,
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // GET /suites : flat list of test suites of the project
    // ------------------------------------------------------------------
    if ($method === 'GET' && $path === '/suites') {
        $sid = intval($_SESSION['testprojectID'] ?? 0);
        $tproject_id = intval($_GET['tproject_id'] ?? 0) ?: $sid;

        $out = [];
        if ($tproject_id > 0) {
            $map = $tprojectMgr->gen_combo_test_suites($tproject_id);
            if (!is_null($map)) {
                foreach ($map as $tsid => $label) {
                    $out[] = ['id' => intval($tsid), 'name' => is_array($label) ? '' : $label];
                }
            }
        }

        usort($out, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        echo json_encode(['status' => 'ok', 'suites' => $out]);
        exit;
    }

    // ------------------------------------------------------------------
    // GET /tcases?tsuite_id=X&scope=deep|direct : test cases under a suite
    // ------------------------------------------------------------------
    if ($method === 'GET' && $path === '/tcases') {
        $tsuite_id = intval($_GET['tsuite_id'] ?? 0);
        $scope = ($_GET['scope'] ?? 'deep') === 'direct' ? 'direct' : 'deep';

        $ids = [];
        if ($tsuite_id > 0) {
            $tsuiteMgr = new testsuite($db);
            if ($scope === 'direct') {
                $ids = $tsuiteMgr->get_children_testcases($tsuite_id, 'only_id');
            } else {
                $ids = $tsuiteMgr->get_testcases_deep($tsuite_id, 'only_id');
            }
        }

        $out = [];
        if (!empty($ids)) {
            foreach ($ids as $tcid) {
                $nm = $tcaseMgr->getName(intval($tcid));
                $out[] = ['id' => intval($tcid), 'name' => $nm];
            }
            usort($out, function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
        }

        echo json_encode(['status' => 'ok', 'tcases' => $out]);
        exit;
    }

    // ------------------------------------------------------------------
    // GET /keywords?tcase_id=X :
    //   available (all project keywords) + assigned on latest active version
    // ------------------------------------------------------------------
    if ($method === 'GET' && $path === '/keywords') {
        $sid = intval($_SESSION['testprojectID'] ?? 0);
        $tproject_id = intval($_GET['tproject_id'] ?? 0) ?: $sid;
        $tcase_id = intval($_GET['tcase_id'] ?? 0);

        $availMap = $tprojectMgr->get_keywords_map($tproject_id);

        $assigned = [];
        $hasBeenExecuted = false;
        $latestVersion = null;
        if ($tcase_id > 0) {
            $glOpt = ['output' => 'thin', 'active' => 1];
            $ltcv = $tcaseMgr->get_last_version_info($tcase_id, $glOpt);
            if (!is_null($ltcv) && isset($ltcv['tcversion_id'])) {
                $latestVersion = $ltcv;
                $statusQuo = current(
                    $tcaseMgr->get_versions_status_quo($tcase_id, $ltcv['tcversion_id']));
                if ($statusQuo === false) { $statusQuo = []; }
                $hasBeenExecuted = intval($statusQuo['executed'] ?? 0) > 0;

                $amap = $tcaseMgr->get_keywords_map($tcase_id, $ltcv['tcversion_id'],
                    ['orderByClause' => ' ORDER BY keyword ASC ']);
                if (!is_null($amap)) {
                    foreach ($amap as $kwid => $kw) {
                        $assigned[] = [
                            'id' => intval($kwid),
                            'kw' => is_array($kw) ? ($kw['keyword'] ?? '') : $kw,
                        ];
                    }
                }
            }
        }

        $available = [];
        if (!is_null($availMap)) {
            foreach ($availMap as $kwid => $kw) {
                $available[] = [
                    'id' => intval($kwid),
                    'kw' => is_array($kw) ? ($kw['keyword'] ?? '') : $kw,
                ];
            }
            usort($available, function ($a, $b) {
                return strcasecmp($a['kw'], $b['kw']);
            });
        }

        echo json_encode([
            'status' => 'ok',
            'available' => $available,
            'assigned' => $assigned,
            'hasBeenExecuted' => $hasBeenExecuted,
            'latest_active_version' => $latestVersion !== null
                ? intval($latestVersion['version'] ?? 0) : null,
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // POST /case  {tcase_id, keywords:[ids]} -> REPLACE set (setKeywords)
    // ------------------------------------------------------------------
    if ($method === 'POST' && $path === '/case') {
        $sid = intval($_SESSION['testprojectID'] ?? 0);
        $tproject_id = intval($body['tproject_id'] ?? 0) ?: $sid;
        $tcase_id = intval($body['tcase_id'] ?? 0);
        $kws = array_values(array_filter(array_map('intval', $body['keywords'] ?? [])));

        if ($tcase_id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing tcase_id']);
            exit;
        }

        $glOpt = ['output' => 'thin', 'active' => 1];
        $ltcv = $tcaseMgr->get_last_version_info($tcase_id, $glOpt);
        if (is_null($ltcv) || !isset($ltcv['tcversion_id'])) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Test case not found']);
            exit;
        }

        $statusQuo = current($tcaseMgr->get_versions_status_quo($tcase_id, $ltcv['tcversion_id']));
        if ($statusQuo === false) { $statusQuo = []; }
        $hasBeenExecuted = intval($statusQuo['executed'] ?? 0) > 0;
        $canEditExecuted = kwaHasRight($db, $user,
            'testproject_add_remove_keywords_executed_tcversions')
            || kwaHasRight($db, $user, 'testproject_edit_executed_testcases');

        if ($hasBeenExecuted && !$canEditExecuted) {
            echo json_encode([
                'status' => 'blocked',
                'reason' => 'executed',
                'message' => 'Keyword assignment blocked: latest version has been executed'
                    . ' and you lack the required right',
            ]);
            exit;
        }

        $tcaseMgr->setKeywords($tcase_id, $ltcv['tcversion_id'], $kws);

        echo json_encode([
            'status' => 'ok',
            'result' => 'updated',
            'assigned' => array_map('intval', $kws),
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // POST /suite {tsuite_id, scope:deep|direct,
    //              mode:add|remove|removeall, keywords:[ids]}
    // ------------------------------------------------------------------
    if ($method === 'POST' && $path === '/suite') {
        $sid = intval($_SESSION['testprojectID'] ?? 0);
        $tproject_id = intval($body['tproject_id'] ?? 0) ?: $sid;
        $tsuite_id = intval($body['tsuite_id'] ?? 0);
        $scope = (($body['scope'] ?? 'deep') === 'direct') ? 'direct' : 'deep';
        $mode = $body['mode'] ?? '';
        $kws = array_values(array_filter(array_map('intval', $body['keywords'] ?? [])));

        if ($tsuite_id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing tsuite_id']);
            exit;
        }

        $method2do = null;
        if ($mode === 'add' && count($kws) > 0) {
            $method2do = 'addKeywords';
        } elseif ($mode === 'remove' && count($kws) > 0) {
            $method2do = 'deleteKeywords';
        } elseif ($mode === 'removeall') {
            $method2do = 'deleteKeywords';
            $kws = null;
        }

        if (is_null($method2do)) {
            http_response_code(400);
            echo json_encode(['status' => 'error',
                'message' => 'Invalid mode/keyword combination']);
            exit;
        }

        $tsuiteMgr = new testsuite($db);
        if ($scope === 'direct') {
            $tcs = $tsuiteMgr->get_children_testcases($tsuite_id, 'only_id');
        } else {
            $tcs = $tsuiteMgr->get_testcases_deep($tsuite_id, 'only_id');
        }

        if (empty($tcs)) {
            echo json_encode(['status' => 'empty',
                'reason' => 'no_test_cases',
                'message' => 'There are no Test Cases in this Test Suite'
                    . ' => keyword assignment is not possible']);
            exit;
        }

        $canEditExecuted = kwaHasRight($db, $user,
            'testproject_add_remove_keywords_executed_tcversions')
            || kwaHasRight($db, $user, 'testproject_edit_executed_testcases');

        $updated = 0;
        $skipped = 0;
        if (!empty($tcs)) {
            $glOpt = ['output' => 'thin', 'active' => 1];
            foreach ($tcs as $tcid) {
                $tcid = intval($tcid);
                $ltcv = $tcaseMgr->get_last_version_info($tcid, $glOpt);
                if (is_null($ltcv) || !isset($ltcv['tcversion_id'])) {
                    continue;
                }
                $latestActiveVersionID = $ltcv['tcversion_id'];
                $statusQuo = current(
                    $tcaseMgr->get_versions_status_quo($tcid, $latestActiveVersionID));
                if ($statusQuo === false) { $statusQuo = []; }
                $execd = intval($statusQuo['executed'] ?? 0) > 0;

                if (!$canEditExecuted && $execd) {
                    $skipped++;
                    continue;
                }
                $tcaseMgr->$method2do($tcid, $latestActiveVersionID, $kws);
                $updated++;
            }
        }

        echo json_encode([
            'status' => 'ok',
            'result' => 'updated',
            'updated' => $updated,
            'skipped_executed' => $skipped,
        ]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Unknown endpoint']);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
