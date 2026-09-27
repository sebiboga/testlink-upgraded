<?php
// Repro / regression harness for issue #1606:
//   tree::getNodeTable() - the id-keyed node_tables_by['id'] map has no entry
//   for the two tableless PSEUDO node types testcase_step (9) and build (12),
//   so the 5 raw readers raised one PHP 8 E_WARNING "Undefined array key 9"
//   per testcase_step row, which watchPHPErrors turns into an Event Viewer row.
//
// Usage:  php tmp/repro_1606.php
// Expect: 6/6 cases warning-free  (pre-fix: S1 + S2 raise 1 warning per step row)
//          resolver unit table 13/13 correct
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$fx = json_decode(file_get_contents('tmp/fixture_1606.json'), true);
if (!$fx) {
    die("run: php tmp/fixtures_1606.php first\n");
}

$tprojMgr = new testproject($db);
$tplanMgr = new testplan($db);

function run_case($label, $cb)
{
    $warnings = array();
    set_error_handler(function ($no, $str, $file, $line) use (&$warnings) {
        $warnings[] = "$str in " . basename($file) . " - Line $line";
        return true;
    });
    try {
        $data = $cb();
    } catch (Throwable $e) {
        $data = 'THROWN: ' . $e->getMessage();
    }
    restore_error_handler();
    $n = count($warnings);
    printf("%s  %-58s warnings=%d\n", $n === 0 ? 'PASS' : 'FAIL', $label, $n);
    foreach ($warnings as $w) {
        echo "        $w\n";
    }
    return array($n, $data);
}

$tree = new tree($db);
$res = array();
$res['S1'] = run_case('S1 get_children() on tcversion node (raw id read)', function () use ($tree, $fx) {
    return $tree->get_children($fx['idV1']);
});
$res['S2'] = run_case('S2 get_subtree(recursive) (_get_subtree_rec)', function () use ($tree, $fx) {
    return $tree->get_subtree($fx['idS1'], null, array('recursive' => true));
});
$res['S3'] = run_case('S3 get_path(format=full) (_get_path)', function () use ($tree, $fx) {
    return $tree->get_path($fx['idV1'], 'full');
});
$res['S4'] = run_case('S4 testproject::getTestSpec(recursive)', function () use ($tprojMgr, $fx) {
    return $tprojMgr->getTestSpec($fx['idP'], null, array('recursive' => true));
});
$res['S5'] = run_case('S5 testplan::getSkeleton(recursive)', function () use ($tplanMgr, $tprojMgr, $fx) {
    return $tplanMgr->getSkeleton($fx['idTP'], $fx['idP'], null, array('recursive' => true));
});
$res['S6'] = run_case('S6 CONTROL get_subtree(non-recursive, essential)', function () use ($tree, $fx) {
    return $tree->get_subtree($fx['idS1'], null, array('essential' => true));
});

$total = 0;
foreach ($res as $n => $r) {
    $total += $r[0];
}
printf("SUMMARY: %d/6 cases warning-free  (total warnings: %d)\n", 6 - count(array_filter(array_map(function ($r) { return $r[0] > 0 ? 1 : 0; }, $res))), $total);

// ------------------------------------------------- resolver unit table -----
$expect = array(
    1 => 'testprojects', 2 => 'testsuites', 3 => 'testcases', 4 => 'tcversions',
    5 => 'testplans', 6 => 'req_specs', 7 => 'requirements', 8 => 'req_versions',
    10 => 'req_versions', 11 => 'req_specs_revisions',
    9 => null, 12 => null, 99 => null,
);
$bad = 0;
echo "\n-- getNodeTable() unit table --\n";
foreach ($expect as $id => $want) {
    $got = $tree->getNodeTable($id);
    $ok = $got === $want;
    if (!$ok) {
        $bad++;
    }
    printf("%s  node_type_id %-3s => %s\n", $ok ? 'ok  ' : 'FAIL', $id, var_export($got, true));
}
printf("resolver failures: %d\n", $bad);

// -------------------------------------------------------- data snapshot ----
$snap = array();
foreach ($res as $k => $r) {
    $snap[$k] = $r[1];
}
file_put_contents('tmp/snapshot_1606.json', json_encode($snap, JSON_PRETTY_PRINT));
echo "data snapshot -> tmp/snapshot_1606.json\n";

echo "\nRESULT: " . (($total === 0 && $bad === 0) ? "ALL GREEN\n" : "DEFECT PRESENT\n");
exit(($total === 0 && $bad === 0) ? 0 : 1);
