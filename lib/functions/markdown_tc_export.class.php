<?php
/**
 * Markdown Test Case Exporter (TestLink 2.0.1 modernization)
 *
 * Generates structured Markdown compatible with markdown_tc_import.class.php,
 * enabling an export -> import round-trip that preserves the ExternalID
 * (e.g. "- **ExternalID:** TLU-42") for duplicate detection on re-import
 * (hitCriteria=externalID / actionOnHit=update_last_version).
 *
 * Format produced:
 *   # <project name>
 *   **Project:** <project name>
 *   **Prefix:** <prefix>
 *
 *   ## <suite name> (Suite ID: N)
 *   ### TC-N: <title>
 *   - **ExternalID:** <prefix>-<external_id>
 *   - **Importance:** <High|Medium|Low>
 *   - **Preconditions:** ...
 *   - **Steps:**
 *       1. Do something
 *          *Expected:* something happened
 *   - **Expected result:** ...
 */
class markdownTcExport
{
    /** @var testcase */
    protected $tcaseMgr;

    /** @var testsuite */
    protected $tsuiteMgr;

    /** @var testproject */
    protected $tprojectMgr;

    /** @var string */
    protected $prefix = '';

    /** @var string */
    protected $glueChar = '-';

    public function __construct($db, $tprojectId)
    {
        $this->tcaseMgr = new testcase($db);
        $this->tsuiteMgr = new testsuite($db);
        $this->tprojectMgr = new testproject($db);
        $this->glueChar = (string) config_get('testcase_cfg')->glue_character;
        $tp = $this->tprojectMgr->get_by_id($tprojectId);
        if ($tp) {
            $prefix = method_exists($this->tprojectMgr, 'getTestCasePrefix')
                ? $this->tprojectMgr->getTestCasePrefix($tprojectId)
                : null;
            $this->prefix = is_string($prefix) && $prefix !== '' ? $prefix : 'TC';
        }
    }

    /**
     * Export a single test case (latest version) as markdown.
     */
    public function exportTestCase($tcaseId, $tprojectId)
    {
        $tcaseId = intval($tcaseId);
        $latestId = $this->resolveLatestVersionId($tcaseId);
        if ($latestId <= 0) {
            return '';
        }
        $tcinfo = $this->tcaseMgr->get_by_id($tcaseId, $latestId, null, array('output' => 'essential'));
        if (is_null($tcinfo) || count($tcinfo) === 0) {
            return '';
        }
        $row = $tcinfo[0];
        $tcversionId = $latestId;
        $title = strval($row['name'] ?? '');
        $extId = $this->getExtIdString($tcaseId, $tprojectId);
        $suiteName = $this->getSuiteNameForCase($tcaseId);

        $md = "## {$suiteName} (Suite ID: 0)\n";
        $md .= self::tcBlock($this->tcaseMgr, $tcaseId, $tcversionId, $title, $extId, $tprojectId);
        return $md;
    }

    /**
     * Export a whole test suite (recursive by default) as markdown.
     */
    public function exportTestSuite($containerId, $tprojectId, $recursive = true)
    {
        $md = '';
        $this->exportNode($containerId, $tprojectId, $recursive, $md);
        return $md;
    }

    /**
     * Export a whole test project (all suites, recursive) as markdown.
     */
    public function exportTestProject($tprojectId)
    {
        $md = '';
        $this->exportNode($tprojectId, $tprojectId, true, $md);
        return $md;
    }

    public function header($projectName)
    {
        $name = (string) $projectName;
        $md  = "# {$name}\n";
        $md .= "**Project:** {$name}\n";
        $md .= "**Prefix:** {$this->prefix}\n";
        $md .= "\n";
        return $md;
    }

    /**
     * Recursively walk a node (project or suite) and append markdown sections.
     * Mirrors the traversal in testsuite::exportTestSuiteDataToXML().
     */
    protected function exportNode($containerId, $tprojectId, $recursive, &$md)
    {
        $topt = array('recursive' => testsuite::USE_RECURSIVE_MODE,
                      'excludeTC' => false,
                      'excludeContents' => null);
        $test_spec = $this->tsuiteMgr->get_subtree($containerId, $topt);
        $childNodes = isset($test_spec['childNodes']) ? $test_spec['childNodes'] : null;
        if (is_null($childNodes)) {
            return;
        }
        foreach ($childNodes as $cNode) {
            $nTable = $cNode['node_table'];
            if ($nTable == 'testsuites') {
                $this->emitSuite($cNode['id'], $tprojectId, $recursive, $md);
            } elseif ($nTable == 'testcases') {
                $this->emitCase($cNode['id'], null, $tprojectId, $md);
            }
        }
    }

    /**
     * Emit a suite section (optionally recursing into child suites).
     * When not recursing, a suite emits only its direct cases.
     */
    protected function emitSuite($suiteId, $tprojectId, $recursive, &$md)
    {
        $sdata = $this->tsuiteMgr->get_by_id($suiteId);
        $suiteName = $sdata ? strval($sdata['name'] ?? '') : '';
        $suiteName = $suiteName !== '' ? $suiteName : ('Suite ' . $suiteId);

        $md .= "## {$suiteName} (Suite ID: {$suiteId})\n";

        $topt = array('recursive' => testsuite::USE_RECURSIVE_MODE,
                      'excludeTC' => false);
        $test_spec = $this->tsuiteMgr->get_subtree($suiteId, $topt);
        $childNodes = isset($test_spec['childNodes']) ? $test_spec['childNodes'] : null;
        if (is_null($childNodes)) {
            return;
        }
        // Collect this suite's direct children.
        foreach ($childNodes as $cNode) {
            $nTable = $cNode['node_table'];
            if ($nTable == 'testsuites') {
                if ($recursive) {
                    $this->emitSuite($cNode['id'], $tprojectId, true, $md);
                }
            } elseif ($nTable == 'testcases') {
                $this->emitCase($cNode['id'], null, $tprojectId, $md);
            }
        }
    }

    protected function emitCase($tcaseId, $tcversionId, $tprojectId, &$md)
    {
        $tcaseId = intval($tcaseId);
        if (intval($tcversionId) <= 0) {
            $tcversionId = $this->resolveLatestVersionId($tcaseId);
        } else {
            $tcversionId = intval($tcversionId);
        }
        if ($tcversionId <= 0) {
            return;
        }
        $tcinfo = $this->tcaseMgr->get_by_id($tcaseId, $tcversionId, null, array('output' => 'essential'));
        if (is_null($tcinfo) || count($tcinfo) === 0) {
            return;
        }
        $row = $tcinfo[0];
        $versionId = $tcversionId;
        $title = strval($row['name'] ?? '');
        $extId = $this->getExtIdString($tcaseId, $tprojectId);
        $md .= self::tcBlock($this->tcaseMgr, $tcaseId, $versionId, $title, $extId, $tprojectId);
    }

    /**
     * Render a single test case block from its tcversion data.
     */
    protected static function tcBlock($tcaseMgr, $tcaseId, $tcversionId, $title, $extId, $tprojectId)
    {
        $md = "\n### TC-{$tcaseId}: {$title}\n";
        if ($extId !== '' && $extId !== null) {
            $md .= "- **ExternalID:** {$extId}\n";
        }

        // Importance
        $importance = 2;
        $steps = [];
        $preconditions = '';
        $tc = is_object($tcaseMgr) ? $tcaseMgr : null;
        if ($tc) {
            $data = self::loadTcVersion($tc, $tcaseId, $tcversionId);
            $importance = intval($data['importance'] ?? 2);
            $preconditions = strval($data['preconditions'] ?? '');
            $steps = $data['steps'] ?? [];
        }

        $impLabel = self::importanceLabel($importance);
        $md .= "- **Importance:** {$impLabel}\n";

        if ($preconditions !== '') {
            $md .= "- **Preconditions:** " . self::inline($preconditions) . "\n";
        }
        if (count($steps) > 0) {
            $md .= "- **Steps:**\n";
            foreach ($steps as $s) {
                $num = intval($s['step_number'] ?? 0);
                $acts = strval($s['actions'] ?? '');
                $exp = strval($s['expected_results'] ?? '');
                $md .= "     {$num}. " . self::inline($acts) . "\n";
                if ($exp !== '') {
                    $md .= "        *Expected:* " . self::inline($exp) . "\n";
                }
            }
        }
        if ($steps === [] && trim($preconditions) === '') {
            $md .= "- **Steps:**_none_\n";
        }
        $md .= "\n";
        return $md;
    }

    /**
     * Load tcversion details (importance, preconditions, steps) from rows.
     * Tries getStepsSimple for steps; version fields come from get_by_id using
     * the test case id + a specific tcversion id.
     */
    protected static function loadTcVersion($tcaseMgr, $tcaseId, $tcversionId)
    {
        $tcaseId = intval($tcaseId);
        $tcversionId = intval($tcversionId);
        $out = ['importance' => 2, 'preconditions' => '', 'steps' => []];
        if ($tcaseId > 0 && $tcversionId > 0) {
            try {
                // full_without_steps returns the whole TCV row (importance,
                // preconditions) without rendering steps; we fetch steps
                // separately via getStepsSimple to keep the markdown faithful.
                $info = $tcaseMgr->get_by_id($tcaseId, $tcversionId, null, array('output' => 'full_without_steps'));
            } catch (Exception $e) {
                $info = null;
            }
            if (!is_null($info) && count($info) > 0) {
                $r = $info[0];
                $out['importance'] = intval($r['importance'] ?? 2);
                $out['preconditions'] = strval($r['preconditions'] ?? '');
            }
        }
        try {
            $steps = $tcaseMgr->getStepsSimple($tcversionId);
        } catch (Exception $e) {
            $steps = null;
        }
        if (is_array($steps) && count($steps) > 0) {
            $out['steps'] = [];
            foreach ($steps as $s) {
                $out['steps'][] = [
                    'step_number' => intval($s['step_number'] ?? 0),
                    'actions' => strval($s['actions'] ?? ''),
                    'expected_results' => strval($s['expected_results'] ?? ''),
                ];
            }
        }
        return $out;
    }

    protected static function importanceLabel($v)
    {
        // TestLink canonical: HIGH=3, MEDIUM=2, LOW=1 (cfg/const.inc.php).
        $v = intval($v);
        if ($v === 1) { return 'Low'; }
        if ($v === 3) { return 'High'; }
        return 'Medium';
    }

    /**
     * Replace newlines/newlines-within-preconditions by spaces so the field
     * stays on a single MD bullet line.
     */
    protected static function inline($s)
    {
        return trim(str_replace(["\r\n", "\r", "\n"], ' ', (string) $s));
    }

    /**
     * Resolve the latest tcversion id for a test case.
     */
    protected function resolveLatestVersionId($tcaseId)
    {
        try {
            $last = $this->tcaseMgr->get_last_version_info(intval($tcaseId), array('output' => 'minimun'));
            if (is_array($last) && isset($last['id'])) {
                return intval($last['id']);
            }
        } catch (Exception $e) {
            // fall through
        }
        return 0;
    }

    /**
     * Return the external id string (e.g. 'TLU-42') for a test case.
     * getExternalID() returns [$identity, $prefix, $glue, $external]; we need
     * the fully-composed identity string.
     */
    protected function getExtIdString($tcaseId, $tprojectId)
    {
        $arr = $this->tcaseMgr->getExternalID($tcaseId, $tprojectId, $this->prefix);
        if (is_array($arr) && isset($arr[3]) && intval($arr[3]) > 0 && isset($arr[0])) {
            return strval($arr[0]);
        }
        return '';
    }

    protected function getSuiteNameForCase($tcaseId)
    {
        $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
        $db = $this->tcaseMgr->db;
        $rows = $db->get_recordset(
            "SELECT P.name AS name, P.id AS sid, C.id AS cid FROM {$tables['nodes_hierarchy']} C " .
            "JOIN {$tables['nodes_hierarchy']} P ON P.id = C.parent_id " .
            "WHERE C.id = " . intval($tcaseId));
        if (!is_null($rows) && count($rows) > 0) {
            return strval($rows[0]['name'] ?? ('Suite ' . $rows[0]['sid']));
        }
        return 'Suite 0';
    }
}
