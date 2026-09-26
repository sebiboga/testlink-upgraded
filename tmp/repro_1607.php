<?php
// Repro harness for issue #1607 -- "static $my leaks order_cfg/output between
// top-level get_subtree() calls in one process".
//
//   php tmp/repro_1607.php <case>
//
//   case flat-leak   : non-recursive _get_subtree(): call A rspec/rspec then
//                      call B spec_order/full on the SAME parent. Counts
//                      E_WARNING "Undefined array key doc_id".
//   case rec-leak    : recursive _get_subtree_rec(): call A with an
//                      additionalWhereClause / key_type=extjs, then call B
//                      with NO filters/options. If state leaked, call B
//                      returns 0 children and/or extjs-shaped rows.
//   case <other>     : run ONLY that single call (control, expected result)
//
// Reads the ids dumped by tmp/fixtures_1607.php.
require_once('config.inc.php');
require_once('common.php');

$case = isset($argv[1]) ? $argv[1] : 'rec-leak';

$row = shell_exec("mysql -h 127.0.0.1 -utestlink -ptestlink -N -B testlink -e \"SELECT nh.id FROM nodes_hierarchy nh JOIN testprojects tp ON nh.id=tp.id WHERE tp.prefix='TQ1'\"");
$idP = intval(trim((string)$row));
if (!$idP) {
    die("fixture missing - run: php tmp/fixtures_1607.php\n");
}
function nid($typeid) {
    $r = shell_exec("mysql -h 127.0.0.1 -utestlink -ptestlink -N -B testlink -e \"SELECT id FROM nodes_hierarchy WHERE node_type_id=$typeid AND name LIKE 'TQ1607%' ORDER BY id LIMIT 1\"");
    return intval(trim((string)$r));
}
$idS1 = nid(2);
$idS2 = 0;
$idRS1 = nid(6);
$idRS2 = 0;

$warnings = array();
set_error_handler(function ($no, $str, $file, $line) use (&$warnings) {
    if ($no & (E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE)) {
        $warnings[] = "$str in " . basename($file) . " - Line $line";
    }
    return true;
}, E_ALL);

$db = new database(DB_TYPE);
doDBConnect($db);

function flat_names($rows)
{
    $out = array();
    foreach ((array)$rows as $r) {
        $out[] = is_array($r) ? ($r['name'] ?? '?') : $r;
    }
    sort($out);
    return $out;
}

function rec_names($nodes)
{
    $out = array();
    $walk = function ($n) use (&$walk, &$out) {
        $out[] = isset($n['name']) ? $n['name'] : (isset($n['text']) ? 'EXTJS:' . $n['text'] : '?');
        foreach ((array)(isset($n['childNodes']) ? $n['childNodes'] : array()) as $c) {
            $walk($c);
        }
    };
    $top = (is_array($nodes) && array_key_exists('childNodes', $nodes)) ? (array)$nodes['childNodes'] : (array)$nodes;
    foreach ($top as $n) {
        if (is_array($n)) {
            $walk($n);
        }
    }
    return $out;
}

switch ($case) {
    // ------------------------------------------------ A then B, one process --
    case 'flat-leak':
        // call A: rspec ordering + rspec output on the requirement spec parent
        $A = new tree($db);
        $A->get_subtree($idRS1, null, array('order_cfg' => array('type' => 'rspec'),
                                            'output' => 'rspec'));
        // call B: default ordering, full output, on the same parent
        $B = new tree($db);
        $b = $B->get_subtree($idRS1, null, array('output' => 'full'));
        $n = flat_names($b);
        break;

    case 'flat-b':
        $B = new tree($db);
        $b = $B->get_subtree($idRS1, null, array('output' => 'full'));
        $n = flat_names($b);
        break;

    case 'rec-leak':
        // call A: recursive with a restrictive extra WHERE + extjs key_type
        $A = new tree($db);
        $A->get_subtree($idP, array('additionalWhereClause' => " AND node_type_id = 2"),
                        array('recursive' => true, 'key_type' => 'extjs'));
        // call B: recursive, NO filters, NO options at all
        $B = new tree($db);
        $b = $B->get_subtree($idP, null, array('recursive' => true));
        $n = rec_names($b);
        break;

    case 'rec-b':
        $B = new tree($db);
        $b = $B->get_subtree($idP, null, array('recursive' => true));
        $n = rec_names($b);
        break;

    case 'rec-a':
        $A = new tree($db);
        $a = $A->get_subtree($idP, array('additionalWhereClause' => " AND node_type_id = 2"),
                             array('recursive' => true, 'key_type' => 'extjs'));
        $n = rec_names($a);
        break;

    // call B alone but asking for a mismatched output/order_cfg combination:
    // shows the Line-995 doc_id warning is a CALLER-side misuse, not a leak
    case 'mismatch':
        $B = new tree($db);
        $b = $B->get_subtree($idRS1, null, array('output' => 'rspec'));
        $n = flat_names($b);
        break;

    default:
        die("unknown case $case\n");
}

restore_error_handler();

echo "case=$case\n";
echo "  rows: " . (empty($n) ? '(NONE)' : implode(', ', $n)) . "\n";
echo "  count=" . count($n) . "  warnings=" . count($warnings) . "\n";
foreach (array_slice($warnings, 0, 8) as $w) {
    echo "  ! $w\n";
}
