<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 *
 * @filesource	bugzillaxmlrpcInterface.class.php
 * @author Francisco Mancardi
 *
 *
 * @internal revisions
 * @since 1.9.11
 * 20140531 - franciscom - contribution + refactoring adding new support methods
 * 
**/
require_once('Zend/Loader/Autoloader.php');
Zend_Loader_Autoloader::getInstance();

class bugzillaxmlrpcInterface extends issueTrackerInterface
{
  private $APIClient;
  private $issueDefaults;

  /**
   * Construct and connect to BTS.
   *
   * @param str $type (see tlIssueTracker.class.php $systems property)
   * @param xml $cfg
   **/
  function __construct($type,$config,$name)
  {
    $this->interfaceViaDB = false;
    // keep parent defaults (addReporter/addHandler) to avoid "Undefined array key"
    // warnings in issueTrackerInterface::buildViewBugLink() when rendering links
    $this->methodOpt['buildViewBugLink'] = array_merge(
        $this->methodOpt['buildViewBugLink'],
        array('addSummary' => true, 'colorByStatus' => false));
    $this->guiCfg = array('use_decoration' => true); // add [] on summary
    
    $this->name = $name;
    if( !$this->setCfg($config) )
    {
      return false;
    } 

    $this->completeCfg();
    $this->connect();
    
    // For bugzilla status code is not important.
    // Design Choice make it equal to verbose. Important bugzilla uses UPPERCASE 
    $this->defaultResolvedStatus = array();
    $this->defaultResolvedStatus[] = array('code' => 'RESOLVED', 'verbose' => 'RESOLVED');
    $this->defaultResolvedStatus[] = array('code' => 'VERIFIED', 'verbose' => 'VERIFIED');
    $this->defaultResolvedStatus[] = array('code' => 'CLOSED', 'verbose' => 'CLOSED');
    
    $this->setResolvedStatusCfg();
  }


  /**
   *
   * check for configuration attributes than can be provided on
   * user configuration, but that can be considered standard.
   * If they are MISSING we will use 'these carved on the stone values' 
   * in order	to simplify configuration.
   * 
   *
   **/
  function completeCfg()
  {
    // Issue #1619: setCfg() re-binds $this->cfg to a stdClass
    // (issueTrackerInterface.class.php:165), so a cfg document that PARSES but
    // carries no <uribase> element (e.g. '<testlink/>') has no such PROPERTY and
    // reading it raised "Undefined property: stdClass::$uribase" plus a PHP 8.1+
    // "trim(): Passing null to parameter #1" deprecation - both from this one
    // statement, both logged to the Event Viewer. Only the DIAGNOSTICS change:
    // for a no-<uribase> cfg the derived $base was ALREADY '/' pre-fix, and
    // every valid cfg yields a byte-identical $base.
    // is_scalar() covers a second shape of the same defect, and it is the one
    // that KILLED the request: a whitespace-only element ('<uribase>  </uribase>')
    // survives the SimpleXML -> json -> stdClass round-trip as an empty
    // SimpleXMLElement, i.e. a NESTED stdClass, so trim() raised
    // "TypeError: trim(): Argument #1 ($string) must be of type string,
    // stdClass given" (measured, pre-fix) and killed the whole request. A plain
    // (string) cast only moved that fatal to the issueDefaults loop below - and
    // that second site is what issue #1711 fixes.
    // is_scalar() cannot reject a legitimate value: setCfg() is the ONLY writer
    // of $this->cfg (issueTrackerInterface.class.php:116 then :165), so by the
    // time completeCfg() runs it is always a stdClass, never a SimpleXMLElement
    // (for which trim() would have worked).
    $uri = $this->cfg->uribase ?? '';
    $base = trim(is_scalar($uri) ? (string)$uri : '',"/") . '/'; // be sure no double // at end

    // Issue #1711: property_exists() is TRUE for a member that the setCfg()
    // SimpleXML -> json -> stdClass round-trip turned into a NESTED object, i.e. an
    // element-valued cfg field like '<urixmlrpc><x/></urixmlrpc>'. So the guards
    // below must test the VALUE, not just the presence: a non-scalar member is
    // treated as ABSENT and the derived default is built. Without this, the
    // '(string)$this->cfg->urixmlrpc' in createAPIClient() raised
    // "Error: Object of class stdClass could not be converted to string" - an Error,
    // NOT an Exception, so the catch(Exception) in connect() could not stop it.
    $this->cfg->urixmlrpc = $this->cfgStr('urixmlrpc', $base . 'xmlrpc.cgi');
    $this->cfg->uriview   = $this->cfgStr('uriview',   $base . 'show_bug.cgi?id=');
    $this->cfg->uricreate = $this->cfgStr('uricreate', $base);

    // username/password (login()) and product/component (addIssue()) are cast to
    // string as well. They are COERCED to '' instead of defaulted, and the property
    // is only ever touched when it already exists: canCreateViaAPI() is
    // property_exists()-based, so CREATING product/component here would silently
    // switch addIssue() from disabled to enabled.
    foreach(array('username','password','product','component') as $prop)
    {
      if( $this->cfgIsNotText($prop) )
      {
        $this->cfgWarn($prop, "using empty string");
        $this->cfg->$prop = '';
      }
    }

    $this->issueDefaults = array('version' => 'unspecified', 'severity' => 'Trivial',
                                 'op_sys' => 'All', 'priority' => 'Normal','platform' => "All",);
    foreach($this->issueDefaults as $prop => $default)
    {
      // Issue #1711: same defect, same class, one statement below the cast that
      // #1619 moved here. A nested stdClass here raised the same uncatchable Error
      // for 'version', 'severity', 'op_sys', 'priority' and 'platform'; falling
      // back to $default is precisely what the surrounding property_exists()
      // logic already intended for a missing value.
      if( $this->cfgIsNotText($prop) )
      {
        $this->cfgWarn($prop, "using default '$default'");
      }
      $this->cfg->$prop = $this->cfgStr($prop, $default);
    }
  }

  /**
   * Issue #1711: read a cfg member that is STRUCTURALLY KNOWN TO BE A STRING.
   *
   * setCfg() re-binds $this->cfg to a stdClass, so a non-text cfg field arrives as
   * something a (string) cast cannot convert and the cast raises an uncatchable
   * Error: an element-valued field ('<version><x/></version>', but also the far more
   * common '<platform/>' or '<platform>  </platform>') decodes to a NESTED stdClass,
   * a repeated field ('<platform>a</platform><platform>b</platform>') decodes to a
   * PHP array, and a field with attributes decodes to an object. A scalar is
   * returned byte-identically, so no legitimate configuration is changed; a member
   * that is never null on this path (see issueTrackerInterface.class.php:165).
   *
   * @param string $prop cfg member to read
   * @param string $default value to use when the member is missing or not a scalar
   * @return string
   **/
  private function cfgStr($prop,$default)
  {
    if( !property_exists($this->cfg,$prop) || $this->cfgIsNotText($prop) )
    {
      return $default;
    }
    return (string)$this->cfg->$prop;
  }

  /**
   * Issue #1711: is this cfg member present AND not a plain text value?
   * A missing member is NOT "not text" - the caller decides what a missing member
   * means (derived default vs. leave it absent).
   *
   * @param string $prop cfg member to test
   * @return bool
   **/
  private function cfgIsNotText($prop)
  {
    return property_exists($this->cfg,$prop) && !is_scalar($this->cfg->$prop);
  }

  /**
   * Issue #1711: report a cfg member that could not be used, naming the field (and
   * the tracker, so the row is attributable when several are configured) instead of
   * letting the reader of the Event Viewer guess which element of the XML is wrong.
   *
   * @param string $prop offending cfg member
   * @param string $action what was done about it
   **/
  private function cfgWarn($prop,$action)
  {
    tLog(__METHOD__ . " [$this->name] :: cfg field <$prop> is not a text value, $action", 'WARNING');
  }

  /**
   * useful for testing 
   *
   *
   **/
  function getAPIClient()
  {
    return $this->APIClient;
  }

  /**
   * checks id for validity
   *
   * @param string issueID
   *
   * @return bool returns true if the bugid has the right format, false else
   **/
  function checkBugIDSyntax($issueID)
  {
    return $this->checkBugIDSyntaxNumeric($issueID);
  }

  /**
   * establishes connection to the bugtracking system
   *
   * @return bool 
   *
   **/
  function connect()
  {
    try
    {
      // CRITIC NOTICE for developers
      // $this->cfg is a simpleXML Object, then seems very conservative and safe
      // to cast properties BEFORE using it.
      $this->createAPIClient();
      $this->connected = true;
    }
    catch(Exception $e)
    {
      $logDetails = '';
      foreach(array('uribase','apikey') as $v)
      {
        // Issue #1619: this catch block is the ERROR DIAGNOSTIC, so it must not
        // raise diagnostics of its own. Unguarded, "$v={$this->cfg->$v}" warned
        // "Undefined property: stdClass::$uribase" (the stdClass re-binding is
        // done by setCfg(), issueTrackerInterface.class.php:165) precisely when
        // the cfg was never populated - i.e. on the error path, where the log
        // line is the only clue. Now logs "uribase= / apikey=" instead.
        // is_scalar(): a cfg like '<apikey><x/></apikey>' decodes apikey to a
        // NESTED stdClass, and interpolating an object raises an Error that
        // catch(Exception) cannot catch - the log line could kill the request
        // it was trying to describe.
        $val = $this->cfg->$v ?? '';
        $logDetails .= "$v=" . (is_scalar($val) ? $val : '') . " / ";
      }
      $logDetails = trim($logDetails,'/ ');
      $this->connected = false;
      tLog(__METHOD__ . " [$logDetails] " . $e->getMessage(), 'ERROR');
    }
  }

 /**
  * 
  *
  **/
	function isConnected()
	{
		return $this->connected;
	}


 /**
  * 
  *
  **/
	public function getIssue($issueID)
	{
		$issue = null;

    $resp = array();
    $login = $this->login();		
    $resp = array_merge($resp,(array)$login['response']);


		$method = 'Bug.get';
		$args = array(array('ids' => array(intval($issueID)), 'permissive' => true));
		if (isset($login['userToken'])) 
    {
			$args[0]['Bugzilla_token'] = $login['userToken'];
		}
		$resp[$method] = $this->APIClient->call($method, $args);


    $op = $this->logout($login['userToken']);
    $resp = array_merge($resp,(array)$op['response']);


		if(count($resp['Bug.get']['faults']) == 0)
		{
			$issue = new stdClass();
      $issue->id = $issueID;
		  $issue->IDHTMLString = "<b>{$issueID} : </b>";
			$issue->statusCode = $issue->statusVerbose = $resp['Bug.get']['bugs'][0]['status'];
      $issue->isResolved = isset($this->resolvedStatus->byCode[$issue->statusCode]); 

			$issue->statusHTMLString = $this->buildStatusHTMLString($issue->statusVerbose);
			$issue->summary = $issue->summaryHTMLString = $resp['Bug.get']['bugs'][0]['summary'];
		}
    else
	  {
	    tLog(__METHOD__ . ' :: ' . $resp['Bug.get']['faults'][0]['faultString'], 'ERROR');
		}
		return $issue;
	}


	/**
	 * Returns status for issueID
	 *
	 * @param string issueID
	 *
	 * @return 
	 **/
	function getIssueStatusCode($issueID)
	{
		$issue = $this->getIssue($issueID);
		return !is_null($issue) ? $issue->statusCode : false;
	}

	/**
	 * Returns status in a readable form (HTML context) for the bug with the given id
	 *
	 * @param string issueID
	 * 
	 * @return string 
	 *
	 **/
	function getIssueStatusVerbose($issueID)
	{
    return $this->getIssueStatusCode($issueID);
	}

	/**
	 *
	 * @param string issueID
	 * 
	 * @return string 
	 *
	 **/
	function getIssueSummaryHTMLString($issueID)
	{
    $issue = $this->getIssue($issueID);
    $str = $issue->summaryHTMLString;
		if($this->guiCfg['use_decoration'])
		{
			$str = "[" . $str . "] ";	
		}
    return $str;
	}

  /**
	 * @param string issueID
   *
   * @return bool true if issue exists on BTS
   **/
  function checkBugIDExistence($issueID)
  {
    if(($status_ok = $this->checkBugIDSyntax($issueID)))
    {
      $issue = $this->getIssue($issueID);
      $status_ok = is_object($issue) && !is_null($issue);
    }
    return $status_ok;
  }


  /**
   * 
   *
   **/
	function createAPIClient()
	{
		// echo __METHOD__ .'<br>';
		try
		{
			$this->APIClient = new Zend_XmlRpc_Client((string)$this->cfg->urixmlrpc);
			$httpClient = new Zend_Http_Client();
			$httpClient->setCookieJar();
			$this->APIClient->setHttpClient($httpClient);
		}
		catch(Exception $e)
		{
			$this->connected = false;
            tLog(__METHOD__ .  $e->getMessage(), 'ERROR');
		}
	}	



    /**
     *
     * @author francisco.mancardi@gmail.com>
     **/
  public static function getCfgTemplate()
  {
    $template = "<!-- Template " . __CLASS__ . " -->\n" .
                "<issuetracker>\n" .
                "<username>USERNAME</username>\n" .
                "<password>PASSWORD</password>\n" .
                "<uribase>http://bugzilla.mozilla.org/</uribase>\n" .
                "<!-- In order to create issues from TestLink, you need to provide this MANDATORY info -->\n".
                "<product>BUGZILLA PRODUCT</product>\n" .					
                "<component>BUGZILLA PRODUCT</component>\n" .
                "<!-- This can be adjusted according Bugzilla installation. -->\n".
                "<!-- COMMENTED SECTION \n" .
                " There are defaults defined in bugzillaxmlrpcInterface.class.php. \n".
                "<version>unspecified</version>\n" .
                "<severity>Trivial</severity>\n" .
                "<op_sys>All</op_sys>\n" .
                "<priority>Normal</priority>\n" .
                "<platform>All</platform> --> \n".
                "</issuetracker>\n";
                
    return $template;
  }
  
  
  function getAccessibleProducts()
  {
    $issue = null;

    $resp = array();
    $login = $this->login();    
    $resp = array_merge($resp,(array)$login['response']);

    $method = 'Product.get_accessible_products';
    $args = array(array());
    if (isset($login['userToken'])) 
    {
      $args[0]['Bugzilla_token'] = $login['userToken'];
    }
    $itemSet = $this->APIClient->call($method, $args);
    
    $op = $this->logout($login['userToken']);
    $resp = array_merge($resp,(array)$op['response']);
    
    return $itemSet;
  }

  /**
   *
   */
  function getProduct($id)
  {
    $issue = null;
    $resp = array();
    $login = $this->login();    
    $resp = array_merge($resp,(array)$login['response']);
    
    $method = 'Product.get';
    $args = array(array('ids' => array(intval($id))));
    if (isset($login['userToken'])) 
    {
      $args[0]['Bugzilla_token'] = $login['userToken'];
    }
    $itemSet = $this->APIClient->call($method,$args);
    
    $op = $this->logout($login['userToken']);
    $resp = array_merge($resp,(array)$op['response']);

    return $itemSet; 	  
  }

    // good info from:
    // http://petehowe.co.uk/2010/example-of-calling-the-bugzilla-api-using-php-zend-framework/
    //
    // From BUGZILLA DOCS
    //
    // Returns
    // A hash with one element, id. This is the id of the newly-filed bug.
    // 
    // Errors
    // 
    // 51 (Invalid Object)
    //     The component you specified is not valid for this Product.
    // 
    // 103 (Invalid Alias)
    //     The alias you specified is invalid for some reason. See the error message for more details.
    //
    // 104 (Invalid Field)
    //     One of the drop-down fields has an invalid value, or a value entered in a text field is too long. 
    //     The error message will have more detail.
    //
    // 105 (Invalid Component)
    //     You didn't specify a component.
    //
    // 106 (Invalid Product)
    //     Either you didn't specify a product, this product doesn't exist, or you don't have permission 
    //     to enter bugs in this product.
    //
    // 107 (Invalid Summary)
    //     You didn't specify a summary for the bug.
    //
    // 504 (Invalid User)
    //     Either the QA Contact, Assignee, or CC lists have some invalid user in them. 
    //     The error message will have more details.
    // 
    
    
  function addIssue($summary,$description)
  {
    $issue = null;
    $resp = array();
    $login = $this->login();    
    $resp = array_merge($resp,(array)$login['response']);
    
    $method = 'Bug.create';
    $issue = array('product' => (string)$this->cfg->product,
                   'component' => (string)$this->cfg->component,
                   'summary' => $summary,
                   'description' => $description);


    foreach($this->issueDefaults as $prop => $default)
    {
      $issue[$prop] = (string)$this->cfg->$prop;
    }
    
    $args = array($issue);
    if (isset($login['userToken'])) 
    {
      $args[0]['Bugzilla_token'] = $login['userToken'];
    }

    
    $op = $this->APIClient->call($method,$args);
    if( ($op['status_ok'] = ($op['id'] > 0)) )
    {
      $op['msg'] = sprintf(lang_get('bugzilla_bug_created'),$summary,$issue['product']);
    }
    else
    {
      $msg = "Create BUGZILLA Ticket FAILURE ";
      $op= array('status_ok' => false, 'id' => -1, 
                 'msg' => $msg . ' - serialized issue:' . serialize($issue));
      tLog($msg, 'WARNING');
    }
      
    $logout = $this->logout($login['userToken']);
    $resp = array_merge($resp,(array)$logout['response']);

    return $op;
  }

 /**
  *
  **/
  function canCreateViaAPI()
  {
    return (property_exists($this->cfg, 'product') && property_exists($this->cfg, 'component'));
  }



 /**
  *
  **/
  private function login()
  {
    $args = array(array('login' => (string)$this->cfg->username, 
                        'password' => (string)$this->cfg->password,'remember' => 1));
    $ret = array();
    $ret['response']['User.login'] = $this->APIClient->call('User.login', $args);
    $ret['userToken'] = $ret['response']['User.login']['token'];
    return $ret;
  }  


 /**
  *
  **/
  private function logout($userToken=null)
  {
    $args = array(array());
    if( !is_null($userToken) ) 
    {
      $args[0]['Bugzilla_token'] = $userToken;
    }

    $ret = array();
    $ret['response']['User.logout'] = $this->APIClient->call('User.logout', $args);
    return $ret;
  }

}