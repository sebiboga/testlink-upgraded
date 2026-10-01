<?php
/**
 * Fixture for issue #1042 (attachment download link in tcView.html).
 *
 * The DB dump row alone is not enough: with $g_repositoryType = FS the file
 * bytes live in ATTACHMENTS_REPOSITORY and attachments.file_path points at
 * them (that is what the download endpoint streams). This script therefore
 * inserts the two attachments the way the legacy attachmentupload.php does,
 * through tlAttachmentRepository::insertAttachment().
 *
 * Usage: php tmp/fixtures_1042.php <tcversion_id> [att_id]
 */
require_once(__DIR__ . '/../config.inc.php');
require_once(__DIR__ . '/../lib/functions/attachments.inc.php');

$db = new database(DB_TYPE);
doDBConnect($db);

$tcv = isset($argv[1]) ? intval($argv[1]) : 0;
if ($tcv <= 0) {
    fwrite(STDERR, "usage: php tmp/fixtures_1042.php <tcversion_id>\n");
    exit(1);
}

$repoDir = sys_get_temp_dir() . '/tlu_att_1042';
if (!is_dir($repoDir)) {
    mkdir($repoDir, 0777, true);
}

// 8x8 red PNG + a plain text file: one image row (is_image / eye + ghost
// toggles) and one empty-title row (access_string / file-name fallback).
$pngB64 = 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAIAQMAAAD+wSzIAAAABlBMVEX///+/v7+jQ3Y5'
        . 'AAAADklEQVQI12P4AIX8EAgALgAD/aNpbtEAAAAASUVORK5CYII=';
file_put_contents($repoDir . '/fixture.png', base64_decode($pngB64));
file_put_contents($repoDir . '/notes.txt',
                  "Issue 1042 fixture: plain text attachment.\n");

// idempotent: drop the fixture rows of this issue first
$attTables = tlObjectWithDB::getDBTables(array('attachments'));
$db->exec_query("DELETE FROM {$attTables['attachments']} "
                . "WHERE fk_table = 'tcversions' AND fk_id = {$tcv} "
                . "AND file_name IN ('fixture.png','notes.txt')");

$specs = array(
    array('fixture.png', 'image/png', 'Fixture screenshot'),
    array('notes.txt',   'text/plain', ''),
);
$ok = 0;
foreach ($specs as $s) {
    $fin = array(
        'name'     => $s[0],
        'type'     => $s[1],
        'tmp_name' => $repoDir . '/' . $s[0],
        'size'     => filesize($repoDir . '/' . $s[0]),
        'error'    => 0,
    );
    $op = tlAttachmentRepository::create($db)->insertAttachment($tcv, 'tcversions', $s[2], $fin);
    if (!$op->statusOK) {
        fwrite(STDERR, "insertAttachment FAILED for {$s[0]}: "
                       . strval($op->msg) . "\n");
        exit(1);
    }
    $ok++;
}

echo "OK tcversion={$tcv} inserted={$ok}\n";
foreach (tlAttachmentRepository::create($db)
             ->getAttachmentInfosFor($tcv, 'tcversions') as $ai) {
    echo "  id={$ai['id']} title='{$ai['title']}' file={$ai['file_name']} "
       . "type={$ai['file_type']} size={$ai['file_size']} "
       . "is_image=" . var_export($ai['is_image'], true) . "\n";
}
