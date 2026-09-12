<?php
/**
 * BFF API — Test Strategy
 *
 * Serves the Test Strategy chapter map used by the General Overview screen
 * (gui/templates/strategy/testStrategy.html) and the chapter pages
 * (scope.html / exitCriteria.html). The chapter list is server-driven so the
 * front-end never hardcodes business data; every label is emitted as a
 * TLi18n key and localised client-side.
 *
 * Routes (session-based; 401 anonymous):
 *   GET ?action=chapters  -> { status, chapters[], footer, grants }
 *   GET ?action=info      -> { status, footer, grants } (chapter-less modal data)
 *
 * The Test Strategy is a content/documentation area: unlike data screens it
 * needs no testplan_metrics/testplan_execute gate — any authenticated user
 * may read it (matches the static pages it replaces: testStrategy.html,
 * scope.html, exitCriteria.html).
 *
 * Refs #1431.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

$db = null;
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
    exit;
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'User not found'));
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/**
 * The 18 ISTQB-style Test Strategy chapters + chapter 19 (Severity
 * Configuration, which owns a dedicated modernized screen). num/icon/key/
 * descKey/url are structural; titles + descriptions are TLi18n keys
 * (`ts.chapter*`) so each locale renders its own text.
 */
function strategyChapters() {
    return array(
        array('num' => 1,  'icon' => 'fa-book',                    'key' => 'ts.chapterIntro',          'descKey' => 'ts.chapterIntroDesc',          'url' => '/gui/templates/strategy/intro.html'),
        array('num' => 2,  'icon' => 'fa-bullseye',                'key' => 'ts.chapterObjectives',     'descKey' => 'ts.chapterObjectivesDesc',     'url' => '/gui/templates/strategy/objectives.html'),
        array('num' => 3,  'icon' => 'fa-expand-arrows-alt',       'key' => 'ts.chapterScope',          'descKey' => 'ts.chapterScopeDesc',          'url' => '/gui/templates/strategy/scope.html'),
        array('num' => 4,  'icon' => 'fa-compass',                 'key' => 'ts.chapterApproach',       'descKey' => 'ts.chapterApproachDesc',       'url' => '/gui/templates/strategy/approach.html'),
        array('num' => 5,  'icon' => 'fa-layer-group',             'key' => 'ts.chapterLevels',         'descKey' => 'ts.chapterLevelsDesc',         'url' => '/gui/templates/strategy/testLevels.html'),
        array('num' => 6,  'icon' => 'fa-check-double',            'key' => 'ts.chapterTypes',          'descKey' => 'ts.chapterTypesDesc',          'url' => '/gui/templates/strategy/testTypes.html'),
        array('num' => 7,  'icon' => 'fa-flag-checkered',          'key' => 'ts.chapterEntryExit',      'descKey' => 'ts.chapterEntryExitDesc',      'url' => '/gui/templates/strategy/exitCriteria.html'),
        array('num' => 8,  'icon' => 'fa-server',                  'key' => 'ts.chapterEnv',            'descKey' => 'ts.chapterEnvDesc',            'url' => '/gui/templates/strategy/environments.html'),
        array('num' => 9,  'icon' => 'fa-users',                   'key' => 'ts.chapterRoles',          'descKey' => 'ts.chapterRolesDesc',          'url' => '/gui/templates/strategy/roles.html'),
        array('num' => 10, 'icon' => 'fa-wrench',                  'key' => 'ts.chapterTools',          'descKey' => 'ts.chapterToolsDesc',          'url' => '/gui/templates/strategy/tools.html'),
        array('num' => 11, 'icon' => 'fa-comments',                'key' => 'ts.chapterComm',           'descKey' => 'ts.chapterCommDesc',           'url' => '/gui/templates/strategy/communication.html'),
        array('num' => 12, 'icon' => 'fa-file-alt',                'key' => 'ts.chapterDeliverables',   'descKey' => 'ts.chapterDeliverablesDesc',   'url' => '/gui/templates/strategy/deliverables.html'),
        array('num' => 13, 'icon' => 'fa-chart-line',              'key' => 'ts.chapterMetrics',        'descKey' => 'ts.chapterMetricsDesc',        'url' => '/gui/templates/strategy/metrics.html'),
        array('num' => 14, 'icon' => 'fa-exclamation-triangle',    'key' => 'ts.chapterRisks',          'descKey' => 'ts.chapterRisksDesc',          'url' => '/gui/templates/strategy/risks.html'),
        array('num' => 15, 'icon' => 'fa-bug',                     'key' => 'ts.chapterDefects',        'descKey' => 'ts.chapterDefectsDesc',        'url' => '/gui/templates/strategy/defectManagement.html'),
        array('num' => 16, 'icon' => 'fa-code-branch',             'key' => 'ts.chapterCfg',            'descKey' => 'ts.chapterCfgDesc',            'url' => '/gui/templates/strategy/changeConfig.html'),
        array('num' => 17, 'icon' => 'fa-graduation-cap',          'key' => 'ts.chapterTraining',       'descKey' => 'ts.chapterTrainingDesc',       'url' => '/gui/templates/strategy/training.html'),
        array('num' => 18, 'icon' => 'fa-rocket',                  'key' => 'ts.chapterRelease',        'descKey' => 'ts.chapterReleaseDesc',        'url' => '/gui/templates/strategy/release.html'),
        array('num' => 19, 'icon' => 'fa-sliders-h',               'key' => 'ts.chapterSeverity',       'descKey' => 'ts.chapterSeverityDesc',       'url' => '/gui/templates/projects/severityConfig.html'),
    );
}

/**
 * Session/footer payload shared by every route.
 */
function strategyMeta($user, $db) {
    return array(
        'login'          => $user->login ?? '',
        'displayName'    => $user->getDisplayName(),
        'generated_on'   => date('Y-m-d H:i:s'),
        'right'          => array(
            'mgt_modify_product' => $user->hasRight($db, 'mgt_modify_product') === 'yes',
        ),
    );
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_REQUEST['action'] ?? 'chapters';

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'message' => 'Method not allowed'));
    exit;
}

switch ($action) {
    case 'chapters':
        echo json_encode(array(
            'status'   => 'ok',
            'chapters' => strategyChapters(),
            'footer'   => strategyMeta($user, $db),
            'grants'   => array('strategy_read' => true),
        ));
        break;

    case 'info':
        echo json_encode(array(
            'status' => 'ok',
            'footer' => strategyMeta($user, $db),
            'grants' => array('strategy_read' => true),
        ));
        break;

    default:
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Unknown action'));
}