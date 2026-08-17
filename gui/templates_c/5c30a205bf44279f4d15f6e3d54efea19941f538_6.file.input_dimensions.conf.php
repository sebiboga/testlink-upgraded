<?php /* Smarty version 4.5.7, created on 2026-08-17 07:00:53
         compiled from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\conf\input_dimensions.conf' */ ?>
<?php
/* Smarty version 4.5.7, created on 2026-08-17 07:00:53
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\conf\input_dimensions.conf' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82b1a56ee861_13218126',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '5c30a205bf44279f4d15f6e3d54efea19941f538' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\conf\\input_dimensions.conf',
      1 => 1786866248,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6a82b1a56ee861_13218126 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->smarty->ext->configLoad->_loadConfigVars($_smarty_tpl, array (
  'sections' => 
  array (
    'attachmentupload' => 
    array (
      'vars' => 
      array (
        'UPLOAD_FILENAME_SIZE' => 50,
        'ATTACHMENT_TITLE_SIZE' => 50,
        'ATTACHMENT_TITLE_MAXLEN' => 250,
      ),
    ),
    'bugAdd' => 
    array (
      'vars' => 
      array (
        'BUGID_SIZE' => 40,
        'BUGID_MAXLEN' => 64,
      ),
    ),
    'buildEdit' => 
    array (
      'vars' => 
      array (
        'BUILD_NAME_SIZE' => 100,
        'BUILD_NAME_MAXLEN' => 100,
        'BUILD_NOTES_TRUNCATE_LEN' => 120,
      ),
    ),
    'buildView' => 
    array (
      'vars' => 
      array (
        'BUILD_NAME_SIZE' => 100,
        'BUILD_NAME_MAXLEN' => 100,
        'BUILD_NOTES_TRUNCATE_LEN' => 120,
        'item_view_table' => 'table table-bordered',
        'item_view_thead' => 'thead-dark',
        'pagination_length' => 20,
      ),
    ),
    'cfieldsEdit' => 
    array (
      'vars' => 
      array (
        'CFIELD_NAME_SIZE' => 30,
        'CFIELD_NAME_MAXLEN' => 25,
        'CFIELD_LABEL_SIZE' => 52,
        'CFIELD_LABEL_MAXLEN' => 50,
        'CFIELD_POSSIBLE_VALUES_SIZE' => 50,
        'CFIELD_POSSIBLE_VALUES_MAXLEN' => 255,
      ),
    ),
    'cfieldsTprojectAssign' => 
    array (
      'vars' => 
      array (
        'DISPLAY_ORDER_SIZE' => 3,
        'DISPLAY_ORDER_MAXLEN' => 4,
        'item_view_table' => 'table table-bordered',
        'item_view_thead' => 'thead-dark',
        'pagination_length' => 20,
      ),
    ),
    'containerEdit' => 
    array (
      'vars' => 
      array (
        'CONTAINER_NAME_SIZE' => 50,
        'CONTAINER_NAME_MAXLEN' => 75,
      ),
    ),
    'containerOrder' => 
    array (
      'vars' => 
      array (
        'ORDER_SIZE' => 5,
        'ORDER_MAXLEN' => 5,
      ),
    ),
    'eventviewer' => 
    array (
      'vars' => 
      array (
        'EVENT_DESCRIPTION_TRUNCATE_LEN' => 260,
      ),
    ),
    'execSetResults' => 
    array (
      'vars' => 
      array (
        'ROUND_EXEC_HISTORY' => 1,
        'ROUND_TC_TITLE' => 1,
        'ROUND_TC_SPEC' => 1,
      ),
    ),
    'keywordsEdit' => 
    array (
      'vars' => 
      array (
        'KEYWORD_SIZE' => 50,
        'KEYWORD_MAXLEN' => 100,
        'NOTES_ROWS' => 3,
        'NOTES_COLS' => 50,
      ),
    ),
    'infrastructure' => 
    array (
      'vars' => 
      array (
        'MACHINE_NAME_MAXLEN' => 100,
        'MACHINE_NAME_SIZE' => 30,
        'MACHINE_IP_MAXLEN' => 50,
        'MACHINE_IP_SIZE' => 30,
        'MACHINE_NOTES_MAXLEN' => 2000,
        'MACHINE_NOTES_ROWS' => 3,
        'MACHINE_NOTES_COLS' => 50,
      ),
    ),
    'login' => 
    array (
      'vars' => 
      array (
        'PASSWD_SIZE' => 50,
        'NAMES_SIZE' => 50,
        'NAMES_MAXLEN' => 30,
        'EMAIL_SIZE' => 50,
        'EMAIL_MAXLEN' => 100,
      ),
    ),
    'mainPage' => 
    array (
      'vars' => 
      array (
        'TESTPLAN_TRUNCATE_SIZE' => 45,
      ),
    ),
    'navBar' => 
    array (
      'vars' => 
      array (
        'TESTPROJECT_TRUNCATE_SIZE' => 150,
        'TESTPLAN_TRUNCATE_SIZE' => 45,
      ),
    ),
    'planAddTC' => 
    array (
      'vars' => 
      array (
        'EXECUTION_ORDER_SIZE' => 4,
        'EXECUTION_ORDER_MAXLEN' => 5,
      ),
    ),
    'planEdit' => 
    array (
      'vars' => 
      array (
        'TESTPLAN_NAME_SIZE' => 50,
        'TESTPLAN_NAME_MAXLEN' => 100,
      ),
    ),
    'planMilestones' => 
    array (
      'vars' => 
      array (
        'MILESTONE_NAME_SIZE' => 50,
        'MILESTONE_NAME_MAXLEN' => 100,
        'PRIORITY_SIZE' => 3,
        'PRIORITY_MAXLEN' => 3,
      ),
    ),
    'planMilestonesEdit' => 
    array (
      'vars' => 
      array (
        'MILESTONE_NAME_SIZE' => 50,
        'MILESTONE_NAME_MAXLEN' => 100,
        'PRIORITY_SIZE' => 3,
        'PRIORITY_MAXLEN' => 3,
      ),
    ),
    'planNew' => 
    array (
      'vars' => 
      array (
        'TESTPLAN_NAME_SIZE' => 50,
        'TESTPLAN_NAME_MAXLEN' => 100,
      ),
    ),
    'planView' => 
    array (
      'vars' => 
      array (
        'TESTPLAN_NOTES_TRUNCATE' => 100,
      ),
    ),
    'platformsView' => 
    array (
      'vars' => 
      array (
        'PLATFORM_SIZE' => 25,
        'PLATFORM_MAXLEN' => 75,
        'PLATFORM_NOTES_TRUNCATE_LEN' => 120,
        'NOTES_ROWS' => 3,
        'NOTES_COLS' => 50,
        'item_view_table' => 'table table-bordered',
        'item_view_thead' => 'thead-dark',
        'pagination_length' => 20,
      ),
    ),
    'platformsEdit' => 
    array (
      'vars' => 
      array (
        'PLATFORM_SIZE' => 25,
        'PLATFORM_MAXLEN' => 75,
        'NOTES_ROWS' => 3,
        'NOTES_COLS' => 50,
      ),
    ),
    'projectEdit' => 
    array (
      'vars' => 
      array (
        'TESTCASE_PREFIX_SIZE' => 5,
        'TESTCASE_PREFIX_MAXLEN' => 16,
        'TESTPROJECT_NAME_SIZE' => 50,
        'TESTPROJECT_NAME_MAXLEN' => 100,
      ),
    ),
    'projectView' => 
    array (
      'vars' => 
      array (
        'TESTPROJECT_NOTES_TRUNCATE' => 100,
        'TESTPROJECT_NAME_SIZE' => 40,
        'TESTPROJECT_NAME_MAXLEN' => 100,
      ),
    ),
    'resultsNavigator' => 
    array (
      'vars' => 
      array (
        'EMAIL_TO_SIZE' => 50,
        'EMAIL_SUBJECT_SIZE' => 50,
      ),
    ),
    'reqEdit' => 
    array (
      'vars' => 
      array (
        'REQ_TITLE_SIZE' => 50,
        'REQ_TITLE_MAXLEN' => 75,
        'REQ_EXPECTED_COVERAGE_SIZE' => 3,
        'REQ_EXPECTED_COVERAGE_MAXLEN' => 3,
      ),
    ),
    'reqSpecEdit' => 
    array (
      'vars' => 
      array (
        'SRS_TITLE_SIZE' => 50,
        'SRS_TITLE_MAXLEN' => 75,
        'REQ_SPEC_TITLE_SIZE' => 50,
        'REQ_SPEC_TITLE_MAXLEN' => 75,
        'REQ_COUNTER_SIZE' => 5,
        'REQ_COUNTER_MAXLEN' => 5,
      ),
    ),
    'reqSpecView' => 
    array (
      'vars' => 
      array (
        'SRS_CONTAINER_WIDTH' => '90%',
      ),
    ),
    'resultsMoreBuildsGUI' => 
    array (
      'vars' => 
      array (
        'BUILDS_COMBO_NUM_ITEMS' => 10,
        'PLATFORMS_COMBO_NUM_ITEMS' => 10,
        'KEYWORDS_COMBO_NUM_ITEMS' => 10,
        'TSUITES_COMBO_NUM_ITEMS' => 10,
        'TCSTATUS_COMBO_NUM_ITEMS' => 4,
      ),
    ),
    'rolesEdit' => 
    array (
      'vars' => 
      array (
        'ROLENAME_SIZE' => 50,
        'ROLENAME_MAXLEN' => 100,
      ),
    ),
    'tcSearchForm' => 
    array (
      'vars' => 
      array (
        'VERSION_SIZE' => 3,
        'VERSION_MAXLEN' => 3,
        'TCNAME_SIZE' => 35,
        'TCNAME_MAXLEN' => 50,
        'SUMMARY_SIZE' => 35,
        'SUMMARY_MAXLEN' => 50,
        'STEPS_SIZE' => 35,
        'STEPS_MAXLEN' => 50,
        'RESULTS_SIZE' => 35,
        'RESULTS_MAXLEN' => 50,
        'CFVALUE_SIZE' => 20,
        'CFVALUE_MAXLEN' => 20,
        'PRECONDITIONS_SIZE' => 35,
        'PRECONDITIONS_MAXLEN' => 50,
        'AUTHOR_SIZE' => 35,
        'AUTHOR_MAXLEN' => 50,
      ),
    ),
    'tcSearchGUI' => 
    array (
      'vars' => 
      array (
        'VERSION_SIZE' => 3,
        'VERSION_MAXLEN' => 3,
        'TCNAME_SIZE' => 35,
        'TCNAME_MAXLEN' => 50,
        'SUMMARY_SIZE' => 20,
        'SUMMARY_MAXLEN' => 50,
        'STEPS_SIZE' => 20,
        'STEPS_MAXLEN' => 50,
        'RESULTS_SIZE' => 20,
        'RESULTS_MAXLEN' => 50,
        'CFVALUE_SIZE' => 20,
        'CFVALUE_MAXLEN' => 20,
        'PRECONDITIONS_SIZE' => 20,
        'PRECONDITIONS_MAXLEN' => 50,
        'AUTHOR_SIZE' => 20,
        'AUTHOR_MAXLEN' => 50,
      ),
    ),
    'tcStepEdit' => 
    array (
      'vars' => 
      array (
        'STEP_NUMBER_SIZE' => 1,
        'STEP_NUMBER_MAXLEN' => 2,
      ),
    ),
    'reqSearchForm' => 
    array (
      'vars' => 
      array (
        'REQDOCID_SIZE' => 35,
        'REQDOCID_MAXLEN' => 64,
        'VERSION_SIZE' => 3,
        'VERSION_MAXLEN' => 3,
        'REQNAME_SIZE' => 35,
        'REQNAME_MAXLEN' => 50,
        'SCOPE_SIZE' => 35,
        'SCOPE_MAXLEN' => 50,
        'COVERAGE_SIZE' => 3,
        'COVERAGE_MAXLEN' => 3,
        'CFVALUE_SIZE' => 20,
        'CFVALUE_MAXLEN' => 20,
      ),
    ),
    'reqSpecSearchForm' => 
    array (
      'vars' => 
      array (
        'REQSPECDOCID_SIZE' => 35,
        'REQSPECDOCID_MAXLEN' => 64,
        'VERSION_SIZE' => 3,
        'VERSION_MAXLEN' => 3,
        'REQSPECNAME_SIZE' => 35,
        'REQSPECNAME_MAXLEN' => 50,
        'SCOPE_SIZE' => 35,
        'SCOPE_MAXLEN' => 50,
        'COVERAGE_SIZE' => 3,
        'COVERAGE_MAXLEN' => 3,
        'CFVALUE_SIZE' => 20,
        'CFVALUE_MAXLEN' => 20,
      ),
    ),
    'treeFilterForm' => 
    array (
      'vars' => 
      array (
        'TC_TITLE_SIZE' => 32,
        'TC_TITLE_MAXLEN' => 32,
        'REQ_DOCID_SIZE' => 35,
        'REQ_DOCID_MAXLEN' => 64,
        'REQ_NAME_SIZE' => 35,
        'REQ_NAME_MAXLEN' => 50,
        'COVERAGE_SIZE' => 3,
        'COVERAGE_MAXLEN' => 3,
      ),
    ),
    'issueTrackerEdit' => 
    array (
      'vars' => 
      array (
        'ISSUETRACKER_NAME_SIZE' => 50,
        'ISSUETRACKER_NAME_MAXLEN' => 100,
        'ISSUETRACKER_CFG_ROWS' => 20,
        'ISSUETRACKER_CFG_COLS' => 80,
      ),
    ),
    'codeTrackerEdit' => 
    array (
      'vars' => 
      array (
        'CODETRACKER_NAME_SIZE' => 50,
        'CODETRACKER_NAME_MAXLEN' => 100,
        'CODETRACKER_CFG_ROWS' => 20,
        'CODETRACKER_CFG_COLS' => 80,
      ),
    ),
    'reqMgrSystemEdit' => 
    array (
      'vars' => 
      array (
        'REQMGRSYSTEM_NAME_SIZE' => 50,
        'REQMGRSYSTEM_NAME_MAXLEN' => 100,
        'REQMGRSYSTEM_CFG_ROWS' => 20,
        'REQMGRSYSTEM_CFG_COLS' => 80,
      ),
    ),
    'cfieldsView' => 
    array (
      'vars' => 
      array (
        'item_view_table' => 'table table-bordered',
        'item_view_thead' => 'thead-dark',
      ),
    ),
    'issueTrackerView' => 
    array (
      'vars' => 
      array (
        'item_view_table' => 'table table-bordered',
        'item_view_thead' => 'thead-dark',
        'pagination_length' => 20,
      ),
    ),
    'codeTrackerView' => 
    array (
      'vars' => 
      array (
        'item_view_table' => 'table table-bordered',
        'item_view_thead' => 'thead-dark',
        'pagination_length' => 20,
      ),
    ),
    'keywordsView' => 
    array (
      'vars' => 
      array (
        'item_view_table' => 'table table-bordered',
        'item_view_thead' => 'thead-dark',
        'pagination_length' => 20,
      ),
    ),
  ),
  'vars' => 
  array (
    'BUGS_FILTER_SIZE' => 32,
    'BUGS_FILTER_MAXLEN' => 240,
    'BUGSUMMARY_SIZE' => 100,
    'BUGNOTES_ROWS' => 10,
    'BUGNOTES_COLS' => 120,
    'DATE_PICKER' => 12,
    'EXEC_DURATION_SIZE' => 7,
    'EXEC_DURATION_MAXLEN' => 7,
    'FILENAME_MAXLEN' => 50,
    'FILENAME_SIZE' => 50,
    'LOGIN_SIZE' => 50,
    'LOGIN_MAXLEN' => 100,
    'LOGMSG_SIZE' => 35,
    'LOGMSG_MAXLEN' => 100,
    'REQ_DOCID_SIZE' => 35,
    'REQ_DOCID_MAXLEN' => 64,
    'REQSPEC_DOCID_SIZE' => 35,
    'REQSPEC_DOCID_MAXLEN' => 64,
    'STEP_NUMBER_SIZE' => 1,
    'STEP_NUMBER_MAXLEN' => 2,
    'SCOPE_TRUNCATE' => 100,
    'SCOPE_SHORT_TRUNCATE' => 30,
    'TC_ID_SIZE' => 12,
    'TC_ID_MAXLEN' => 30,
    'TC_EXTERNAL_ID_SIZE' => 20,
    'TC_EXTERNAL_ID_MAXLEN' => 20,
    'TESTPROJECT_TRUNCATE_SIZE' => 150,
    'TESTCASE_NAME_SIZE' => 50,
    'TESTCASE_NAME_MAXLEN' => 100,
    'TESTPLAN_TRUNCATE_SIZE' => 45,
    'BUTTON_CLASS' => 'btn btn-primary btn-xs',
    'TITLE_CLASS' => 'title big-font',
    'item_view_table' => 'table table-bordered',
    'item_view_thead' => 'thead-dark',
    'pagination_length' => 20,
    'NOT_SORTABLE' => 'data-orderable="false" ',
  ),
));
}
}
