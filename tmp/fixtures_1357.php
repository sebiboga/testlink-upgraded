<?php
// Fixture for issue #1357 browser testing: reqSpecCompare log-message hover tooltip.
// Creates test project `RSCMP1357` (prefix RSC) + requirement spec `RS-CMP`
// with four revisions whose log messages exercise every state:
//   rev1 (auto, "Req spec created" legacy automatic log)
//   rev2 short log "Fixed typo in scope"
//   rev3 LONG log (> req_spec_cfg->log_message_len 200 chars) -> truncated cell + full tooltip
//   rev4 EMPTY log "" -> legacy empty_log_message placeholder
// Run from repo root: php tmp/fixtures_1357.php
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tprojMgr = new testproject($db);
$reqSpecMgr = new requirement_spec_mgr($db);
$userId = 1;

// Reset on re-runs (repo rule: fixtures must be re-runnable).
foreach ((array)$tprojMgr->get_by_name('RSCMP1357') as $row) {
    $oid = intval(is_array($row) ? ($row['id'] ?? 0) : $row);
    if ($oid > 0) {
        echo "deleting old project $oid\n";
        $tprojMgr->delete($oid, 1);
    }
}

$item = new stdClass();
$item->name = 'RSCMP1357';
$item->prefix = 'RSC';
$item->notes = 'fixture for issue 1357 (reqSpecCompare log-message hover tooltip)';
$item->color = '';
$item->active = 1;
$item->is_public = 1;
$opts = new stdClass();
$opts->requirementsEnabled = 1;
$opts->testPriorityEnabled = 0;
$opts->automationEnabled = 0;
$opts->inventoryEnabled = 0;
$opts->platformsEnabled = 0;
$item->options = $opts;
$r = $tprojMgr->create($item);
$idP = intval($r);
echo "tproject=$idP\n";
$tprojMgr->setActive($idP);

$op = $reqSpecMgr->create($idP, $idP, 'RS-CMP', 'Compare Tooltip Spec',
    'spec used by suite #1357', 0, $userId, TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
if (!$op['status_ok'] || $op['id'] <= 0) { die("spec create failed: " . $op['msg'] . "\n"); }
$idS = intval($op['id']);
echo "spec=$idS\n";

// rev2 - short log
$longLog = "Reworked the scope paragraph ordering and fixed the typo in the "
    . "introduction. Also added the missing comma list separators and aligned "
    . "the bullet indentation with the house style guide. Reviewers asked for "
    . "clearer wording on the acceptance criteria, so this revision rewrites "
    . "sections 2 and 3 entirely while keeping the functional scope unchanged. "
    . "The trailing note about data migration remains as-is until the QA pass.";
$op = $reqSpecMgr->clone_revision($idS, array(
    'log_message' => 'Fixed typo in scope',
    'author_id'   => $userId,
));
if (!$op['status_ok']) { die("rev2 create failed: " . $op['msg'] . "\n"); }
echo "rev2=" . intval($op['id']) . "\n";

// rev3 - long log (> 200 chars) exercises truncation + full tooltip
$op = $reqSpecMgr->clone_revision($idS, array(
    'log_message' => $longLog,
    'author_id'   => $userId,
));
if (!$op['status_ok']) { die("rev3 create failed: " . $op['msg'] . "\n"); }
echo "rev3=" . intval($op['id']) . "\n";

// rev4 - empty log exercises the legacy empty_log_message placeholder
$op = $reqSpecMgr->clone_revision($idS, array(
    'log_message' => '',
    'author_id'   => $userId,
));
if (!$op['status_ok']) { die("rev4 create failed: " . $op['msg'] . "\n"); }
echo "rev4=" . intval($op['id']) . "\n";

file_put_contents('/tmp/fixture_1357.txt', json_encode(array(
    'tproject_id' => $idP,
    'spec_id'     => $idS,
)));
echo "wrote /tmp/fixture_1357.txt\n";