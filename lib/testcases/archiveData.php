<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  archiveData.php
 *
 * @internal legacy deep-link closed by the #1536 modernization parity pass:
 *   the 'Archive Data (keep data)' / test case version archive toggle is now
 *   hosted by the modern Test Case Editor (gui/templates/testcases/tcEdit.html)
 *   backed by the testcases BFF. Legacy accounts that deep-link here land on
 *   the modern editor panel for the requested test case.
 */

// Route to the modern twin, preserving the deep-link target identity.
header('Location: gui/templates/testcases/tcEdit.html?doAction=edit&tcase_id=' .
       intval($_REQUEST['id'] ?? $_REQUEST['tcase_id'] ?? 0) .
       '&tproject_id=' . intval($_REQUEST['tproject_id'] ?? 0) .
       '&tplan_id=' . intval($_REQUEST['tplan_id'] ?? 0));
exit;
