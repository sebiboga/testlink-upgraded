<?php
// Verification harness for issue #1593 (lib/functions/common.php:122 guarded
// include_once in tlAutoload).
// Run from repo root: php tmp/verify_1593.php
// Exit code 0 = all checks pass.
require_once('config.inc.php');
require_once('common.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$fail = 0;
function chk($name, $ok, $detail = '') {
    global $fail;
    if (!$ok) { $fail++; }
    printf("%-62s %s %s\n", $name, $ok ? 'PASS' : 'FAIL', $detail);
}

// 1. the autoloader still resolves plain classes (flat, lib/functions)
chk('autoload: database (lib/functions)', class_exists('database', true));
chk('autoload: testproject (lib/functions)', class_exists('testproject', true));
chk('autoload: tlReqMgrSystem (lib/functions)', class_exists('tlReqMgrSystem', true));
// NOTE: exttable.class.php declares tlExtTable, not exttable - a pre-existing
// name mismatch, identical before and after the fix (asserted below as a
// negative control: the file DOES resolve, the class name just differs).
// exttable.class.php declares tlExtTable, not exttable. The file is pulled in
// by an explicit require in the code that needs it, never by the autoloader, so
// 'exttable'/'tlExtTable' via autoload was already false before the fix - the
// negative control below pins that (behaviour identical, warnings gone), and
// the explicit include proves the real class file still resolves and loads.
chk('negative control: autoload("exttable") === false (file declares tlExtTable)',
    class_exists('exttable', true) === false);
require_once('exttable.class.php');
chk('explicit include of exttable.class.php defines tlExtTable',
    class_exists('tlExtTable', false));

// 2. classes from a SUBDIRECTORY include_path entry still load
//    (lib/reqmgrsystemintegration/ has no shipped class, so use the
//     issuetrackerintegration one + third_party style subdirs)
chk('autoload: mantisrestInterface (issuetrackerintegration/)',
    class_exists('mantisrestInterface', true));
chk('autoload: redminerestInterface (issuetrackerintegration/)',
    class_exists('redminerestInterface', true));
chk('autoload: stashrestInterface (codetrackerintegration/)',
    class_exists('stashrestInterface', true));
chk('autoload: githubrestCodeTrackerInterface (codetrackerintegration/)',
    class_exists('githubrestCodeTrackerInterface', true));
chk('autoload: reqMgrSystemInterface (reqmgrsystemintegration/)',
    class_exists('reqMgrSystemInterface', true));

// 3. a class that does not exist must NOT raise any PHP diagnostic
$before = error_get_last();
$missing = class_exists('contoursoapInterface', true);
$after = error_get_last();
chk('missing class: class_exists("contoursoapInterface") === false', $missing === false);
chk('missing class: no new E_WARNING raised by the loader',
    $after === $before || ($after['type'] !== E_WARNING),
    $after === $before ? '' : json_encode($after));

// 4. same for a totally invented class name
chk('missing class: class_exists("zzNoSuchClass1593") === false',
    class_exists('zzNoSuchClass1593', true) === false);

// 5. the loader must not define anything for a missing class
chk('missing class: class not defined afterwards',
    !class_exists('contoursoapInterface', false));

// 6. real behaviour of the app is unchanged: the reqmgr system manager still
//    reports the contour system and its "not connected" state degrades
$sysMgr = new tlReqMgrSystem($db);
$sys = $sysMgr->getByName('Contour Demo');
chk('app: contour system row still readable', !is_null($sys));
if (!is_null($sys)) {
    chk('app: implementation resolves to contoursoapInterface',
        $sys['implementation'] === 'contoursoapInterface', $sys['implementation']);
    chk('app: checkConnection() degrades to false instead of fataling',
        $sysMgr->checkConnection($sys['id']) === false);
}

// 7. the loader did not define the class by accident
chk('loader: contoursoapInterface still absent after checkConnection()',
    !class_exists('contoursoapInterface', false));

printf("\n%s (%d failure(s))\n", $fail ? 'RESULT: FAIL' : 'RESULT: PASS', $fail);
exit($fail ? 1 : 0);
