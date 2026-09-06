<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/ 
 * This script is distributed under the GNU General Public License 2 or later.
 * 
 * TLSmarty class is TestLink wraper for GUI templates processing. 
 * The class is loaded via common.php to all pages.
 * 
 * @filesource	tlsmarty.inc.php
 * @package 	  TestLink
 * @author 		  Martin Havlat
 * @copyright 	2005-2020, TestLink community 
 * @link 		    http://www.testlink.org/
 * @link 		    http://www.smarty.net/ 
 *
 *
 */

/** in this way you can switch ext js version in easy way,
	To use a different version of Sencha (Old EXT-JS) that provided with TL */
if( !defined('TL_EXTJS_RELATIVE_PATH') ) {
    define('TL_EXTJS_RELATIVE_PATH','third_party/ext-js' );
}

if(!defined('TL_USE_LOG4JAVASCRIPT') ) {
  define('TL_USE_LOG4JAVASCRIPT',0);
}


/** 
 * The next two functions was moved here from common.php */
function translate_tc_status($status_code) {
	$resultsCfg = config_get('results'); 
	$verbose = lang_get('test_status_not_run');
	if( $status_code != '') {
		$suffix = $resultsCfg['code_status'][$status_code];
		$verbose = lang_get('test_status_' . $suffix);
	}
	return $verbose;
}

/** 
 * function is registered in tlSmarty class
 * @uses function translate_tc_status
 * @todo should be moved to tlSmarty class
 */
function translate_tc_status_smarty($params, $smarty) {
	$the_ret = translate_tc_status($params['s']);
	if(	isset($params['var']) ) {
		$smarty->assign($params['var'], $the_ret);
	} else {
		return $the_ret;
	}
}

/**
 * Should be used to prevent certain templates to only get included once per page load. 
 * For example javascript includes, such as ext-js.
 *
 * Usage (in template):
 * <code>
 * {if guard_header_smarty(__FILE__)}
 *     template code
 *     <script src="big-library.js type="text/javascript"></script>
 * {/if}
 * </code>
 */
function guard_header_smarty($file) {
	static $guarded = array();
	$status_ok = false;
	
	if (!isset($guarded[$file])) {
		$guarded[$file] = true;
		$status_ok = true;
	}
	return $status_ok;
}

/**
 * TestLink wrapper for external Smarty class
 * @package 	TestLink
 */
class TLSmarty extends Smarty {
  private $tlImages;
  private $tlIMGTags;
  var $tlTemplateCfg;
	private $dashioHome;

  function __construct() {
    global $tlCfg;
    global $g_tpl;
    
    $basehref = isset($_SESSION['basehref']) 
                ? $_SESSION['basehref'] : TL_BASE_HREF;

    $my_locale = isset($_SESSION['locale']) 
                 ? $_SESSION['locale'] : TL_DEFAULT_LOCALE;

    parent::__construct();
    
    $main = TL_ABS_PATH . 'gui/templates/dashio/';
    $this->template_dir = 
             ['main' => $main,
              'attach' => $main . 'attachments/',
              'execInc' => $main . 'execute/include/',
              'feedback' => $main . 'feedback/',
              'include' => $main . 'include/',
              'tcaseInc' => $main . 'testcases/include/',
              'tcaseLbl' => $main . 'testcases/labels/'
             ];
                          
    $this->config_dir = TL_ABS_PATH . 'gui/templates/conf';
    $this->compile_dir = TL_TEMP_PATH;

    // 20200222
    // Can not access $this->dashioHome in templates
    // without doing the ->assign().
    // I declare the variable anyway, to be able to access
    // it from PHP code
    //
    $this->dashioHome = 'gui/templates/dashio/dashio-template/';
    $this->assign('dashioHome', $this->dashioHome);

    $this->assign('dashioHomeURL', $basehref . $this->dashioHome);

    // FontAwesome CSS location
    $fontAwesomeHome = $basehref . $this->dashioHome . 'lib/fontawesome-free-6.7.2-web/';
    $this->assign('fontawesomeHomeURL', $fontAwesomeHome);

    // ----------------------------------------------------------    
    $testproject_coloring = $tlCfg->gui->testproject_coloring;
    $testprojectColor = $tlCfg->gui->background_color ; 
    $this->assign('testprojectColor', $testprojectColor);
    
    
    if ($tlCfg->smarty_debug) {
      $this->debugging = true;
      tLog("Smarty debug window = ON");
    }


    // --------------------------------------------------------------
    // Must be initialized to avoid log on TestLink Event Viewer due to undefined variable.
    // This means that optional/missing parameters on include can not be used.
    //
    // Good refactoring must be done in future, to create group of this variable
    // with clear names that must be a hint for developers, to understand where this
    // variables are used.
    
    // inc_head.tpl
    $this->assign('SP_html_help_file',null);
    $this->assign('menuUrl',null);
    $this->assign('args',null);
    $this->assign('additionalArgs',null);
    $this->assign('pageTitle',null);
    $this->assign('printPreferences',null);
    
    $this->assign('css_only',null);
    $this->assign('body_onload',null);
    
    // inc_attachments.tpl
    $this->assign('attach_tableStyles',"font-size:12px");
    $this->assign('attach_tableClassName',"simple");
    $this->assign('attach_inheritStyle',0);
    $this->assign('attach_show_upload_btn',1);
    $this->assign('attach_show_title',1);
    $this->assign('attach_downloadOnly',false);
    
    // inc_help.tpl
    $this->assign('inc_help_alt',null);
    $this->assign('inc_help_title',null);
    $this->assign('inc_help_style',null);
    $this->assign('show_help_icon',true);
            
    $this->assign('tplan_name',null);
    $this->assign('name',null);
    // -------------------------------------------------------------
    
    $this->assign('basehref', $basehref);
    $this->assign('css', $basehref . TL_TESTLINK_CSS);
    $this->assign('use_custom_css', 0);
    if(!is_null($tlCfg->custom_css) && $tlCfg->custom_css != '') {
      $this->assign('use_custom_css', 1);
      $this->assign('custom_css', 
        $basehref . TL_THEME_CSS_DIR . $tlCfg->custom_css);
    }
    
    $this->assign('locale', $my_locale);
     
    //
    $stdTPLCfg = ['tcViewViewer.inc' => '',
                  'tcbody.inc' => '',
                  'steps.inc' => '',
                  'aliens.inc' => '',
                  'keywords.inc' => '',
                  'relations.inc' => '', 
                  'quickexec.inc' => '',
                  'platforms.inc' => '',
                  'attributesLinearForViewer.inc' => '', 
                  'steps_horizontal.inc' => '',
                  'steps_vertical.inc' => ''];

    array_walk($stdTPLCfg, function (&$value, $key) {
      $bbb = "testcases/include/";
      return $value =  $bbb . $key . '.tpl';
    });
 
    $stdTPLCfg['exec_test_spec.inc'] = '';
    $stdTPLCfg['exec_img_controls.inc'] = '';
    $stdTPLCfg['exec_controls.inc'] = '';
    $stdTPLCfg['exec_show_tc_exec.inc'] = '';
    $stdTPLCfg['exec_tc_relations.inc'] = '';
    $stdTPLCfg['add_issue_on_step.inc'] = '';
    $stdTPLCfg['create_issue.inc'] = '';
    $stdTPLCfg['execSetResultsBulk.inc'] = '';
    $stdTPLCfg['execSetResultsJS.inc'] = '';
    $stdTPLCfg['execSetResultsRemoteExec.inc'] = '';
    $stdTPLCfg['execSetResultsUtils.inc'] = '';
    $stdTPLCfg['issueTrackerMetadata.inc'] = '';
    $stdTPLCfg['issue_inputs_on_step.inc'] = '';

    array_walk($stdTPLCfg, function (&$value, $key) {
      $bbb = "execute/include/";
      if ($value == '') {
        return $value =  $bbb . $key . '.tpl';
      }
    });
 
    $stdTPLCfg['showScriptsTable.inc'] = 
      'include/showScriptsTable.inc.tpl';


    // -----------------------------------------------------------------------------
    // load configuration
    $this->assign('session',isset($_SESSION) ? $_SESSION : null);
    $this->assign('tlCfg',$tlCfg);
    $this->assign('tplConfig',array_merge($stdTPLCfg,(array)$g_tpl));
    $this->assign('gsmarty_gui',$tlCfg->gui);
    $this->assign('gsmarty_spec_cfg',config_get('spec_cfg'));
    $this->assign('gsmarty_attachments',config_get('attachments'));
    
    $this->assign('pageCharset',$tlCfg->charset);
    $this->assign('tlVersion',TL_VERSION);
    $this->assign('testproject_coloring',null);
    
    	
    // -----------------------------------------------------------------------------
    // define a select structure for {html_options ...}
    $this->assign('gsmarty_option_yes_no', array(0 => lang_get('No'), 1 => lang_get('Yes')));
    $this->assign('gsmarty_option_priority', array(HIGH => lang_get('high_priority'), 
                                                   MEDIUM => lang_get('medium_priority'), 
                                                   LOW => lang_get('low_priority')));
    
    $this->assign('gsmarty_option_importance', array(HIGH => lang_get('high_importance'), 
                                                     MEDIUM => lang_get('medium_importance'), 
                                                     LOW => lang_get('low_importance')));
       
    $wkf = array();
    $xcfg = config_get('testCaseStatus');
    foreach($xcfg as $human => $key) {
      $wkf[$key] = lang_get('testCaseStatus_' . $human);
    }  
    $this->assign('gsmarty_option_wkfstatus',$wkf);


    // this allows unclosed <head> tag to add more information and link; see inc_head.tpl
    $this->assign('openHead', 'no');
    
    // there are some variables which should not be assigned for template but must be initialized
    // inc_head.tpl
    $this->assign('jsValidate', null);
    $this->assign('jsTree', null);
    $this->assign('editorType', null);
    	
    	
    // user feedback variables (used in inc_update.tpl)
    $this->assign('user_feedback', null);
    $this->assign('feedback_type', ''); // Possibile values: soft
    $this->assign('action', 'updated'); //todo: simplify (remove) - use user_feedback
    $this->assign('sqlResult', null); //todo: simplify (remove) - use user_feedback
    
    $this->assign('refresh', 'no');
    $this->assign('result', null);
    
    // $this->assign('optLocale',config_get('locales'));
    $this->assign('gsmarty_href_keywordsView',
    			        ' "lib/keywords/keywordsView.php?tproject_id=%s%" ' . ' target="mainframe" class="bold" ' .
    			        ' title="' . lang_get('menu_manage_keywords') . '"');
    

    $this->assign('gsmarty_href_platformsView',
                  ' "lib/platforms/platformsView.php?tproject_id=%s%" ' . ' target="mainframe" class="bold" ' .
                  ' title="' . lang_get('menu_manage_platforms') . '"');

    $this->assign('gsmarty_html_select_date_field_order',
                  $tlCfg->locales_html_select_date_field_order[$my_locale]);
                  
    $this->assign('gsmarty_date_format',$tlCfg->locales_date_format[$my_locale]);
    
    // add smarty variable to be able to set localized date format on datepicker
    $this->assign('gsmarty_datepicker_format',
                  str_replace('%','',$tlCfg->locales_date_format[$my_locale]));
                  
    $this->assign('gsmarty_timestamp_format',$tlCfg->locales_timestamp_format[$my_locale]);
    
    // -----------------------------------------------------------------------------
    // Images
    $this->tlImages = tlSmarty::getImageSet();
    $this->tlIMGTags = tlSmarty::getIMGTagsSet();
    
    // getImageSet() returns FontAwesome markup, so these composites wrap the
    // icon rather than pointing an <img> at it.
    $msg = lang_get('show_hide_api_info');
    $this->tlImages['toggle_api_info'] =  "<span class=\"clickable\" title=\"{$msg}\" " .
    								" onclick=\"showHideByClass('span','api_info');event.stopPropagation();\" " .
    								" align=\"left\">{$this->tlImages['api_info']}</span>";

    $msg = lang_get('show_hide_direct_link');
    $this->tlImages['toggle_direct_link'] = "<span class=\"clickable\" title=\"{$msg}\" " .
    						  		                      " onclick=\"showHideByClass('div','direct_link');event.stopPropagation();\" " .
    						  		                      " align=\"left\">{$this->tlImages['direct_link']}</span>";
    
    // Some useful values for Sort Table Engine
    $this->tlImages['sort_hint'] = '';
    switch (TL_SORT_TABLE_ENGINE)
    {
      case 'kryogenix.org':
        $sort_table_by_column = lang_get('sort_table_by_column');
        $this->tlImages['sort_hint'] = "<span title=\"{$sort_table_by_column}\" " .
        						                   " align=\"left\">{$this->tlImages['sort']}</span>";
        
        $this->assign("noSortableColumnClass","sorttable_nosort");
      break;
      
      default:
        $this->assign("noSortableColumnClass",'');
      break;
    }

    // Do not move!!!
    $this->assign("tlImages",$this->tlImages);
    $this->assign("tlIMGTags",$this->tlIMGTags);
    
    // Register functions
    $this->registerPlugin("function","lang_get", "lang_get_smarty");
    $this->registerPlugin("function","localize_date", "localize_date_smarty");
    $this->registerPlugin("function","localize_timestamp", "localize_timestamp_smarty");
    $this->registerPlugin("function","localize_tc_status","translate_tc_status_smarty");
      
    $this->registerPlugin("modifier","basename","basename");
    $this->registerPlugin("modifier","dirname","dirname");

    // Call to smarty filter that adds a CSRF filter to all form elements
    if(isset($tlCfg->csrf_filter_enabled) && 
       $tlCfg->csrf_filter_enabled === TRUE && function_exists('smarty_csrf_filter')) {
          $this->registerFilter('output','smarty_csrf_filter');
    }

  } // end of function TLSmarty()

  /**
   *
   */
  function getImages() {
    return $this->tlImages;
  }

  /**
   *
   */
  static function getImageSet() {
    $iconKeys = array(
      'active', 'activity', 'account', 'add', 'add2set', 'api_info', 'assign_task',
      'bug', 'bug_link_tl_to_bts', 'bug_create_into_bts', 'bug_link_tl_to_bts_disabled',
      'bug_create_into_bts_disabled', 'bug_add_note', 'bullet', 'bulkOperation', 'calendar',
      'checked', 'choiceOn', 'clear', 'clear_notes', 'clipboard', 'check_ok', 'check_ko',
      'cog', 'copy_attachments', 'create_copy', 'create_from_xml', 'date', 'delete',
      'demo_mode', 'delete_disabled', 'disconnect', 'disconnect_small', 'direct_link',
      'duplicate', 'edit', 'edit_icon', 'email', 'events', 'eye', 'vorsicht', 'export',
      'export_import', 'execute', 'executed', 'exec_icon', 'exec_passed', 'exec_failed',
      'exec_blocked', 'execution', 'execution_order', 'execution_duration', 'export_excel',
      'export_for_results_import', 'ghost_item', 'user_group', 'heads_up', 'help', 'history',
      'history_small', 'home', 'import', 'import_results', 'inactive', 'info', 'info_small',
      'insert_step', 'item_link', 'link_to_report', 'lock', 'lock_open', 'log_message',
      'log_message_small', 'logout', 'magnifier', 'move_copy', 'new_f2_16', 'note_edit',
      'note_edit_greyed', 'on', 'off', 'order_alpha', 'plugins', 'public', 'private',
      'remove', 'reorder', 'report', 'report_word', 'requirements', 'relations', 'resequence', 'reset',
      'saveForBaseline', 'summary_small', 'sort', 'steps', 'table', 'testcases_table_view',
      'testcase_execution_type_automatic', 'testcase_execution_type_manual', 'test_specification',
      'toggle_all', 'user', 'user_badge', 'upload', 'upload_greyed', 'warning', 'wrench',
      'test_status_not_run', 'test_status_passed', 'test_status_failed', 'test_status_blocked',
      'test_status_passed_next', 'test_status_failed_next', 'test_status_blocked_next', 'keyword_add',
      'toggle_api_info', 'toggle_direct_link', 'sort_hint'
    );

    $dummy = array();
    foreach ($iconKeys as $key) {
      $faIcon = self::getFontAwesomeIcon($key);
      $dummy[$key] = '<i class="fa ' . $faIcon . '" aria-hidden="true"></i>';
    }

    $imi = config_get('images');
    if(count($imi) >0) {
      $dummy = array_merge($dummy,$imi);
    }
    return $dummy;
	}

  static function getFontAwesomeIcon($iconKey) {
    $faIcons = array(
      'active' => 'fa-check-circle',
      'activity' => 'fa-heartbeat',
      'account' => 'fa-user-circle',
      'add' => 'fa-plus',
      'add2set' => 'fa-plus-square',
      'api_info' => 'fa-info-circle',
      'assign_task' => 'fa-tasks',
      'bug' => 'fa-bug',
      'bug_link_tl_to_bts' => 'fa-link',
      'bug_create_into_bts' => 'fa-plus-circle',
      'bug_link_tl_to_bts_disabled' => 'fa-link',
      'bug_create_into_bts_disabled' => 'fa-plus-circle',
      'bug_add_note' => 'fa-edit',
      'bullet' => 'fa-circle',
      'bulkOperation' => 'fa-list',
      'calendar' => 'fa-calendar',
      'checked' => 'fa-check',
      'choiceOn' => 'fa-check-circle',
      'clear' => 'fa-trash-alt',
      'clear_notes' => 'fa-trash-alt',
      'clipboard' => 'fa-clipboard',
      'check_ok' => 'fa-lightbulb',
      'check_ko' => 'fa-times-circle',
      'cog' => 'fa-cog',
      'copy_attachments' => 'fa-copy',
      'create_copy' => 'fa-files-o',
      'create_from_xml' => 'fa-file-code',
      'date' => 'fa-calendar',
      'delete' => 'fa-trash-alt',
      'demo_mode' => 'fa-smile-o',
      'delete_disabled' => 'fa-trash-alt',
      'disconnect' => 'fa-plug',
      'disconnect_small' => 'fa-plug',
      'direct_link' => 'fa-link',
      'duplicate' => 'fa-files-o',
      'edit' => 'fa-edit',
      'edit_icon' => 'fa-edit',
      'email' => 'fa-envelope',
      'events' => 'fa-bell',
      'eye' => 'fa-eye',
      'vorsicht' => 'fa-exclamation-triangle',
      'export' => 'fa-download',
      'export_import' => 'fa-exchange',
      'execute' => 'fa-play',
      'executed' => 'fa-play',
      'exec_icon' => 'fa-play',
      'exec_passed' => 'fa-check-circle',
      'exec_failed' => 'fa-times-circle',
      'exec_blocked' => 'fa-stop-circle',
      'execution' => 'fa-play',
      'execution_order' => 'fa-sort-numeric-asc',
      'execution_duration' => 'fa-hourglass-end',
      'export_excel' => 'fa-file-excel-o',
      'export_for_results_import' => 'fa-download',
      'ghost_item' => 'fa-question-circle',
      'user_group' => 'fa-users',
      'heads_up' => 'fa-lightbulb',
      'help' => 'fa-question-circle',
      'history' => 'fa-history',
      'history_small' => 'fa-history',
      'home' => 'fa-home',
      'import' => 'fa-upload',
      'import_results' => 'fa-upload',
      'inactive' => 'fa-times-circle',
      'info' => 'fa-info-circle',
      'info_small' => 'fa-info-circle',
      'insert_step' => 'fa-plus-square',
      'item_link' => 'fa-link',
      'link_to_report' => 'fa-link',
      'lock' => 'fa-lock',
      'lock_open' => 'fa-unlock',
      'log_message' => 'fa-history',
      'log_message_small' => 'fa-history',
      'logout' => 'fa-sign-out',
      'magnifier' => 'fa-search',
      'move_copy' => 'fa-files-o',
      'new_f2_16' => 'fa-file',
      'note_edit' => 'fa-edit',
      'note_edit_greyed' => 'fa-edit',
      'on' => 'fa-toggle-on',
      'off' => 'fa-toggle-off',
      'order_alpha' => 'fa-sort-alpha-asc',
      'plugins' => 'fa-plug',
      'public' => 'fa-globe',
      'private' => 'fa-lock',
      'remove' => 'fa-times',
      'reorder' => 'fa-bars',
      'report' => 'fa-file-pdf-o',
      'report_word' => 'fa-file-word-o',
      'requirements' => 'fa-list-ul',
      'relations' => 'fa-sitemap',
      'resequence' => 'fa-arrows-v',
      'reset' => 'fa-undo',
      'saveForBaseline' => 'fa-save',
      'summary_small' => 'fa-info-circle',
      'sort' => 'fa-sort',
      'steps' => 'fa-th-list',
      'table' => 'fa-table',
      'testcases_table_view' => 'fa-table',
      'testcase_execution_type_automatic' => 'fa-robot',
      'testcase_execution_type_manual' => 'fa-user',
      'test_specification' => 'fa-file-text',
      'toggle_all' => 'fa-check-square-o',
      'user' => 'fa-user',
      'user_badge' => 'fa-user-circle',
      'upload' => 'fa-upload',
      'upload_greyed' => 'fa-upload',
      'warning' => 'fa-exclamation-triangle',
      'wrench' => 'fa-wrench',
      'test_status_not_run' => 'fa-circle-o',
      'test_status_passed' => 'fa-check-circle',
      'test_status_failed' => 'fa-times-circle',
      'test_status_blocked' => 'fa-stop-circle',
      'test_status_passed_next' => 'fa-check-circle',
      'test_status_failed_next' => 'fa-times-circle',
      'test_status_blocked_next' => 'fa-stop-circle',
      'keyword_add' => 'fa-tag',
      'toggle_api_info' => 'fa-info-circle',
      'toggle_direct_link' => 'fa-link',
      'sort_hint' => 'fa-sort'
    );

    return isset($faIcons[$iconKey]) ? $faIcons[$iconKey] : $iconKey;
  }

  /**
   *
   */
  static function getIMGTagsSet() {
    $burl = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : TL_BASE_HREF;
    $imgLoc = $burl . TL_THEME_IMG_DIR;
    // var_dump($imgLoc);
 

    // $dummy = array('checked' => '<img src="' . $imgLoc . 'apply_f2_16.png">');
    $dummy = array('displayOnExec' => '<i class="fa fa-desktop"></i>'
                   ,'cog' => '<i class="fa fa-cog" aria-hidden="true"></i>'
                  );

    $msg = lang_get('show_hide_direct_link');
    $dummy['toggle_direct_link'] = 
      "<i class=\"fas fa-link\" title=\"{$msg}\" alt=\"{$msg}\" " .
      " onclick=\"showHideByClass('div','direct_link');event.stopPropagation();\" " .
      "></i>";

    // var_dump($dummy);
    return $dummy;
  }

  /**
   * Every template that draws the left side menu includes aside.tpl, which
   * gates the whole menu on $gui->showMenu and builds its links from
   * $gui->uri. Each page builds its own $gui and most of them never call
   * initUserEnv(), so on those pages the sidebar rendered as an empty strip.
   *
   * Fill in only what the page left unset, right before rendering, so no
   * page loses values it deliberately computed itself.
   */
  protected function addMenuContext() {
    global $db;

    $gui = $this->getTemplateVars('gui');

    // Refs #1021: anonymous object-api_key sessions (setUpEnvForAnonymousAccess
    // sets userID = -1) have no usable identity to build a menu context from
    // (initUserEnv -> get_accessible_for_user(tlUser::getByID(0)=null) would
    // fatal). Public report pages render without any menu shell, so an empty
    // grant set is the correct, crash-free menu for them.
    $isAnonymousSession =
      isset($_SESSION['userID']) && intval($_SESSION['userID']) <= 0;
    if( !is_object($gui) || !isset($_SESSION['currentUser']) ||
        $isAnonymousSession || null == $db ) {
      $this->assign('menuGrants',self::emptyMenuGrants());
      return;
    }

    $needsMenu = !property_exists($gui,'showMenu') || null == $gui->showMenu;

    // aside.tpl filters the individual menu entries on these rights. They go
    // in a variable of their own because pages use $gui->grants as an array
    // while the menu needs the object form, and overwriting theirs would
    // break page content already built around the array.
    $menuGrants = (property_exists($gui,'grants') && is_object($gui->grants))
                  ? $gui->grants : null;

    if( !$needsMenu && null != $menuGrants ) {
      $this->assign('menuGrants',$menuGrants);
      return;
    }

    $ctx = new stdClass();
    $ctx->tproject_id =
      isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
    $ctx->tplan_id =
      isset($_SESSION['testplanID']) ? intval($_SESSION['testplanID']) : 0;

    list($uxArgs,$ux) = initUserEnv($db,$ctx);

    if( null == $menuGrants && property_exists($ux,'grants') ) {
      $menuGrants = $ux->grants;
    }
    $this->assign('menuGrants',
      null != $menuGrants ? $menuGrants : self::emptyMenuGrants());

    if( $needsMenu ) {
      // aside.tpl gates Metrics Dashboard/Builds/Add-Remove Platforms/
      // Milestones on $gui->countPlans > 0, so it has to be copied over too.
      foreach( array('showMenu','activeMenu','uri','logo','whoami','access','countPlans') as $prop ) {
        if( property_exists($ux,$prop) &&
            (!property_exists($gui,$prop) || null == $gui->$prop) ) {
          $gui->$prop = $ux->$prop;
        }
      }
      $this->assign('gui',$gui);
    }
  }

  /**
   * Return a stdClass whose properties match the menu grants that
   * aside.tpl reads, all set to "no" (or 0 for inventory flags) so
   * that every menu item is hidden when no real grant set is available.
   */
  static function emptyMenuGrants() {
    $g = new stdClass();
    $keys = array(
      'event_viewer','user_mgmt','cfield_management','project_edit',
      'configuration',
      'tproject_user_role_assignment','keywords_view','modify_tc','view_tc',
      'keyword_assignment','req_tcase_link_management','monitor_req',
      'mgt_testplan_create','testplan_create_build',
      'testplan_add_remove_platforms','testplan_set_urgent_testcases',
      'testplan_update_linked_testcase_versions',
      'testplan_show_testcases_newest_versions','testplan_execute',
      'exec_ro_access','exec_testcases_assigned_to_me',
      'testplan_milestone_overview','plugin_management'
    );
    foreach( $keys as $k ) {
      $g->$k = 'no';
    }
    $g->project_inventory_view = 0;
    $g->project_inventory_management = 0;
    return $g;
  }

  /**
   * @see addMenuContext()
   */
  public function display($template = null, $cache_id = null,
                          $compile_id = null, $parent = null) {
    $this->addMenuContext();
    parent::display($template,$cache_id,$compile_id,$parent);
  }

}
