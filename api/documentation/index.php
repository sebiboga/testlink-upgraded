<?php
/**
 * BFF API — Documentation Hub
 *
 * Returns the list of available documentation PDFs with metadata.
 * Mirrors tools/viewer.php's $allowed_files array but returns JSON
 * for the Dashio HTML screen (gui/templates/documentation/documentation.html).
 *
 * Refs #764.
 */
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');
doSessionStart();

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
    exit;
}

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$docs = array(
    array(
        'key'       => 'testlink_user_manual',
        'title'     => lang_get('doc_user_manual'),
        'filename'  => 'testlink_user_manual.pdf',
        'pdfUrl'    => '/docs/testlink_user_manual.pdf',
    ),
    array(
        'key'       => 'testlink_installation_manual',
        'title'     => lang_get('doc_installation_manual'),
        'filename'  => 'testlink_installation_manual.pdf',
        'pdfUrl'    => '/docs/testlink_installation_manual.pdf',
    ),
    array(
        'key'       => 'tl_file_formats',
        'title'     => lang_get('doc_file_formats'),
        'filename'  => 'tl-file-formats.pdf',
        'pdfUrl'    => '/docs/tl-file-formats.pdf',
    ),
    array(
        'key'       => 'excel2testlink',
        'title'     => lang_get('doc_excel_import'),
        'filename'  => 'excel2TestLink.pdf',
        'pdfUrl'    => '/docs/excel2TestLink.pdf',
    ),
    array(
        'key'       => 'fckeditor_config',
        'title'     => lang_get('doc_fckeditor_config'),
        'filename'  => 'Configuration_of_FCKEditor_and_CKFinder.pdf',
        'pdfUrl'    => '/docs/Configuration_of_FCKEditor_and_CKFinder.pdf',
    ),
    array(
        'key'       => 'tl_bts_howto',
        'title'     => lang_get('doc_bug_tracking_howto'),
        'filename'  => 'tl-bts-howto.pdf',
        'pdfUrl'    => '/docs/tl-bts-howto.pdf',
    ),
    array(
        'key'       => 'good_test_case',
        'title'     => lang_get('doc_good_test_case'),
        'filename'  => 'GoodTest.pdf',
        'pdfUrl'    => '/docs/bibliographical_references/GoodTest.pdf',
    ),
    array(
        'key'       => 'youtrack_readme',
        'title'     => lang_get('doc_youtrack_readme'),
        'filename'  => 'youtrack-readme.pdf',
        'pdfUrl'    => '/docs/youtrack-readme.pdf',
    ),
);

// Mirror the legacy tools/viewer.php allowlist ordering (Refs #1281).
usort($docs, function($a, $b) {
    static $order = array('testlink_user_manual', 'testlink_installation_manual',
        'tl_file_formats', 'excel2testlink', 'fckeditor_config', 'tl_bts_howto',
        'good_test_case', 'youtrack_readme');
    $ia = array_search($a['key'], $order, true);
    $ib = array_search($b['key'], $order, true);
    return ($ia === false ? 999 : $ia) - ($ib === false ? 999 : $ib);
});

// Verify each file exists on disk
foreach ($docs as &$doc) {
    $full = dirname(__FILE__) . '/../../' . ltrim($doc['pdfUrl'], '/');
    $doc['exists'] = file_exists($full);
}
unset($doc);

echo json_encode(array(
    'status'  => 'ok',
    'docs'    => $docs,
    'wikiUrl' => 'https://github.com/sebiboga/testlink-upgraded/wiki',
));
