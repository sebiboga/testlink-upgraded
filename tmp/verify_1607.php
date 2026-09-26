<?php
// Regression verification for issue #1607 -- the matrix rows 5-7 of the
// ROOT CAUSE comment (rows 1-4 are tmp/repro_1607.php).
//
//   php tmp/verify_1607.php
//
// Seeds the missing testplan_tcversions rows (matrix row 6), then exercises:
//   5. get_subtree(<suite>, null, ['recursive'=>true])         -> suite subtree
//   6. recursive with order_cfg exec_order + tplan_id          -> SQL executes,
//                                                                    no
//                                                                    "Undefined
//                                                                    array key"
//   7. TWO different order_cfg recursive calls in ONE process   -> each uses
//                                                                    its own
require_once('config.inc.php');
require_once('common.php');

function q($sql)
{
    return trim((string)shell_exec("mysql -h 127.0.0.1 -utestlink -ptestlink -N -B testlink -e " . escapeshellarg($sql)));
}

$idP = intval(q("SELECT nh.id FROM nodes_hierarchy nh JOIN testprojects tp ON nh.id=tp.id WHERE tp.prefix='TQ1'"));
if (!$idP) {
    die("fixture missing - run: php tmp/fixtures_1607.php\n");
}
$idS1 = intval(q("SELECT id FROM nodes_hierarchy WHERE node_type_id=2 AND name='TQ1607-S1'"));
$idTP = intval(q("SELECT tp.id FROM testplans tp JOIN testprojects p ON tp.testproject_id=p.id WHERE p.prefix='TQ1'"));
if (!$idTP) {
    die("no test plan - run: php tmp/fixtures_1607.php\n");
}

// ---------------------------------------------------- seed the plan links ---
// direct SQL: the legacy link_tcversions() needs the full platform/items
// argument structure, which is irrelevant to the tree traversal under test.
$linked = intval(q("SELECT COUNT(*) FROM testplan_tcversions WHERE testplan_id=$idTP"));
if ($linked === 0) {
    // tcversions rows belonging to the fixture test cases (identified by the
    // summary written by fixtures_1607.php - TestLink 2.x keeps no testcase_id
    // on tcversions, the link is via the node tree)
    $tcsvs = q("SELECT id FROM tcversions WHERE summary='fixture tc for #1607'");
    foreach (array_filter(explode("\n", $tcsvs)) as $tcv) {
        $tcv = intval($tcv);
        q("INSERT INTO testplan_tcversions (testplan_id,author_id,creation_ts,tcversion_id,platform_id,node_order) "
            . "VALUES ($idTP,1,NOW(),$tcv,0,1)");
    }
    $linked = intval(q("SELECT COUNT(*) FROM testplan_tcversions WHERE testplan_id=$idTP"));
    echo "seeded testplan_tcversions rows: $linked\n\n";
} else {
    echo "testplan_tcversions already seeded: $linked rows\n\n";
}

$warnings = array();
set_error_handler(function ($no, $str, $file, $line) use (&$warnings) {
    if ($no & (E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE)) {
        $warnings[] = "$str in " . basename($file) . " - Line $line";
    }
    return true;
}, E_ALL);

$db = new database(DB_TYPE);
doDBConnect($db);

function flatten($nodes, &$out)
{
    $top = (is_array($nodes) && array_key_exists('childNodes', $nodes))
         ? (array)$nodes['childNodes'] : (array)$nodes;
    foreach ($top as $n) {
        if (is_array($n)) {
            $out[] = ($n['name'] ?? ($n['text'] ?? '?')) . '/' . ($n['node_type_id'] ?? '?')
                   . ($n['tcversion_id'] ?? '');
            flatten(isset($n['childNodes']) ? $n['childNodes'] : array(), $out);
        }
    }
    return $out;
}

$results = array();

// ------------------------------------------------------- matrix row 5 -------
$t = new tree($db);
$r5 = array();
$out = flatten($t->get_subtree($idS1, null, array('recursive' => true)), $r5);
$results['5'] = 'suite subtree: ' . count($r5) . ' nodes -> ' . implode(' | ', $r5);

// ------------------------------------------------------- matrix row 6 -------
$wBefore = count($warnings);
$t = new tree($db);
$r6 = array();
$out = flatten($t->get_subtree($idP, null, array(
    'recursive' => true,
    'order_cfg'  => array('type' => 'exec_order', 'tplan_id' => $idTP),
)), $r6);
$new6 = array_slice($warnings, $wBefore);
$results['6'] = 'exec_order recursive: ' . count($r6) . ' nodes; new warnings=' . count($new6)
              . (count($new6) ? ' (' . implode('; ', $new6) . ')' : '');

// ------------------------------------------------------- matrix row 7 -------
// two DIFFERENT order_cfg recursive calls in ONE process: this is the exact
// shape the `static` broke. Before the fix, call 2 reused call 1's order_cfg.
// second, EMPTY test plan: with the old `static` the second call reused the
// first call's tplan_id and the two counts came out identical
$idTP2 = intval(q("SELECT tp.id FROM testplans tp JOIN testprojects p ON tp.testproject_id=p.id "
                 . "WHERE p.prefix='TQ1' AND tp.id<>$idTP ORDER BY tp.id DESC LIMIT 1"));
// no second plan is needed: tplan_id 0 (no plan) is itself a valid
// discriminator -- it excludes the test cases from the exec_order UNION
$t = new tree($db);
$r7a = array();
$out = flatten($t->get_subtree($idP, null, array(
    'recursive' => true,
    'order_cfg'  => array('type' => 'exec_order', 'tplan_id' => $idTP),
)), $r7a);
$wBefore = count($warnings);
$t2 = new tree($db);
$r7b = array();
$out = flatten($t2->get_subtree($idP, null, array(
    'recursive' => true,
    'order_cfg'  => array('type' => 'exec_order', 'tplan_id' => $idTP2),
)), $r7b);
$new7 = array_slice($warnings, $wBefore);
$results['7'] = 'exec_order(tplan=' . $idTP . ')=' . count($r7a) . ' nodes, then '
              . 'exec_order(tplan=' . $idTP2 . ' EMPTY)=' . count($r7b)
              . ' nodes; new warnings=' . count($new7)
              . (count($r7a) === count($r7b) ? '  <-- LEAK STILL PRESENT' : '  (differs => no leak)');

restore_error_handler();

foreach ($results as $k => $v) {
    echo "row $k: $v\n";
}
echo "\ntotal warnings this run: " . count($warnings) . "\n";
foreach (array_slice($warnings, 0, 10) as $w) {
    echo "  ! $w\n";
}
