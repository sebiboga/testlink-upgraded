<?php
// Repro / regression harness for issue #1712
// "8 sibling issue-tracker interface classes share #1711's unguarded (string)
//  cast on stdClass cfg members"
//
// setCfg() (issueTrackerInterface.class.php:165) re-binds $this->cfg to a
// stdClass via json_decode(json_encode(...)), so a cfg field that is NOT text
// (element-valued / empty / whitespace-only / repeated) decodes to a nested
// stdClass or a PHP array. Every unguarded (string) cast or trim() on such a
// member raises an Error/TypeError - which catch(Exception) cannot stop - and
// the request dies with HTTP 502.
//
// Usage:  php tmp/repro_1712.php   -> exits 0 when every case is clean, 1 otherwise
// Each case instantiates the interface class exactly like the BFF does
// (api/issuetracker/index.php:280 `new $impl($type,$cfg,$name)`).
chdir(dirname(__FILE__) . '/..');
require_once('config.inc.php');
require_once('common.php');

$FAIL = 0;
function ok($cond, $label)
{
    global $FAIL;
    if ($cond) { echo "  PASS  $label\n"; } else { echo "  FAIL  $label\n"; $FAIL++; }
}

// Captures PHP warnings/deprecations raised while the ctor runs.
$warnings = array();
$handler = function ($no, $str, $file, $line) {
    $GLOBALS['warnings'][] = "$str (" . basename($file) . ":$line)";
    return true;
};
set_error_handler($handler);

function run_case($label, $impl, $type, $cfg, $strict = true)
{
    $GLOBALS['warnings'] = array();
    $err = null;
    try {
        $iface = new $impl($type, $cfg, 'IT-1712');
        $connected = $iface->isConnected();
    } catch (Throwable $e) {
        $err = get_class($e) . ': ' . $e->getMessage();
        $connected = null;
    }
    // The defect's own signature: an uncatchable Error/TypeError, or a warning
    // from the very same unguarded read. Warnings emitted by 3rd-party libs
    // (dynamic-property deprecations, "connection refused" while trying the
    // unreachable control host) are environmental and out of scope.
    $family = preg_grep('/could not be converted to string|Undefined property: stdClass'
        . '|must be of type string|Array to string conversion|parse_url\(\)/',
        $GLOBALS['warnings']);
    $w = count($GLOBALS['warnings']);
    $clean = ($err === null) && (!$strict || count($family) === 0);
    printf("%s  %-34s %s\n", $clean ? 'PASS' : 'FAIL', $label,
           $err !== null ? $err : ($w > 0 ? "warnings=$w" : 'connected=' . var_export($connected, true)));
    foreach ($family as $x) { echo "        ! $x\n"; }
    if (!$clean) { $GLOBALS['FAIL']++; }
    return $clean;
}

$U = '<uribase>http://127.0.0.1:1/</uribase>';

echo "== A. element-valued field ('<f><x/></f>') on a structurally-string member ==\n";
run_case('fogbugzrest  (type 8)  username',  'fogbugzrestInterface', 8,
    "<issuetracker>$U<username><x/></username><password>p</password></issuetracker>");
run_case('gforgesoap   (type 10) uriwsdl',   'gforgesoapInterface', 10,
    "<issuetracker>$U<uriwsdl><x/></uriwsdl></issuetracker>");
run_case('jirarest     (type 7)  uriapi',    'jirarestInterface', 7,
    "<issuetracker>$U<uriapi><x/></uriapi><username>u</username><password>p</password></issuetracker>");
run_case('jirasoap     (type 5)  uriwsdl',   'jirasoapInterface', 5,
    "<issuetracker>$U<uriwsdl><x/></uriwsdl></issuetracker>");
run_case('mantissoap   (type 3)  uriwsdl',   'mantissoapInterface', 3,
    "<issuetracker>$U<uriwsdl><x/></uriwsdl></issuetracker>");
run_case('redminerest  (type 15) apikey',    'redminerestInterface', 15,
    "<issuetracker>$U<apikey><x/></apikey></issuetracker>");
run_case('tracxmlrpc   (type 19) urixmlrpc', 'tracxmlrpcInterface', 19,
    "<issuetracker>$U<urixmlrpc><x/></urixmlrpc></issuetracker>");
run_case('tuleaprest   (type 27) tracker',   'tuleaprestInterface', 27,
    "<issuetracker>$U<tracker><x/></tracker></issuetracker>");

echo "\n== B. whitespace-only field ('<f>  </f>') -> same nested stdClass ==\n";
run_case('mantissoap   uriwsdl "  "', 'mantissoapInterface', 3,
    "<issuetracker>$U<uriwsdl>  </uriwsdl></issuetracker>");
run_case('tuleaprest   tracker "  "', 'tuleaprestInterface', 27,
    "<issuetracker>$U<tracker>  </tracker></issuetracker>");

echo "\n== C. repeated field ('<f>a</f><f>b</f>') -> PHP array ==\n";
run_case('mantissoap   uriwsdl x2', 'mantissoapInterface', 3,
    "<issuetracker>$U<uriwsdl>a</uriwsdl><uriwsdl>b</uriwsdl></issuetracker>");

echo "\n== D. control: a perfectly normal cfg still behaves ==\n";
// control cases only assert that no Throwable escapes: they legitimately hit
// the network (connection refused) and load legacy libs that emit deprecations.
run_case('mantissoap   normal cfg', 'mantissoapInterface', 3,
    "<issuetracker>$U<uriwsdl>http://127.0.0.1:1/mantis</uriwsdl><username>u</username><password>p</password></issuetracker>", false);
run_case('bugzillaxmlrpc normal cfg', 'bugzillaxmlrpcInterface', 1,
    "<issuetracker>$U<username>u</username><password>p</password></issuetracker>", false);

echo "\n" . ($FAIL === 0 ? "ALL PASS" : "$FAIL FAILURE(S)") . "\n";
exit($FAIL === 0 ? 0 : 1);
