<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 *
 * @filesource  githubrestCodeTrackerInterface.class.php
 *
 * Native GitHub Code Tracker interface (issue #433).
 * Talks to the public GitHub REST API (https://api.github.com) over cURL,
 * without any external SDK. Supports:
 *   - branch / tag enumeration
 *   - commit listing (for a branch or commit sha)
 *   - pull-request listing
 *   - code-view / commit / pull-request link building
 *
 * Config is stored in codetrackers.cfg (XML wrapper, like the other
 * interfaces) but the modernized screen builds it from form fields:
 *
 * <codetracker>
 *   <repository>https://github.com/{owner}/{repo}</repository>
 *   <branch>main</branch>
 *   <token>ghp_xxx</token>
 *   <apibase>https://api.github.com/</apibase>
 * </codetracker>
 *
 * `token` is optional: public repositories can be read anonymously. For
 * private repositories a Personal Access Token (or GitHub App token) is
 * required. `apibase` optionally overrides the API endpoint to support
 * GitHub Enterprise Server.
 *
 * @since 2.0.1
 */
require_once(TL_ABS_PATH . '/lib/codetrackerintegration/codeTrackerInterface.class.php');

class githubrestCodeTrackerInterface extends codeTrackerInterface
{
  /** Sentinel used when no repository is configured yet. */
  const NOREPOSITORY = 'e18b741e13b2b1b09f2ac85615e37bae';

  /** Default GitHub REST API base (public GitHub). */
  const DEFAULT_APIBASE = 'https://api.github.com/';

  /** When the repo is specified as owner/repo with no host, this is the view host. */
  const DEFAULT_VIEWHOST = 'https://github.com';

  private $apiBase = '';
  private $viewBase = '';
  private $owner = null;
  private $repo = null;
  private $branch = null;
  private $token = null;
  private $api = null; // stdClass parsed config

  /**
   * @param string $type   (e.g. 'github' - unused beyond base contract)
   * @param string $config XML (or json) configuration string
   * @param string $name   tracker display name
   */
  function __construct($type, $config, $name)
  {
    $this->name = $name;
    $this->interfaceViaDB = false;

    if ($this->setCfg($config) && $this->checkCfg()) {
      $this->completeCfg();
      $this->connect();
      $this->guiCfg = array('use_decoration' => true);
    }
  }

  /**
   * Normalise/complete config. Because we build the config from form fields,
   * most values always exist; this fills any derivation gaps (e.g. view base).
   */
  function completeCfg()
  {
    $this->apiBase = $this->cfg->apibase ?? self::DEFAULT_APIBASE;
    $this->owner = isset($this->cfg->owner) ? trim((string)$this->cfg->owner) : null;
    $this->repo = isset($this->cfg->repo) ? trim((string)$this->cfg->repo) : null;
    $this->branch = isset($this->cfg->branch) ? trim((string)$this->cfg->branch) : null;
    $this->token = isset($this->cfg->token) ? trim((string)$this->cfg->token) : '';
    $this->viewBase = self::DEFAULT_VIEWHOST;
  }

  /** @return stdClass|null the raw parsed config */
  function getApi()
  {
    return $this->api;
  }

  /**
   * Returns the URL shown when entering code links.
   *
   * @return string
   */
  function getEnterCodeURL()
  {
    return $this->viewBase . '/';
  }

  /**
   * Parse config. Accepts an XML wrapper (legacy parity) OR a flat JSON
   * object. Stores the result as a stdClass and keeps a reference in $this->api.
   *
   * @param string $config
   * @return bool
   */
  function setCfg($config)
  {
    $config = trim((string)$config);
    if (strlen($config) === 0) {
      tLog(__METHOD__ . " - empty configuration for $this->name", 'ERROR');
      return false;
    }

    $obj = null;

    // JSON config (new UI for the github type) — parsed as-is.
    if ($config[0] === '{') {
      $obj = json_decode($config);
      if (json_last_error() !== JSON_ERROR_NONE) {
        tLog(__METHOD__ . ' - invalid JSON configuration: ' . json_last_error_msg(), 'ERROR');
        return false;
      }
    } else {
      // Legacy XML wrapper — reuse the base parsing (SimpleXML -> json -> stdClass).
      $obj = simplexml_load_string('<?xml version="1.0"?> ' . $config, 'SimpleXMLElement', LIBXML_NOCDATA);
      if ($obj === false) {
        tLog(__METHOD__ . ' - failure loading XML configuration', 'ERROR');
        return false;
      }
      $obj = json_decode(json_encode($obj));
      if (!is_object($obj)) {
        $obj = new stdClass();
      }
    }

    // Flatten: if repository is given as full URL or "owner/repo", split it.
    if (isset($obj->repository) && is_string($obj->repository)) {
      $this->splitRepository($obj->repository, $obj);
    } elseif (!isset($obj->owner)) {
      $obj->owner = null;
      $obj->repo = null;
    }

    $this->cfg = $obj;
    $this->api = $obj;
    return true;
  }

  /**
   * Given a repository string ('owner/repo' or a full URL) populate
   * $this->owner / $this->repo on $obj.
   */
  private function splitRepository($repository, $obj)
  {
    $repository = trim($repository);
    // strip scheme + host if a full URL was pasted
    $repository = preg_replace('#^https?://[^/]+/#', '', $repository);
    $repository = trim($repository, '/');
    $parts = explode('/', $repository);
    $obj->owner = isset($parts[0]) ? trim($parts[0]) : '';
    $obj->repo = isset($parts[1]) ? trim($parts[1]) : '';
    if (!isset($obj->branch)) {
      $obj->branch = $this->branch;
    }
  }

  /**
   * Validate the configuration.
   *
   * @return bool
   */
  function checkCfg()
  {
    $status_ok = true;

    $repoSet = isset($this->cfg->repository) && is_string($this->cfg->repository)
               ? trim($this->cfg->repository) : '';
    $ownerSet = isset($this->cfg->owner) && is_string($this->cfg->owner)
                ? trim($this->cfg->owner) : '';
    $repoNameSet = isset($this->cfg->repo) && is_string($this->cfg->repo)
                   ? trim($this->cfg->repo) : '';

    // A repository is only strictly required when we are NOT doing pure
    // code-link authoring (repository, branch) that TestLink needs at runtime.
    if ($repoSet === '' && ($ownerSet === '' || $repoNameSet === '')) {
      // allow "link only" usage: sentinel owner keeps the record valid.
      $this->cfg->owner = self::NOREPOSITORY;
      $this->cfg->repo = '';
    }

    if (isset($this->cfg->apibase) && is_string($this->cfg->apibase)) {
      $this->cfg->apibase = trim($this->cfg->apibase);
      if ($this->cfg->apibase !== '' && substr($this->cfg->apibase, -1) !== '/') {
        $this->cfg->apibase .= '/';
      }
    }

    return $status_ok;
  }

  /**
   * Establish (and verify) the connection to GitHub.
   *
   * @return bool
   */
  function connect()
  {
    try {
      // A repository is required to verify connectivity.
      if (empty($this->owner) || empty($this->repo)) {
        $this->connected = false;
        return;
      }

      $url = $this->apiBase . 'repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo);
      $data = $this->ghGet($url);
      $this->connected = is_object($data) && property_exists($data, 'full_name');
    } catch (Exception $e) {
      $this->connected = false;
      tLog(__METHOD__ . ' ' . $e->getMessage(), 'ERROR');
    }
  }

  /** @return bool */
  function isConnected()
  {
    return $this->connected;
  }

  /**
   * Raw authenticated GET against the GitHub API.
   *
   * @param string $url
   * @return mixed decoded JSON (object/array) or null on error
   */
  private function ghGet($url)
  {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 15,
      CURLOPT_HTTPHEADER => array(
        'Accept: application/vnd.github+json',
        'User-Agent: TestLink-CodeTracker',
        'X-GitHub-Api-Version: 2022-11-28',
      ),
    ));

    if (!empty($this->token)) {
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: application/vnd.github+json',
        'User-Agent: TestLink-CodeTracker',
        'X-GitHub-Api-Version: 2022-11-28',
        'Authorization: Bearer ' . $this->token,
      ));
    }

    $proxy = config_get('proxy');
    if (is_object($proxy) && isset($proxy->host) && $proxy->host) {
      curl_setopt($ch, CURLOPT_PROXY, $proxy->host);
      if (isset($proxy->port) && $proxy->port) {
        curl_setopt($ch, CURLOPT_PROXYPORT, $proxy->port);
      }
      if (isset($proxy->user) && $proxy->user) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy->user . ':' . ($proxy->password ?? ''));
      }
    }

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
      return null;
    }

    if ($httpCode >= 400) {
      return null;
    }

    $decoded = json_decode($result);
    return $decoded;
  }

  /**
   * Array of branch names for the configured repository.
   *
   * @return array|false
   */
  public function getBranches()
  {
    if (empty($this->owner) || empty($this->repo)) {
      return false;
    }
    $url = $this->apiBase . 'repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo)
         . '/branches?per_page=100';
    $data = $this->ghGet($url);
    if (!is_array($data)) {
      return false;
    }
    $ret = array();
    foreach ($data as $branch) {
      if (is_object($branch) && isset($branch->name)) {
        $ret[$branch->name] = $branch->name;
      }
    }
    return $ret;
  }

  /**
   * Array of tag names for the configured repository.
   *
   * @return array|false
   */
  public function getTags()
  {
    if (empty($this->owner) || empty($this->repo)) {
      return false;
    }
    $url = $this->apiBase . 'repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo)
         . '/tags?per_page=100';
    $data = $this->ghGet($url);
    if (!is_array($data)) {
      return false;
    }
    $ret = array();
    foreach ($data as $tag) {
      if (is_object($tag) && isset($tag->name)) {
        $ret[$tag->name] = $tag->name;
      }
    }
    return $ret;
  }

  /**
   * Commits for the configured repository.
   *
   * @param string|null $branch  branch or sha; defaults to configured branch
   * @return array|false  list of commit records {sha, short, message, author, date}
   */
  public function getCommits($branch = null)
  {
    if (empty($this->owner) || empty($this->repo)) {
      return false;
    }
    $sha = $branch ?: $this->branch;
    $url = $this->apiBase . 'repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo)
         . '/commits?per_page=50';
    if (!empty($sha)) {
      $url .= '&sha=' . rawurlencode($sha);
    }
    $data = $this->ghGet($url);
    if (!is_array($data)) {
      return false;
    }
    $ret = array();
    foreach ($data as $c) {
      if (!is_object($c)) {
        continue;
      }
      $short = isset($c->sha) ? substr((string)$c->sha, 0, 7) : '';
      $message = '';
      $author = '';
      $date = '';
      if (isset($c->commit) && is_object($c->commit)) {
        $message = isset($c->commit->message) ? trim((string)$c->commit->message) : '';
        // single-line first subject
        $nl = strpos($message, "\n");
        if ($nl !== false) {
          $message = substr($message, 0, $nl);
        }
        if (isset($c->commit->author) && is_object($c->commit->author)) {
          $author = isset($c->commit->author->name) ? (string)$c->commit->author->name : '';
          $date = isset($c->commit->author->date) ? (string)$c->commit->author->date : '';
        }
      }
      $ret[] = array(
        'sha' => $short,
        'short' => $short,
        'full' => isset($c->sha) ? (string)$c->sha : '',
        'message' => $message,
        'author' => $author,
        'date' => $date,
      );
    }
    return $ret;
  }

  /**
   * Pull requests for the configured repository.
   *
   * @param string $state open|closed|all
   * @return array|false list of PR records {number, title, state, head, base}
   */
  public function getPullRequests($state = 'open')
  {
    if (empty($this->owner) || empty($this->repo)) {
      return false;
    }
    $url = $this->apiBase . 'repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo)
         . '/pulls?state=' . rawurlencode($state) . '&per_page=50';
    $data = $this->ghGet($url);
    if (!is_array($data)) {
      return false;
    }
    $ret = array();
    foreach ($data as $pr) {
      if (!is_object($pr)) {
        continue;
      }
      $head = isset($pr->head) && is_object($pr->head) && isset($pr->head->ref)
              ? (string)$pr->head->ref : '';
      $base = isset($pr->base) && is_object($pr->base) && isset($pr->base->ref)
              ? (string)$pr->base->ref : '';
      $ret[] = array(
        'number' => isset($pr->number) ? intval($pr->number) : 0,
        'title' => isset($pr->title) ? (string)$pr->title : '',
        'state' => isset($pr->state) ? (string)$pr->state : '',
        'head' => $head,
        'base' => $base,
        'login' => (isset($pr->user) && is_object($pr->user) && isset($pr->user->login))
                   ? (string)$pr->user->login : '',
      );
    }
    return $ret;
  }

  /**
   * GitHub-specific commit link (overrides default buildViewCodeLink passthrough).
   *
   * @see codeTrackerInterface::buildViewCodeLink
   */
  function buildViewCodeLink($project_key, $repository_name, $code_path, $opt = null)
  {
    $branch_name = null;
    $commit_id = null;
    if (isset($opt['branch'])) {
      $branch_name = $opt['branch'];
    }
    if (isset($opt['commit_id'])) {
      $commit_id = $opt['commit_id'];
    }

    // Normalise code_path: GitHub exposes it relative to repo root; keep the
    // leading slash handling the same as legacy (strip a leading slash).
    $path = ltrim((string)$code_path, '/');

    $url = $this->buildViewCodeURL($project_key, $repository_name, $code_path, $branch_name, $commit_id);

    $link = "<a href='" . htmlspecialchars($url) . "' target='_blank'>";
    $link .= htmlspecialchars($path !== '' ? $path : $code_path);
    $link .= '</a>';

    $ret = new stdClass();
    $ret->link = $link;
    $ret->op = true;
    return $ret;
  }

  /**
   * Build a GitHub URL for a code path, commit or pull request.
   *
   * @param mixed  $project_key
   * @param mixed  $repository_name
   * @param mixed  $code_path
   * @param string $branch_name
   * @param string $commit_id
   *
   * @return string
   */
  function buildViewCodeURL($project_key, $repository_name, $code_path, $branch_name = null, $commit_id = null)
  {
    $owner = $this->owner ?: $project_key;
    $repo = $this->repo ?: $repository_name;
    $path = ltrim((string)$code_path, '/');

    if ($owner === '' || $repo === '') {
      return $this->viewBase . '/';
    }

    $base = $this->viewBase . '/' . rawurlencode($owner) . '/' . rawurlencode($repo);

    // Pull request references look like "PR-123" or "#123".
    if (preg_match('/^PR-(\d+)$/i', $path, $m) || preg_match('/^#(\d+)$/', $path, $m2)) {
      $num = isset($m[1]) ? $m[1] : $m2[1];
      return $base . '/pull/' . rawurlencode($num);
    }

    // Commit sha reference.
    if ($commit_id && strlen((string)$commit_id) >= 7) {
      return $base . '/commit/' . rawurlencode((string)$commit_id);
    }

    if ($branch_name) {
      return $base . '/blob/' . rawurlencode($branch_name) . ($path !== '' ? '/' . $path : '');
    }

    return $base;
  }

  /**
   * Configuration example shown on the legacy "show config example" path.
   *
   * @return string
   */
  public static function getCfgTemplate()
  {
    $tpl = '<!-- Template ' . __CLASS__ . " -->\n" .
           "<codetracker>\n" .
           "<repository>https://github.com/OWNER/REPO</repository>\n" .
           "<branch>main</branch>\n" .
           "<token>ghp_xxx or empty for public repos</token>\n" .
           "</codetracker>\n";
    return $tpl;
  }

  /**
   * Environment check: cURL extension must be available.
   *
   * @return array
   */
  public static function checkEnv()
  {
    $ret = array('status' => true, 'msg' => 'OK');
    if (!function_exists('curl_init')) {
      $ret['status'] = false;
      $ret['msg'] = 'cURL extension is required for the GitHub code tracker interface';
    }
    return $ret;
  }
}
