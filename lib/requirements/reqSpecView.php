<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource  reqSpecView.php
 *
 * @internal legacy deep-link closed by the #1536 modernization parity pass:
 *   the modern screen (gui/templates/requirements/reqSpecView.html) plus BFF
 *   (api/reqspec, action=spec_view) render Requirements Specification view.
 *   Legacy accounts that deep-link into old controllers now land on the
 *   equivalent modern panel; no additional legacy props are resolved here.
 */

// Route to the modern twin, preserving the deep-link target identity.
header('Location: gui/templates/requirements/reqSpecView.html?req_spec_id=' .
       intval($_REQUEST['req_spec_id'] ?? $_REQUEST['id'] ?? 0) .
       '&tproject_id=' . intval($_REQUEST['tproject_id'] ?? 0) .
       '&tplan_id=' . intval($_REQUEST['tplan_id'] ?? 0));
exit;
