<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * Public share-link gateway (modernized, Refs #1541).
 *
 * Legacy direct links for external access to reports landed here and were
 * resolved inline (apikey + type auth, then a redirect to a lib/* target).
 * The modern implementation splits that into:
 *   - api/publiclink/index.php  -> BFF resolver (auth + type mapping, JSON)
 *   - gui/templates/links/publicLink.html -> resolver screen (ERR/redirect)
 *
 * This entry now ONLY forwards the browser to the modern resolver screen,
 * keeping every original query parameter. All existing share links keep
 * working unchanged:
 *   lnl.php?type=exec&id=N&apikey=K
 *   lnl.php?type=file&id=N&apikey=K
 *   lnl.php?type=metricsdashboard&apikey=K
 *   lnl.php?type=testplan&tproject_id=P&tplan_id=T&apikey=K ...
 */

// some session and settings stuff from original index.php
require_once('config.inc.php');
require_once('reports.cfg.php');
require_once('common.php');

doDBConnect($db);

// strip any control characters that could poison a Location header
$qs = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
$qs = str_replace(["\r", "\n"], '', $qs);

header('Location: ' . TL_BASE_HREF . 'gui/templates/links/publicLink.html' .
       ($qs !== '' ? '?' . $qs : ''), true, 302);
exit();