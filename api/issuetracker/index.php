<?php
require_once(__DIR__ . '/../../config.inc.php');
require_once('common.php');

doSessionStart();

require_once(__DIR__ . '/../_guard.php');
bffSameOriginGuard();


header('Content-Type: application/json');

$db = new database(DB_TYPE);
doDBConnect($db);

$userId = $_SESSION['userID'] ?? null;
if (!$userId || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

$path = $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/api/issuetracker(/index\.php)?#', '', $path);
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];
$segments = array_values(array_filter(explode('/', $path)));

function out($data) { echo json_encode($data); exit; }
function getBody() { return json_decode(file_get_contents('php://input'), true) ?? []; }

function trackerToJSON($item, $mgr) {
    $typeDescr = '';
    if (isset($mgr->types[$item['type']])) {
        $typeDescr = $mgr->types[$item['type']];
    }
    $typeLabel = '';
    if (isset($mgr->systems[$item['type']])) {
        $spec = $mgr->systems[$item['type']];
        $typeLabel = $spec['type'];
    }
    return [
        'id' => intval($item['id']),
        'name' => $item['name'],
        'type' => intval($item['type']),
        'typeLabel' => $typeLabel,
        'typeDescr' => $typeDescr,
        'cfg' => $item['cfg'] ?? '',
        'implementation' => $item['implementation'] ?? '',
    ];
}

$mgr = new tlIssueTracker($db);

if ($method === 'GET' && ($path === '/' || $path === '' || $path === '/index.php')) {
    $all = $mgr->getAll(['output' => 'add_link_count']);
    $items = [];
    if ($all) {
        foreach ($all as $item) {
            $items[] = trackerToJSON($item, $mgr);
        }
    }
    out(['status' => 'ok', 'items' => $items, 'total' => count($items)]);
}

if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'meta' && isset($segments[1]) && $segments[1] === 'types') {
    $systems = $mgr->getSystems(['status' => 'all']);
    $items = [];
    foreach ($systems as $code => $spec) {
        $items[] = [
            'code' => intval($code),
            'type' => $spec['type'],
            'api' => $spec['api'],
            'enabled' => $spec['enabled'],
            'label' => $spec['type'] . ' (Interface: ' . $spec['api'] . ')',
        ];
    }
    out(['status' => 'ok', 'items' => $items]);
}

if ($method === 'GET' && isset($segments[0]) && is_numeric($segments[0])) {
    $item = $mgr->getByID(intval($segments[0]));
    if (!$item) { http_response_code(404); out(['status' => 'error', 'message' => 'Issue tracker not found']); }
    out(['status' => 'ok', 'item' => trackerToJSON($item, $mgr)]);
}

if ($method === 'POST' && empty($segments)) {
    $body = getBody();
    $name = trim($body['name'] ?? '');
    $type = intval($body['type'] ?? 0);
    $cfg = $body['cfg'] ?? '';

    if (empty($name)) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Name is required']);
    }

    $it = new stdClass();
    $it->name = $name;
    $it->type = $type;
    $it->cfg = $cfg;

    $result = $mgr->create($it);
    if ($result['status_ok']) {
        $item = $mgr->getByID($result['id']);
        out(['status' => 'ok', 'item' => trackerToJSON($item, $mgr)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => $result['msg']]);
    }
}

if ($method === 'PUT' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $existing = $mgr->getByID($id);
    if (!$existing) { http_response_code(404); out(['status' => 'error', 'message' => 'Issue tracker not found']); }

    $body = getBody();
    $it = new stdClass();
    $it->id = $id;
    $it->name = isset($body['name']) ? trim($body['name']) : $existing['name'];
    $it->type = isset($body['type']) ? intval($body['type']) : intval($existing['type']);
    $it->cfg = isset($body['cfg']) ? $body['cfg'] : ($existing['cfg'] ?? '');

    $result = $mgr->update($it);
    if ($result['status_ok']) {
        $item = $mgr->getByID($id);
        out(['status' => 'ok', 'item' => trackerToJSON($item, $mgr)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => $result['msg']]);
    }
}

if ($method === 'DELETE' && isset($segments[0]) && is_numeric($segments[0])) {
    $id = intval($segments[0]);
    $existing = $mgr->getByID($id);
    if (!$existing) { http_response_code(404); out(['status' => 'error', 'message' => 'Issue tracker not found']); }

    $result = $mgr->delete($id);
    if ($result['status_ok']) {
        out(['status' => 'ok', 'item' => trackerToJSON($existing, $mgr)]);
    } else {
        http_response_code(400);
        out(['status' => 'error', 'message' => $result['msg']]);
    }
}

// ============================== GitHub OAuth ==============================

function gh_oauth_config() {
    static $cfg = false;
    if ($cfg !== false) {
        return $cfg;
    }
    global $tlCfg;
    $local = __DIR__ . '/../../cfg/oauth_github_local.inc.php';
    if (is_file($local)) {
        include $local;
    }
    $c = $tlCfg->OAuthServers['github'] ?? null;
    if (!$c) {
        return $cfg = null;
    }
    $cid = (string)($c['oauth_client_id'] ?? '');
    $sec = (string)($c['oauth_client_secret'] ?? '');
    if ($cid === '' || strpos($cid, 'CHANGE') !== false || $sec === '' || strpos($sec, 'CHANGE') !== false) {
        return $cfg = null;
    }
    return $cfg = $c;
}

function gh_state() {
    return $_SESSION['gh_oauth'] ?? array();
}

function gh_set($key, $val) {
    if (!isset($_SESSION['gh_oauth']) || !is_array($_SESSION['gh_oauth'])) {
        $_SESSION['gh_oauth'] = array();
    }
    $_SESSION['gh_oauth'][$key] = $val;
}

function gh_token() {
    $s = gh_state();
    return $s['token'] ?? null;
}

function gh_has_token() {
    return (bool)gh_token();
}

function gh_oauth_url() {
    return gh_base_url() . '/api/issuetracker/index.php/oauth/callback';
}

function gh_base_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8082';
    return $scheme . '://' . $host;
}

function gh_redirect_uri() {
    $s = gh_state();
    return $s['redirect_uri'] ?? gh_oauth_url();
}

function gh_curl_json($url, $headers, $post = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return array('error' => 'curl: ' . $err);
    }
    $json = json_decode($body, true);
    return is_array($json) ? $json : array('error' => 'invalid JSON response: ' . substr($body, 0, 200));
}

function gh_api_headers($token) {
    return array(
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'User-Agent: testlink-upgraded',
        'X-GitHub-Api-Version: 2022-11-28',
    );
}

function gh_token_max_age() {
    $cfg = gh_oauth_config();
    return (int)($cfg['oauth_token_max_age'] ?? 30 * 86400) ?: 30 * 86400;
}

function gh_tracker_type($mgr) {
    foreach (array('enabled', 'all') as $status) {
        $systems = $mgr->getSystems(array('status' => $status));
        foreach ($systems as $code => $spec) {
            if (($spec['type'] ?? '') === 'github' && ($spec['api'] ?? '') === 'rest') {
                return intval($code);
            }
        }
    }
    return 0;
}

// GET /oauth/start -> {status, url}
if ($method === 'GET' && ($segments[0] ?? '') === 'oauth' && ($segments[1] ?? '') === 'start') {
    $cfg = gh_oauth_config();
    if (!$cfg) {
        http_response_code(503);
        out(['status' => 'error', 'message' => 'GitHub OAuth is not configured (missing oauth_client_id/oauth_client_secret)']);
    }
    $state = bin2hex(random_bytes(16));
    gh_set('state', $state);
    gh_set('redirect_uri', gh_oauth_url());
    $returnUrl = trim((string)($_GET['return'] ?? ''));
    if ($returnUrl !== '') {
        gh_set('return_url', $returnUrl);
    }
    $params = http_build_query(array(
        'client_id'    => $cfg['oauth_client_id'],
        'redirect_uri' => gh_redirect_uri(),
        'scope'        => 'repo',
        'state'        => $state,
        'allow_signup' => 'false',
    ));
    out(['status' => 'ok', 'url' => 'https://github.com/login/oauth/authorize?' . $params]);
}

// GET /oauth/callback?code&state -> exchanges token, redirects back to UI
if ($method === 'GET' && ($segments[0] ?? '') === 'oauth' && ($segments[1] ?? '') === 'callback') {
    $cfg = gh_oauth_config();
    if (!$cfg) {
        http_response_code(503);
        out(['status' => 'error', 'message' => 'GitHub OAuth is not configured (missing oauth_client_id/oauth_client_secret)']);
    }
    $gotState = trim((string)($_GET['state'] ?? ''));
    if ($gotState === '' || $gotState !== (gh_state()['state'] ?? '')) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'OAuth state mismatch (possible CSRF)']);
    }
    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Missing authorization code']);
    }
    $tokenResp = gh_curl_json(
        'https://github.com/login/oauth/access_token',
        array('Accept: application/json'),
        http_build_query(array(
            'client_id'     => $cfg['oauth_client_id'],
            'client_secret' => $cfg['oauth_client_secret'],
            'code'          => $code,
            'redirect_uri'  => gh_redirect_uri(),
        ))
    );
    if (empty($tokenResp['access_token'])) {
        http_response_code(502);
        out(['status' => 'error', 'message' => 'Failed to exchange OAuth code: ' . ($tokenResp['error_description'] ?? $tokenResp['error'] ?? 'unknown error')]);
    }
    $token = $tokenResp['access_token'];
    $userResp = gh_curl_json('https://api.github.com/user', gh_api_headers($token));
    $login = (string)($userResp['login'] ?? '');
    gh_set('token', $token);
    gh_set('user', $login);
    gh_set('created_at', time());
    $return = gh_state()['return_url'] ?? '';
    if ($return === '') {
        $return = '/gui/templates/issuetracker/issuetrackerView.html';
    }
    $sep = (strpos($return, '?') === false) ? '?' : '&';
    header('Location: ' . $return . $sep . 'oauth=connected');
    exit;
}

// GET /oauth/repos -> list user's repos
if ($method === 'GET' && ($segments[0] ?? '') === 'oauth' && ($segments[1] ?? '') === 'repos') {
    if (!gh_has_token()) {
        http_response_code(401);
        out(['status' => 'error', 'message' => 'Not connected to GitHub']);
    }
    $repos = array();
    $page = 1;
    do {
        $resp = gh_curl_json(
            'https://api.github.com/user/repos?per_page=100&page=' . $page . '&sort=updated',
            gh_api_headers(gh_token())
        );
        if (!is_array($resp) || empty($resp)) {
            break;
        }
        foreach ($resp as $repo) {
            if (empty($repo['full_name']) || empty($repo['name'])) {
                continue;
            }
            $repos[] = array(
                'full_name' => $repo['full_name'],
                'owner'     => $repo['owner']['login'] ?? '',
                'repo'      => $repo['name'],
                'private'   => (bool)($repo['private'] ?? false),
                'push'      => (bool)(($repo['permissions']['push'] ?? false) || (($repo['permissions']['admin'] ?? false))),
            );
        }
        $page++;
        if ($page > 10) {
            break;
        }
    } while (count($resp) === 100);
    usort($repos, function ($a, $b) {
        return strcmp($a['full_name'], $b['full_name']);
    });
    out(['status' => 'ok', 'user' => gh_state()['user'] ?? '', 'repos' => $repos]);
}

// GET /oauth/token-status -> connected, age, expiry
if ($method === 'GET' && ($segments[0] ?? '') === 'oauth' && ($segments[1] ?? '') === 'token-status') {
    $s = gh_state();
    $maxAge = gh_token_max_age();
    $age = 0;
    $expired = false;
    $expiresIn = 0;
    if (gh_has_token()) {
        $age = time() - (int)($s['created_at'] ?? time());
        $expired = $age >= $maxAge;
        $expiresIn = max(0, $maxAge - $age);
    }
    out(array(
        'status'       => 'ok',
        'connected'    => gh_has_token(),
        'user'         => $s['user'] ?? '',
        'repo'         => $s['repo'] ?? '',
        'age_days'     => round($age / 86400, 1),
        'expires_in'   => $expiresIn,
        'expired'      => $expired,
        'max_age'      => $maxAge,
    ));
}

// POST /oauth/create -> build GitHub tracker from picked repo, link to project
if ($method === 'POST' && ($segments[0] ?? '') === 'oauth' && ($segments[1] ?? '') === 'create') {
    if (!gh_has_token()) {
        http_response_code(401);
        out(['status' => 'error', 'message' => 'Not connected to GitHub']);
    }
    $body = getBody();
    $repo = trim((string)($body['repo'] ?? ''));
    $tprojectId = intval($body['tproject_id'] ?? 0);
    if (strpos($repo, '/') === false) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid repo, expected owner/name']);
    }
    list($owner, $repoName) = explode('/', $repo, 2);
    if ($owner === '' || $repoName === '' || strpos($repoName, '/') !== false) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'Invalid repo, expected owner/name']);
    }
    $type = gh_tracker_type($mgr);
    if (!$type) {
        http_response_code(400);
        out(['status' => 'error', 'message' => 'GitHub issue tracker interface not found in system types']);
    }
    $user = (string)(gh_state()['user'] ?? $owner);
    $xml = "<issuetracker>\n<user>{$user}</user>\n<apikey>" . gh_token() . "</apikey>\n<url>https://api.github.com</url>\n<owner>{$owner}</owner>\n<repo>{$repoName}</repo>\n</issuetracker>";
    $name = $repo;

    // Track by tracker NAME (= owner/repo). On the GLOBAL page several GitHub
    // trackers may coexist, so update in place ONLY when the picked repo is
    // the SAME one the project is already linked to (session restore / token
    // refresh). A new repo name creates a NEW tracker; an existing one is
    // reused by name. The previously linked tracker is never overwritten.
    $existing = $mgr->getByName($name);
    $linked = $tprojectId ? $mgr->getLinkedTo($tprojectId) : null;
    $isLinkedSame = $existing !== null && $linked !== null &&
                    intval($linked['issuetracker_id']) === intval($existing['id']);

    $id = 0;
    if ($existing) {
        $id = intval($existing['id']);
        if ($isLinkedSame) {
            $it = new stdClass();
            $it->id = $id;
            $it->name = $name;
            $it->type = $type;
            $it->cfg = $xml;
            $res = $mgr->update($it);
            if (!($res['status_ok'] ?? 0)) {
                http_response_code(400);
                out(['status' => 'error', 'message' => $res['msg'] ?? 'update failed']);
            }
        }
    } else {
        $it = new stdClass();
        $it->name = $name;
        $it->type = $type;
        $it->cfg = $xml;
        $res = $mgr->create($it);
        $id = isset($res['id']) ? intval($res['id']) : 0;
        if (!$id || !($res['status_ok'] ?? 0)) {
            http_response_code(400);
            out(['status' => 'error', 'message' => $res['msg'] ?? 'create failed']);
        }
    }

    if ($tprojectId && $id) {
        $mgr->link($id, $tprojectId);
    }

    if ($id) {
        gh_set('repo', $repo);
        $item = $mgr->getByID($id);
        out(['status' => 'ok', 'item' => trackerToJSON($item, $mgr)]);
    }
    http_response_code(400);
    out(['status' => 'error', 'message' => $res['msg'] ?? 'create failed']);
}

http_response_code(404);
out(['status' => 'error', 'message' => 'Not found']);
