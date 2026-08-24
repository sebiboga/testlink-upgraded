<?php
/**
 * Regression harness for issue #482 — exercises the REAL checkPhpExtensions()
 * code from lib/functions/configCheck.php with the single probe call
 * (extension_loaded) swapped for a simulator so we can emulate hosts that
 * are missing specific extensions.
 */
$MISSING = array();
function sim_ext_loaded($ext) {
  global $MISSING;
  return !in_array($ext, $MISSING, true);
}

$src = file_get_contents(dirname(__DIR__) . '/lib/functions/configCheck.php');
if ($src === false) { fwrite(STDERR, "cannot read configCheck.php\n"); exit(1); }
// isolate the function under test into a sandbox file, swapping only the probe call
$start = strpos($src, 'function checkPhpExtensions(');
$end = strpos($src, '/**' . "\n" . ' * Check if web server support session data');
if ($start === false || $end === false || $end <= $start) { fwrite(STDERR, "cannot locate function\n"); exit(1); }
$fn = substr($src, $start, $end - $start);
$fn = str_replace('extension_loaded(', 'sim_ext_loaded(', $fn);
$sandbox = tmpfile();
fwrite($sandbox, "<?php\n\$MISSING = array();\n" . $fn . "\n");
fseek($sandbox, 0);
include stream_get_meta_data($sandbox)['uri'];

$failures = array();

function expect($cond, $label) {
  global $failures;
  echo ($cond ? "PASS" : "FAIL") . " - {$label}\n";
  if (!$cond) { $failures[] = $label; }
}

// Case 1: healthy host -> every required ext probed, no blocking errors
$MISSING = array();
$err = 0; $out = checkPhpExtensions($err);
foreach (array('mysqli','mbstring','openssl','gd','ldap','curl','pdo_mysql','pgsql','json') as $probeHint) {
  // match on the row label text
}
expect(strpos($out, '[REQUIRED] Multibyte Strings (mbstring)') !== false, 'row present: [REQUIRED] mbstring');
expect(strpos($out, '[REQUIRED] MySQL Database (mysqli)') !== false, 'row present: [REQUIRED] MySQL Database (mysqli)');
expect(strpos($out, '[REQUIRED] OpenSSL') !== false, 'row present: [REQUIRED] OpenSSL');
expect(strpos($out, 'PDO MySQL driver') !== false, 'row present: PDO MySQL driver');
expect(strpos($out, 'Postgres Database (optional)') !== false, 'row present: Postgres (optional)');
expect($err === 0, "healthy host => errCounter stays 0 (got {$err})");
expect(strpos($out, 'tab-error') === false, 'healthy host => no tab-error markup');

// Case 2: host WITHOUT mbstring -> REQUIRED tier must block
$MISSING = array('mbstring');
$err = 0; $out = checkPhpExtensions($err);
expect(strpos($out, 'FAILED - REQUIRED EXTENSION MISSING!') !== false, 'missing mbstring => REQUIRED failure markup rendered');
expect(strpos($out, 'sudo apt install php-mbstring') !== false, 'missing mbstring => apt hint rendered');
expect($err >= 1, "missing mbstring => errCounter incremented (got {$err})");

// Case 3: host WITHOUT openssl -> REQUIRED tier must block
$MISSING = array('openssl');
$err = 0; $out = checkPhpExtensions($err);
expect($err >= 1, "missing openssl => errCounter incremented (got {$err})");
expect(strpos($out, '[REQUIRED] OpenSSL') !== false && strpos($out, 'FAILED - REQUIRED EXTENSION MISSING!') !== false,
       'missing openssl => REQUIRED failure markup rendered');

// Case 4: host WITHOUT a recommended ext (curl) -> warning ONLY, must NOT block
$MISSING = array('curl');
$err = 0; $out = checkPhpExtensions($err);
expect($err === 0, "missing curl (recommended) => errCounter stays 0 (got {$err})");
expect(strpos($out, 'tab-warning') !== false, 'missing curl => tab-warning markup rendered');

// Case 5: DB driver per version logic still intact (PHP 8.2+ probes mysqlnd)
$MISSING = array('mysqlnd');
$err = 0; $out = checkPhpExtensions($err);
if (version_compare(phpversion(), '8.2.0', '>=')) {
  expect($err >= 1, "PHP>=8.2 missing mysqlnd => errCounter incremented (got {$err})");
} else {
  expect($err === 0, "PHP<8.2 mysqlnd irrelevant => errCounter stays 0 (got {$err})");
}

echo count($failures) === 0
  ? "\nALL CASES PASS (" . phpversion() . ")\n"
  : "\nFAILURES: " . count($failures) . "\n";
exit(count($failures) === 0 ? 0 : 1);
