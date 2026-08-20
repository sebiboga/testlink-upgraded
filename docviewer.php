<?php
/**
 * TestLink Document Viewer
 * Serves PDFs directly to browser's native PDF viewer
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

// Serve PDF with proper headers for browser native viewer
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename($full_path) . '"');
header('Cache-Control: public, must-revalidate, max-age=0');
header('Accept-Ranges: bytes');

// For large files, support range requests
$file_size = filesize($full_path);
if (isset($_SERVER['HTTP_RANGE'])) {
  if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
    $start = intval($matches[1]);
    $end = $matches[2] === '' ? $file_size - 1 : intval($matches[2]);

    header('HTTP/1.1 206 Partial Content');
    header("Content-Range: bytes $start-$end/$file_size");
    header('Content-Length: ' . ($end - $start + 1));

    $fp = fopen($full_path, 'rb');
    fseek($fp, $start);
    echo fread($fp, $end - $start + 1);
    fclose($fp);
  }
} else {
  header('Content-Length: ' . $file_size);
  readfile($full_path);
}
