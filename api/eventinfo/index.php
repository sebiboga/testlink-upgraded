<?php
/**
 * Event Info popup BFF API
 * URL: /api/eventinfo/?action=show&id=<event id>
 *
 * Modern replacement for the legacy standalone popup lib/events/eventinfo.php
 * (+ gui/templates/dashio/events/eventinfo.tpl): the full record of a single
 * event, with a "Session information" section when the event belongs to a
 * transaction and an "Activity" section when it carries an object reference.
 *
 * Plain PHP, no framework, session-based auth, JSON in/out.
 * Refs #1556.
 */

require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();

header('Content-Type: application/json; charset=utf-8');

$db = new database(DB_TYPE);
doDBConnect($db);

/**
 * Emit a JSON payload and stop. Never lets a PHP warning/notice leak into the
 * response body (the front-end always expects a JSON document).
 */
function eviOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// --- Session auth (same contract as every other BFF) ----------------------
$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    eviOut(['status' => 'error', 'message' => 'Not authenticated'], 401);
}

$user = tlUser::getByID($db, $userId);
if (is_null($user)) {
    eviOut(['status' => 'error', 'message' => 'User not found'], 401);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET' && $method !== 'HEAD') {
    eviOut(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

// Legacy rights check (eventinfo.php checkRights): mgt_view_events.
if (!$user->hasRight($db, 'mgt_view_events')) {
    eviOut(['status' => 'error',
            'message' => 'Forbidden: mgt_view_events right required'], 403);
}

$action = isset($_GET['action']) ? strval($_GET['action']) : '';
if ($action !== 'show') {
    eviOut(['status' => 'error', 'message' => 'Unknown or missing action'], 400);
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    eviOut(['status' => 'error', 'message' => 'Missing or invalid event id'], 400);
}

$event = new tlEvent($id);
if ($event->readFromDB($db, tlEvent::TLOBJ_O_GET_DETAIL_TRANSACTION) < tl::OK) {
    eviOut(['status' => 'error', 'message' => 'Event not found'], 404);
}

// Legacy eventinfo.php resolves the user and shows the display name, falling
// back to the raw userID when the account no longer exists.
$userLogin = null;
$userDisplayName = null;
if (intval($event->userID) > 0) {
    $eventUser = tlUser::getByID($db, intval($event->userID));
    if ($eventUser) {
        $userLogin = $eventUser->login;
        $userDisplayName = $eventUser->getDisplayName();
    }
}

// Legacy template rendered the timestamp through localize_timestamp (session
// locale / configured timestamp_format). Reproduce it server-side.
$tsFormat = config_get('timestamp_format');
$timestampFormatted = tlStrftime($tsFormat, intval($event->timestamp));

$item = array(
    'id' => intval($event->dbID),
    'timestamp' => intval($event->timestamp),
    'timestampFormatted' => $timestampFormatted,
    'logLevelCode' => intval($event->logLevel),
    'logLevel' => isset(tlLogger::$logLevels[$event->logLevel])
                    ? tlLogger::$logLevels[$event->logLevel] : null,
    'description' => (string)$event->description,
    'source' => $event->source,
    'userID' => intval($event->userID) > 0 ? intval($event->userID) : null,
    'userName' => $userLogin,
    'userDisplayName' => $userDisplayName,
    'transactionID' => intval($event->transactionID) > 0
                        ? intval($event->transactionID) : null,
    'sessionID' => $event->sessionID,
    'objectID' => intval($event->objectID) > 0 ? intval($event->objectID) : null,
    'objectType' => $event->objectType,
    'activityCode' => $event->activityCode,
);

eviOut(['status' => 'ok', 'item' => $item]);
