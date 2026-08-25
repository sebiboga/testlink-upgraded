<?php
/**
 * TestLink Document Viewer
 * Serves PDFs from docs/ directory to the browser's native PDF viewer.
 * Consolidated from root viewer.php + docviewer.php (Refs #693).
 *
 * Usage: tools/viewer.php?file=testlink_user_manual
 */

$allowed_files = array(
  'testlink_user_manual' => '../docs/testlink_user_manual.pdf',
  'testlink_installation_manual' => '../docs/testlink_installation_manual.pdf',
  'tl_file_formats' => '../docs/tl-file-formats.pdf',
  'excel2testlink' => '../docs/excel2TestLink.pdf',
  'fckeditor_config' => '../docs/Configuration_of_FCKEditor_and_CKFinder.pdf',
  'tl_bts_howto' => '../docs/tl-bts-howto.pdf',
  'good_test_case' => '../docs/bibliographical_references/GoodTest.pdf',
  'youtrack_readme' => '../docs/youtrack-readme.pdf',
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
  echo "File not found: " . htmlspecialchars(basename($file_path));
  exit;
}

$pdf_url = htmlspecialchars($file_path);
$doc_name = htmlspecialchars(basename($file_path, '.pdf'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $doc_name; ?> - TestLink</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { width: 100%; height: 100vh; overflow: hidden; }
    embed { width: 100%; height: 100%; display: block; }
  </style>
</head>
<body>
  <embed src="<?php echo $pdf_url; ?>" type="application/pdf">
</body>
</html>
