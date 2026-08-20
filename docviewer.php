<?php
/**
 * TestLink Document Viewer
 * Opens PDF/documents inline in browser instead of downloading
 * Usage: docviewer.php?file=testlink_user_manual
 */

$allowed_files = array(
  'testlink_user_manual' => 'docs/testlink_user_manual.pdf',
  'testlink_installation_manual' => 'docs/testlink_installation_manual.pdf',
  'tl_file_formats' => 'docs/tl-file-formats.pdf',
  'excel2testlink' => 'docs/excel2TestLink.pdf',
  'fckeditor_config' => 'docs/Configuration_of_FCKEditor_and_CKFinder.pdf',
  'tl_bts_howto' => 'docs/tl-bts-howto.pdf',
  'good_test_case' => 'docs/bibliographical_references/GoodTest.pdf',
  'youtrack_readme' => 'docs/youtrack-readme.pdf',
);

$file_key = isset($_GET['file']) ? $_GET['file'] : null;

if (!$file_key || !isset($allowed_files[$file_key])) {
  http_response_code(404);
  echo "Document not found";
  exit;
}

$file_path = $allowed_files[$file_key];
$full_path = dirname(__FILE__) . '/' . $file_path;

if (!file_exists($full_path)) {
  http_response_code(404);
  echo "File not found: $file_path";
  exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($full_path) . '"');
header('Content-Length: ' . filesize($full_path));
header('Cache-Control: public, must-revalidate, max-age=0');
header('Pragma: public');

readfile($full_path);
