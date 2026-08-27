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
);

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
