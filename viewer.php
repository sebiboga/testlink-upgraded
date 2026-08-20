<?php
/**
 * TestLink PDF Viewer using PDF.js
 * Guaranteed to open PDFs in browser without download
 */

$allowed_files = array(
  'testlink_user_manual' => 'docs/testlink_user_manual.pdf',
  'testlink_installation_manual' => 'docs/testlink_installation_manual.pdf',
  'tl_file_formats' => 'docs/tl-file-formats.pdf',
  'excel2testlink' => 'docs/excel2TestLink.pdf',
  'fckeditor_config' => 'docs/Configuration_of_FCKEditor_and_CKFinder.pdf',
  'tl_bts_howto' => 'docs/tl-bts-howto.pdf',
);

$file_key = isset($_GET['file']) ? $_GET['file'] : null;

if (!$file_key || !isset($allowed_files[$file_key])) {
  die('Document not found');
}

$file_path = $allowed_files[$file_key];
$full_path = dirname(__FILE__) . '/' . $file_path;

if (!file_exists($full_path)) {
  die('File not found');
}

$pdf_url = htmlspecialchars($file_path);
$doc_name = htmlspecialchars(basename($file_path, '.pdf'));
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php echo $doc_name; ?> - TestLink</title>
  <style>
    * { margin: 0; padding: 0; }
    body { font-family: sans-serif; }
    #toolbar {
      background: #4ECDC4;
      color: white;
      padding: 10px 15px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    #toolbar button {
      background: white;
      border: none;
      padding: 8px 12px;
      cursor: pointer;
      border-radius: 3px;
    }
    #canvas-container {
      overflow: auto;
      height: calc(100vh - 50px);
      background: #808080;
      display: flex;
      justify-content: center;
      padding: 20px;
    }
    canvas {
      box-shadow: 0 0 5px rgba(0,0,0,0.3);
    }
  </style>
</head>
<body>
  <div id="toolbar">
    <div>
      <button onclick="prevPage()">← Previous</button>
      <span id="page-info" style="margin: 0 20px;">Page <span id="page-num">1</span> of <span id="page-count">1</span></span>
      <button onclick="nextPage()">Next →</button>
      <button onclick="zoomIn()">Zoom +</button>
      <button onclick="zoomOut()">Zoom -</button>
    </div>
    <button onclick="window.close()">Close</button>
  </div>
  <div id="canvas-container">
    <canvas id="pdf-canvas"></canvas>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
  <script>
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    const pdfPath = '<?php echo $pdf_url; ?>';
    let pdfDoc = null;
    let currentPage = 1;
    let scale = 1.5;

    async function init() {
      pdfDoc = await pdfjsLib.getDocument(pdfPath).promise;
      document.getElementById('page-count').innerText = pdfDoc.numPages;
      await renderPage(1);
    }

    async function renderPage(num) {
      if (num < 1 || num > pdfDoc.numPages) return;
      currentPage = num;
      document.getElementById('page-num').innerText = num;

      const page = await pdfDoc.getPage(num);
      const canvas = document.getElementById('pdf-canvas');
      const ctx = canvas.getContext('2d');
      const viewport = page.getViewport({ scale: scale });

      canvas.width = viewport.width;
      canvas.height = viewport.height;

      await page.render({ canvasContext: ctx, viewport: viewport }).promise;
    }

    function nextPage() {
      if (currentPage < pdfDoc.numPages) {
        renderPage(currentPage + 1);
      }
    }

    function prevPage() {
      if (currentPage > 1) {
        renderPage(currentPage - 1);
      }
    }

    function zoomIn() {
      scale += 0.2;
      renderPage(currentPage);
    }

    function zoomOut() {
      if (scale > 0.5) {
        scale -= 0.2;
        renderPage(currentPage);
      }
    }

    init();
  </script>
</body>
</html>
