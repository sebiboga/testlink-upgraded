<?php
/**
 * Create Requirements from Mantis Issue XML — BFF alias (Refs #1960)
 *
 * URL: /api/reqmantisimport/index.php?action=init|import&req_spec_id=N
 *
 * The modern twin of lib/requirements/reqCreateFromIssueMantisXML.php was
 * implemented (Refs #1503) in api/reqfromissues/index.php; that workspace is
 * shared with the requirement-spec viewer toolbar. This endpoint is the stable
 * address kept for the RETIRED legacy controller (now a session-guarded 302
 * shim) so bookmarks and the dedicated screen gui/templates/requirements/
 * reqMantisImport.html keep working without duplicating the import engine.
 *
 * It delegates in-process to the shared implementation: identical JSON
 * contract, identical rights (mgt_view_req AND mgt_modify_req on the owning
 * project), identical Mantis XML mapping and duplicate handling.
 */

require __DIR__ . '/../reqfromissues/index.php';
