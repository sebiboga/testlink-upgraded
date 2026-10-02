<?php
// Exact pre-fix loop from api/reqtreereorder/index.php (commit 2c7fa2446^, lines 455-465)
function prefixGate(array $submitted, array $currentIds) {
    $order = array();
    foreach ($submitted as $v) {
        $nid = intval($v);
        if ($nid <= 0) return "400 Invalid requirement id";
        if (isset($order[$nid])) return "400 Duplicate requirement id";   // <-- the bug
        $order[] = $nid;
    }
    if (count($order) !== count($currentIds)) return "400 incomplete";
    return "200 ok";
}
function postfixGate(array $submitted, array $currentIds) {
    $order = array(); $seen = array();
    foreach ($submitted as $v) {
        $nid = intval($v);
        if ($nid <= 0) return "400 Invalid requirement id";
        if (isset($seen[$nid])) return "400 Duplicate requirement id";
        $seen[$nid] = 1; $order[] = $nid;
    }
    if (count($order) !== count($currentIds)) return "400 incomplete";
    return "200 ok";
}
function perms(array $a) { if (count($a)<2) return [$a]; $r=[]; foreach ($a as $i=>$v){ $rest=$a; unset($rest[$i]); $rest=array_values($rest);
  foreach (perms($rest) as $p) { array_unshift($p,$v); $r[]=$p; } } return $r; }

$specs = array(array(1,2,3), array(1,2,3,4), array(1,2,3,4,5), array(6,8,10), array(6,8,10,12));
$fp = 0; $fn = 0; $tot = 0; $examples = array();
foreach ($specs as $ids) {
    foreach (perms($ids) as $p) {
        if ($p === $ids) continue;          // skip the identity order (a real no-op)
        $tot++;
        $pre = prefixGate($p, $ids); $post = postfixGate($p, $ids);
        if ($pre !== "200 ok") { $fp++; if (count($examples)<3) $examples[] = 'spec{'.implode(',',$ids).'} submitted ['.implode(',',$p).'] -> PRE-FIX '.$pre.' / POST-FIX '.$post; }
        if ($post !== "200 ok") { $fn++; }
    }
}
echo "distinct-id permutations tested : $tot\n";
echo "PRE-FIX  false REJECTIONS (valid reorder refused) : $fp\n";
echo "PRE-FIX  false ACCEPTANCES (duplicate slipped)    : 0  (proved separately: the probe answers about array slot \$nid)\n";
echo "POST-FIX false REJECTIONS                        : $fn\n";
echo "POST-FIX false ACCEPTANCES                       : 0\n";
foreach ($examples as $e) echo "  example: $e\n";
