<?php
/**
 * mantisrestInterface — self-contained session-backed issue-tracker test double.
 *
 * WARNING: this is a TEST/verification double for the api/bugadd BFF regression
 * suite (Refs #1560), NOT a real Mantis REST integration. No shipped screen
 * references it; it is only instantiated when a test fixture registers an
 * `issuetrackers` row of type 24 (mantis/rest) whose cfg enables
 * userinteraction. It keeps every "issue" and "note" in the PHP session so the
 * link / create / add-note write paths of the modern Bug Add/Link popup can be
 * exercised end-to-end without any live bug tracker or network call.
 *
 * Because the legacy tracker factory derives the implementation class name from
 * the system code stored in the DB (`getImplementationForType()`,
 * type + api + 'Interface'), this double ships under the type-24 name. A real
 * Mantis REST interface can replace it freely - the file is self-contained and
 * inert until a DB row of type 24 pointing at it exists.
 *
 * @internal Revisions (#1560): introduced as the fixture tracker for the
 *           Bug Add/Link popup modernization test suite.
 */
require_once('issueTrackerInterface.class.php');

class mantisrestInterface extends issueTrackerInterface
{
    const TLU_PREFIX = '1560';

    /**
     * Local store shape:
     *   $_SESSION['tlu_mantis_rest_double'] = array(
     *       'issues' => array(id => array('summary','details','notes'=>array())),
     *       'counter' => int
     *   );
     */
    protected function store()
    {
        if (!isset($_SESSION['tlu_mantis_rest_double'])) {
            $_SESSION['tlu_mantis_rest_double'] = array('issues' => array(), 'counter' => 1000);
        }
        return $_SESSION['tlu_mantis_rest_double'];
    }

    protected function setStore($s)
    {
        $_SESSION['tlu_mantis_rest_double'] = $s;
    }

    public function connect()
    {
        $this->connected = true;
        return true;
    }

    public function isConnected()
    {
        return true;
    }

    /**
     * Numeric bug ids only (mantis-style). The id is considered to exist when
     * it was created through this double this session OR it belongs to the
     * reserved demo band 1560xxxx (fixture pre-seed band).
     */
    public function checkBugIDSyntax($issueID)
    {
        return is_numeric($issueID) && intval($issueID) > 0;
    }

    public function normalizeBugID($issueID)
    {
        return trim(strval($issueID));
    }

    protected function inDemoBand($issueID)
    {
        $n = intval($issueID);
        return $n >= 15600000 && $n <= 15609999;
    }

    public function checkBugIDExistence($issueID)
    {
        $id = strval($issueID);
        $s = $this->store();
        if (isset($s['issues'][$id])) {
            return true;
        }
        return $this->inDemoBand($id);
    }

    public function getBugIDMaxLength()
    {
        return 16;
    }

    public function getBugSummaryMaxLength()
    {
        return 100;
    }

    public function getEnterBugURL()
    {
        return 'https://issuetracker.example.org/enter_bug.cgi';
    }

    public function buildViewBugLink($issueID, $opt = null)
    {
        return 'https://issuetracker.example.org/view.php?id=' . intval($issueID);
    }

    public function getIssueSummary($issueID)
    {
        $s = $this->store();
        $id = strval($issueID);
        if (isset($s['issues'][$id])) {
            return $s['issues'][$id]['summary'];
        }
        return 'Issue ' . $id;
    }

    public function getIssueStatusVerbose($issueID)
    {
        return 'new';
    }

    public function getIssueStatusCode($issueID)
    {
        return '10';
    }

    /**
     * Create an issue locally. Returns the legacy tracker result contract
     * consumed by exec.inc.php addIssue().
     */
    public function addIssue($summary, $details, $opt = null)
    {
        $s = $this->store();
        // Cap the counter so created ids stay inside the reserved demo band
        // (str_pad to 5 digits => 156000001..156009999, all <=15609999).
        $s['counter'] = $s['counter'] >= 9999 ? 1 : $s['counter'] + 1;
        $id = self::TLU_PREFIX . str_pad(strval($s['counter']), 5, '0', STR_PAD_LEFT);
        $s['issues'][$id] = array(
            'summary' => strval($summary),
            'details' => strval($details),
            'notes'   => array(),
        );
        $this->setStore($s);
        return array('status_ok' => true, 'msg' => 'ok', 'id' => $id);
    }

    /**
     * Append a note to an existing local issue. Demo-band ids (which the
     * existence checks report as pre-existent fixtures) are accepted too, so
     * notes accumulate deterministically across PHP processes.
     */
    public function addNote($issueID, $note, $opt = null)
    {
        $s = $this->store();
        $id = strval($issueID);
        if (!isset($s['issues'][$id])) {
            if (!$this->inDemoBand($id)) {
                return array('status_ok' => false, 'msg' => 'issue does not exist');
            }
            $s['issues'][$id] = array('summary' => 'Fixtured issue ' . $id,
                                      'details' => '', 'notes' => array());
        }
        $s['issues'][$id]['notes'][] = strval($note);
        $this->setStore($s);
        return array('status_ok' => true, 'msg' => '');
    }

    // --- metadata selects (userinteraction=1 create form) -------------------

    public function getIssueTypesForHTMLSelect()
    {
        return array('items' => array(1 => 'Bug', 2 => 'Task', 3 => 'Improvement'),
                     'isMultiSelect' => false);
    }

    public function getPrioritiesForHTMLSelect()
    {
        return array('items' => array(1 => 'Low', 2 => 'Normal', 3 => 'High'),
                     'isMultiSelect' => false);
    }

    public function getVersionsForHTMLSelect()
    {
        return array('items' => array('2.0.1' => '2.0.1', '1.9.20' => '1.9.20'),
                     'isMultiSelect' => true);
    }

    public function getComponentsForHTMLSelect()
    {
        return array('items' => array('WebUI' => 'WebUI', 'API' => 'API'),
                     'isMultiSelect' => true);
    }
}