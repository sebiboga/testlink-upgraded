<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 * 
 * @filesource	remote_exec.php
 * @author		Francisco Mancardi <francisco.mancardi@gmail.com>
 *
 * @internal revisions
 * 20110308 - franciscom - refactoring 
 */
require_once("../../config.inc.php");
require_once (TL_ABS_PATH . 'third_party'. DIRECTORY_SEPARATOR . 'xml-rpc/class-IXR.php');

/**
* Initiate the execution of a testcase through XML Server RPCs.
* All the object instantiations are done here.
* XML-RPC Server Settings need to be configured using the custom fields feature.
* Three fields each for testcase level and testsuite level are required.
* The fields are: server_host, server_port and server_path.
* Precede 'tc_' for custom fields assigned to testcase level.
*
* @param $tcaseInfo: 
* @param $serverCfg:
* @param $context
*
* @return map:
*         keys: 'result','notes','message'
*         values: 'result' -> (Pass, Fail or Blocked)
*                 'notes' -> Notes text
*                 'message' -> Message from server
*/
function executeTestCase($tcaseInfo,$serverCfg,$context)
{
	// system: to give info about conection to remote execution server
	// execution:
	// 	scheduled: domain 'now', 'future'
	//			   caller will use this attribute to write exec result (only if now)
	//	timestampISO: can be used by server to say the scheduled time.
	//				  To be used only if scheduled = 'future'
	//
	//   Complete date plus hours, minutes and seconds:
	//      YYYY-MM-DDThh:mm:ssTZD (eg 1997-07-16T19:20:30+01:00)
	//
	// where:
	//
	//     YYYY = four-digit year
	//     MM   = two-digit month (01=January, etc.)
	//     DD   = two-digit day of month (01 through 31)
	//     hh   = two digits of hour (00 through 23) (am/pm NOT allowed)
	//     mm   = two digits of minute (00 through 59)
	//     ss   = two digits of second (00 through 59)
	//     TZD  = time zone designator (Z or +hh:mm or -hh:mm)

	
	$ret = array('system' => array('status' => 'ok', 'msg' => 'ok'),
				 'execution' => array('scheduled' => '', 
				 					  'result' => '',
				 					  'resultVerbose' => '',
				 					  'notes' => '',
				 					  'timestampISO' => '') );
  

	$labels = init_labels(array('remoteExecServerConfigProblems' => null,
						 		'remoteExecServerConnectionFailure' => null));
						  
	
	$do_it = (!is_null($serverCfg) && !is_null($serverCfg["url"]) );
	if(!$do_it)
	{ 
		$ret['system']['status'] = 'configProblems';
		$ret['system']['msg'] = $labels['remoteExecServerConfigProblems'];						
	}
	
  	if($do_it)
  	{
		$xmlrpcClient = new IXR_Client($serverCfg["url"]);
		// A hung automation server must not pin the browser: without an explicit
		// timeout fsockopen() blocks for the OS default (minutes). 20s per call
		// keeps the 200-case run bounded; the answer becomes a
		// connectionFailure, which is what the legacy page shows.
		$xmlrpcClient->timeout = 20;
		if( is_null($xmlrpcClient) )
		{
			$do_it = false;
			$ret['system']['status'] = 'connectionFailure';
			$ret['system']['msg'] = $labels['remoteExecServerConnectionFailure'];						
		}
	}
	
 	if($do_it)
  	{
  		$args4call = array();
  		
  		// Execution Target
  		$args4call['testCaseName'] = $tcaseInfo['name'];
  		$args4call['testCaseID'] = $tcaseInfo['id'];
  		$args4call['testCaseVersionID'] = $tcaseInfo['version_id'];
  		
  		// Context
  		$args4call['testProjectID'] = $context['tproject_id'];
  		$args4call['testPlanID'] = $context['tplan_id'];
  		$args4call['platformID'] = $context['platform_id'];
  		$args4call['buildID'] = $context['build_id'];
  		$args4call['executionMode'] = 'now'; // domain: deferred,now
		
		$xmlrpcClient->query('executeTestCase',$args4call);
		$response = $xmlrpcClient->getResponse();

		if( is_null($response) )
		{
			// Houston we have a problem!!! (Apollo 13)
			$ret['system']['status'] = 'connectionFailure';
			$ret['system']['msg'] = $labels['remoteExecServerConnectionFailure'];						
			$ret['execution'] = null;
		}
		else
		{
			// A non conformant server can answer with something that is not a
			// result map at all (scalar / empty). Keep the legacy payload shape
			// and treat it as an unusable answer instead of emitting
			// "Trying to access array offset on value of type ..." (#1588).
			if( !is_array($response) )
			{
				$ret['system']['status'] = 'configProblems';
				$ret['system']['msg'] = $labels['remoteExecServerConfigProblems'];
				$ret['execution'] = null;
			}
			else
			{
			$ret['execution'] = $response;
			$ret['execution']['resultVerbose'] = '';
			
			if(array_key_exists('result', $response) && !is_null($response['result']) && is_scalar($response['result']))
			{	
				$code = trim(strval($response['result']));
				if( $code != '')
				{
					$resultsCfg = config_get('results');
					// #1588: the remote server answers with a status CODE
					// (results.status_code: p / f / b / n / x / u / a). A value
					// outside that domain used to raise an undefined-key
					// warning and produced an empty label - resolve it
					// defensively and keep an unknown code verbatim.
					$lcode = strtolower($code);
					$domain = null;
					if( isset($resultsCfg['code_status'][$lcode]) )
					{
						$domain = $resultsCfg['code_status'][$lcode];
					}
					else if( isset($resultsCfg['status_code'][$lcode]) )
					{
						// some servers send the domain word ('passed', ...)
						$domain = $lcode;
					}

					if( !is_null($domain) && isset($resultsCfg['status_label'][$domain]) )
					{
						$ret['execution']['resultVerbose'] = lang_get($resultsCfg['status_label'][$domain]);
					}
					else
					{
						$ret['execution']['resultVerbose'] = $code;
					}
				}
			}
			} // is_array($response)
		}
  	} 

	return $ret;
} // function end
?>