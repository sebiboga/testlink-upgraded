<?php
/**
 * Markdown Test Case Import parser (TestLink 2.0.1 modernization)
 *
 * Parses the structured Markdown produced by the CI agents
 * (see tmp/TLU_Test_Cases.md for the reference format):
 *
 *   # Title
 *   **Project:** TLU: TestLink Upgraded 2.0.1
 *   **Prefix:** TLU
 *   **Author:** admin
 *   **Date:** 2026-08-20
 *
 *   ## Test Suites Structure
 *   ```ascii tree with Suite IDs```
 *
 *   ## 1. Header (Suite ID: 12)
 *   ### TC-1.1: Some title
 *   - **Priority:** High
 *   - **Importance:** High
 *   - **Preconditions:** ...
 *   - **Steps:**
 *       1. Do something
 *          *Expected:* something happened
 *   - **Expected result:** ...
 *
 * Pure parser: no DB access, fully unit-testable.
 */
class markdownTcImport
{
    /** @var array collected parse errors: ['line' => N, 'message' => '...'] */
    public $errors = [];

    /**
     * Parse a whole markdown document.
     *
     * @return array {
     *   @var array  meta     project/prefix/author/date
     *   @var array  suites   [{index, name, id, cases:[...]}]
     *   @var int    caseCount
     * }
     */
    public function parse($markdown) {
        $this->errors = [];
        $lines = preg_split('/\r\n|\r|\n/', (string)$markdown);

        $result = [
            'meta' => ['project' => '', 'prefix' => '', 'author' => '', 'date' => ''],
            'suites' => [],
            'caseCount' => 0,
        ];

        $currentSuite = null;
        $currentCase = null;
        $currentSuiteKey = -1;
        $inSteps = false;
        $inCodeFence = false;

        foreach ($lines as $idx => $rawLine) {
            $lineNo = $idx + 1;
            $line = rtrim($rawLine);

            if (preg_match('/^\s*```/', $line)) {
                $inCodeFence = !$inCodeFence;
                continue;
            }
            if ($inCodeFence) {
                continue; // ascii tree blocks are informational only
            }

            // ---- metadata header -------------------------------------------
            if (preg_match('/^\s*\*\*(Project|Prefix|Author|Date):\*\*\s*(.*)$/u',
                           $line, $m)) {
                $key = strtolower($m[1]);
                $result['meta'][$key] = trim($m[2]);
                continue;
            }

            // ---- suite section: any ## heading starts a new suite ---------------
            // Handles all formats:
            //   ## 1. Header (Suite ID: 12)
            //   ## Suite 38: Regression — Issue #706
            //   ## Suite #461 — xmlrpc ...
            //   ## Regression — Issue #551: ...
            //   ## User Profile (userInfo.html)
            //   ## Modernize — Issue #660: ...
            if (preg_match('/^##\s+(?:Test Suites Structure|Summary)\s*$/u', $line)) {
                continue; // skip the tree overview / summary section
            }
            if (preg_match('/^##\s+(.+)$/u', $line, $m)) {
                $suiteName = trim($m[1]);
                // Extract optional (Suite ID: N) from end
                $suiteId = 0;
                if (preg_match('/\(\s*Suite\s+ID:\s*(\d+)\s*\)\s*$/iu', $suiteName, $sm)) {
                    $suiteId = intval($sm[1]);
                    $suiteName = trim(preg_replace('/\(\s*Suite\s+ID:\s*\d+\s*\)\s*$/iu', '', $suiteName));
                }
                $currentSuite = [
                    'index' => count($result['suites']) + 1,
                    'name' => $suiteName,
                    'id' => $suiteId,
                    'cases' => [],
                ];
                self::flushPending($result, $currentSuiteKey, $currentCase);
                $result['suites'][] = $currentSuite;
                $currentSuiteKey = count($result['suites']) - 1;
                $currentCase = null;
                $inSteps = false;
                $stepsFieldSeen = false;
                continue;
            }

            // ---- test case block: "### TC-1.1: Title" ----------------------
            if (preg_match('/^###\s+(TC-[\w.\-]+)\s*:\s*(.+)$/u', $line, $m)) {
                if (is_null($currentSuite)) {
                    $this->errors[] = [
                        'line' => $lineNo,
                        'message' => 'Test case outside of a suite section',
                    ];
                    continue;
                }
                self::flushPending($result, $currentSuiteKey, $currentCase);
                $currentCase = [
                    'tcId' => trim($m[1]),
                    'title' => trim($m[2]),
                    'priority' => '',
                    'importance' => 2,
                    'preconditions' => '',
                    'steps' => [],
                    'expectedResult' => '',
                    'externalId' => '',
                    'line' => $lineNo,
                ];
                // pending case is flushed when the next section starts (or EOF)
                $result['caseCount']++;
                $inSteps = false;
                $stepsFieldSeen = false;
                continue;
            }

            // ---- non-TC "###" heading (narrative, e.g. "### Test Data") -----
            // Flush the pending case and surface it as a parser warning instead
            // of silently merging/abandoning the content (see #1422).
            if (preg_match('/^###\s+(.+)$/u', $line, $m)) {
                if (!is_null($currentCase)) {
                    self::flushPending($result, $currentSuiteKey, $currentCase);
                    $this->errors[] = [
                        'line' => $lineNo,
                        'message' => 'Ignored non-test-case heading: ' . trim($m[1]),
                    ];
                }
                $inSteps = false;
                $stepsFieldSeen = false;
                continue;
            }

            if (is_null($currentCase)) {
                continue;
            }

            // ---- field bullets ---------------------------------------------
            // "Steps" accepts a suffix ("Steps (pre-fix repro):") so step
            // blocks with renamed headers are recognized too (see #1422).
            if (preg_match('/^\s*-\s*\**(Priority|Importance|Preconditions|' .
                           'Steps(?:[^:]*?)|Expected[^:]*?[Rr]esult|' .
                           'External[^:]*?[Ii][Dd])\**\s*:\s*(.*)$/u',
                           $line, $m)) {
                // Normalize the label: "Steps (pre-fix repro)" -> steps,
                // "Expected Result"/"Expected result" -> expected result, etc.
                $labelRaw = strtolower(trim($m[1]));
                if (strpos($labelRaw, 'steps') === 0) {
                    $field = 'steps';
                } elseif (strpos($labelRaw, 'expected') === 0) {
                    $field = 'expected result';
                } elseif (strpos($labelRaw, 'external') === 0) {
                    $field = 'externalid';
                } else {
                    $field = $labelRaw;
                }
                // Strip leading/trailing ** bold markers that leak from
                // markdown **Field:** value format (colon sits inside bold)
                $value = preg_replace('/^\*{1,2}\s*/', '', trim($m[2]));
                $value = preg_replace('/\s*\*{1,2}$/', '', $value);
                switch ($field) {
                    case 'priority':
                        $currentCase['priority'] = $value;
                        $currentCase['importance'] = $this->importanceFromLabel($value);
                        break;
                    case 'importance':
                        $imp = $this->importanceFromLabel($value);
                        if ($imp > 0) {
                            $currentCase['importance'] = $imp;
                        }
                        break;
                    case 'preconditions':
                        $currentCase['preconditions'] = $value;
                        break;
                    case 'steps':
                        $inSteps = true;
                        $stepsFieldSeen = true;
                        // Don't create a spurious step from "- **Steps:**" header
                        // (value would be empty or just bold markers)
                        break;
                    case 'expected result':
                        $currentCase['expectedResult'] = $value;
                        break;
                    case 'externalid':
                        $currentCase['externalId'] = trim($value);
                        break;
                }
                continue;
            }

            // ---- numbered steps (indented or not) ---------------------------
            if ($inSteps && preg_match('/^\s*(\d+)\.\s+(.*)$/', $line, $m)) {
                $this->addStep($currentCase, intval($m[1]), $m[2]);
                continue;
            }
            if ($inSteps && preg_match('/^\s{2,}(.*)$/', $line, $m)) {
                $cont = trim($m[1]);
                if ($cont === '' || count($currentCase['steps']) === 0) {
                    continue;
                }
                $lastStep = count($currentCase['steps']) - 1;
                if (preg_match('/^\*{1,2}Expected\s*:?\*{1,2}\s*(.*)$/iu', $cont, $em)) {
                    $currentCase['steps'][$lastStep]['expected_results'] =
                        trim($currentCase['steps'][$lastStep]['expected_results']);
                    $currentCase['steps'][$lastStep]['expected_results'] =
                        trim($em[1]);
                } elseif (preg_match('/^(.*?)\s*\*{1,2}Expected\s*:?\*{1,2}\s*(.*)$/iu',
                                     $cont, $em)) {
                    $currentCase['steps'][$lastStep]['actions'] =
                        trim($currentCase['steps'][$lastStep]['actions'] . "\n" . trim($em[1]));
                    $currentCase['steps'][$lastStep]['expected_results'] = trim($em[2]);
                } else {
                    $currentCase['steps'][$lastStep]['actions'] =
                        trim($currentCase['steps'][$lastStep]['actions'] . "\n" . $cont);
                }
                continue;
            }

            // ---- plain "- " bullets as acceptance-style steps ---------------
            // Cases without an explicit "- **Steps:**" field (e.g. Suite 40
            // blocks) express the whole procedure as a bullet list; import
            // each bullet as a step action instead of dropping it (see #1422).
            if (!$stepsFieldSeen && preg_match('/^\s*-\s+(.+)$/u', $line, $bm)) {
                $text = preg_replace('/^\*{1,2}\s*/', '', trim($bm[1]));
                $text = preg_replace('/\s*\*{1,2}$/', '', $text);
                if ($text !== '' && $text !== 'Expected') {
                    $currentCase['steps'][] = [
                        'step_number' => count($currentCase['steps']) + 1,
                        'actions' => $text,
                        'expected_results' => '',
                        'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL,
                    ];
                    $inSteps = true;
                }
                continue;
            }

            // multi-line preconditions continuation
            if (trim($line) !== '' && $currentCase['preconditions'] !== ''
                && empty($currentCase['steps'])
                && $currentCase['expectedResult'] === ''
                && !preg_match('/^#/', $line)) {
                $currentCase['preconditions'] .= "\n" . trim($line);
            }
        }
        self::flushPending($result, $currentSuiteKey, $currentCase);
        unset($currentSuite, $currentCase);

        return $result;
    }

    /**
     * Append a finished case (plain value copy) into its suite exactly once,
     * when the next section starts or the document ends.
     */
    private static function flushPending(&$result, $suiteKey, &$case) {
        if ($case !== null && $suiteKey >= 0
            && isset($result['suites'][$suiteKey])) {
            $result['suites'][$suiteKey]['cases'][] = $case;
        }
        $case = null;
    }

    /**
     * Split "1. Do X *Expected:* Y" style single-line steps too.
     */
    private function addStep(&$testCase, $number, $text) {
        $text = trim($text);
        // Strip stray ** bold markers
        $text = preg_replace('/^\*{1,2}\s*/', '', $text);
        $text = preg_replace('/\s*\*{1,2}$/', '', $text);
        $expected = '';
        if (preg_match('/^(.*?)\s*\*{1,2}Expected\s*:?\*{1,2}\s*(.*)$/iu', $text, $m)) {
            $text = trim($m[1]);
            $expected = trim($m[2]);
        }
        $testCase['steps'][] = [
            'step_number' => $number,
            'actions' => $text,
            'expected_results' => $expected,
            'execution_type' => TESTCASE_EXECUTION_TYPE_MANUAL,
        ];
    }

    /**
     * Map High/Medium/Low labels to TestLink importance (3/2/1).
     * Returns 0 when the label is not recognized.
     */
    public function importanceFromLabel($label) {
        $l = strtolower(trim((string)$label));
        if ($l === '') {
            return 0;
        }
        if (strpos($l, 'high') !== false || strpos($l, 'critic') !== false) {
            return 3;
        }
        if (strpos($l, 'low') !== false || strpos($l, 'minor') !== false) {
            return 1;
        }
        if (strpos($l, 'med') !== false || strpos($l, 'norm') !== false) {
            return 2;
        }
        return 0;
    }
}
