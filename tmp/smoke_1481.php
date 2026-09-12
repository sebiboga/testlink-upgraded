<?php
// Standalone render smoke-test for reqViewVersionsViewer / reqViewRevisionViewer guards (Refs #1481)
require_once('config.inc.php');
require_once('common.php');
require_once('lib/requirements/reqCommands.class.php');

require_once('lib/functions/tlsmarty.inc.php');
require_once('lib/functions/lang_api.php');

$db = new database(DB_TYPE);
doDBConnect($db);

// PHP warning capture
$warnings = [];
set_error_handler(function ($no, $str, $file, $line) use (&$warnings) {
    $warnings[] = "$str ($file:$line)";
});
error_reporting(E_ALL);

$labels = [
    'status' => 'Status', 'type' => 'Type', 'scope' => 'Scope', 'version' => 'Version',
    'revision' => 'revision', 'coverage' => 'Coverage', 'required' => 'Required',
    'execution_history' => 'history', 'design' => 'design', 'obsolete' => 'obsolete',
    'btn_print_view' => 'Print', 'title_created' => 'Created on', 'by' => 'by',
    'title_last_mod' => 'Last modified', 'req_spec' => 'Req Spec',
];

$reqBad = [
    'id' => 6, 'version_id' => 7, 'target_id' => 7, 'version' => 1, 'revision' => 1,
    'req_doc_id' => 'R-BAD', 'title' => 'Fixture Requirement BAD', 'status' => 'z',
    'type' => '7', 'scope' => 'Out-of-domain status row.', 'expected_coverage' => 1,
    'creation_ts' => time(), 'modification_ts' => time(), 'author' => 'admin', 'modifier' => '',
];
$reqGood = array_merge($reqBad, ['req_doc_id' => 'R-GOOD', 'title' => 'Fixture Requirement GOOD', 'status' => 'V']);

function makeGui($db) {
    $domain = init_labels(config_get('req_cfg')->status_labels);
    $typeDomain = init_labels(config_get('req_cfg')->type_labels);
    $gui = new stdClass();
    $gui->reqStatusDomain = $domain;
    $gui->reqTypeDomain = $typeDomain;
    $gui->req_cfg = new stdClass();
    $gui->req_cfg->expected_coverage_management = true;
    $gui->attrCfg = ['expected_coverage' => ['7' => true]];
    $gui->reqEditorType = 'none';
    $gui->reqCoverage = [];
    $gui->tcasePrefix = 'TC';
    $gui->glueChar = '-';
    $gui->pieceSep = ':';
    return $gui;
}

function renderSmoke($themeDir, $tpl, $req, $gui, $labels, $thisTemplateDir) {
    $smarty = new Smarty();
    $smarty->setTemplateDir($themeDir);
    $smarty->setCompileDir('/tmp/smarty_compile_1481');
    $smarty->setConfigDir('gui/templates/conf');
    @mkdir('/tmp/smarty_compile_1481', 0777, true);
    $smarty->registerPlugin('function', 'lang_get', 'lang_get_smarty');
    $smarty->registerPlugin('function', 'localize_timestamp', 'localize_timestamp_smarty');
    $smarty->assign('labels', $labels);
    $smarty->assign('args_gui', $gui);
    $smarty->assign('args_req', $req);
    $smarty->assign('args_show_version', true);
    $smarty->assign('args_show_title', false);
    $smarty->assign('args_tproject_name', '');
    $smarty->assign('args_req_spec_name', '');
    $smarty->assign('args_cf', '');
    $smarty->assign('tlImages', ['log_message_small' => '', 'heads_up' => '', 'history_small' => '', 'edit_icon' => '']);
    $smarty->assign('this_template_dir', $thisTemplateDir);
    global $tlCfg;
    $smarty->assign('tlCfg', $tlCfg);
    $smarty->assign('gui', $gui);
    $smarty->assign('cfg_section', 'default');
    return $smarty->fetch($tpl);
}

$gui = makeGui($db);
$domains = [
    'dashio' => ['dir' => 'gui/templates/dashio/', 'incdir' => 'requirements/include'],
    'tl-classic' => ['dir' => 'gui/templates/tl-classic/', 'incdir' => 'requirements'],
];
foreach (['reqViewVersionsViewer.tpl', 'reqViewRevisionViewer.tpl'] as $tpl) {
    foreach ($domains as $theme => $cfg) {
        $outBad = renderSmoke($cfg['dir'], 'requirements/' . $tpl, $reqBad, $gui, $labels, $cfg['incdir']);
        $outGood = renderSmoke($cfg['dir'], 'requirements/' . $tpl, $reqGood, $gui, $labels, $cfg['incdir']);
        $hasBad = strpos($outBad, 'Status : z') !== false;
        $hasGood = strpos($outGood, 'Status : Valid') !== false;
        $winning = (int)preg_match('/Status\s*:\s*Valid/', $outGood) + (int)preg_match('/Status\s*:\s*z/', $outBad);
        printf("%s %s => BAD[Status : z]=%s GOOD[Status : Valid]=%s warnings=%d\n",
            $theme, $tpl, $hasBad ? 'YES' : 'NO', $hasGood ? 'YES' : 'NO', count($warnings));
        foreach (array_slice($warnings, 0, 5) as $w) { echo "   WARN: $w\n"; }
        $warnings = [];
    }
}
echo "DONE\n";