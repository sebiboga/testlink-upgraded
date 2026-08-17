<?php
/* Smarty version 4.5.7, created on 2026-08-17 07:04:00
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\include\inc_filter_panel.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82b260d50964_08163639',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '6be3648f1857e8e2f9a6c9188fd706df35d577b9' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\include\\inc_filter_panel.tpl',
      1 => 1786912104,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:inc_help.tpl' => 1,
  ),
),false)) {
function content_6a82b260d50964_08163639 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_checkPlugins(array(0=>array('file'=>'C:\\sebi\\CLAUDE\\testlink-upgraded\\vendor\\smarty\\smarty\\libs\\plugins\\function.html_options.php','function'=>'smarty_function_html_options',),1=>array('file'=>'C:\\sebi\\CLAUDE\\testlink-upgraded\\vendor\\smarty\\smarty\\libs\\plugins\\function.html_radios.php','function'=>'smarty_function_html_radios',),));
?>

<?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('var'=>'labels','s'=>'caption_nav_settings, platform, test_plan,
     build,filter_tcID,filter_on,filter_result,status,
     btn_update_menu,btn_apply_filter,keyword,keywords_filter_help,
     filter_owner,TestPlan,test_plan,caption_nav_filters,
     platform, include_unassigned_testcases, filter_active_inactive,
     btn_remove_all_tester_assignments, execution_type, 
     do_auto_update, testsuite, btn_reset_filters,hint_list_of_bugs,
     btn_bulk_update_to_latest_version, priority, tc_title,
     custom_field, search_type_like, importance,import_xml_results,
     document_id, req_expected_coverage, title,bugs_on_context,
     status, req_type, req_spec_type, th_tcid, has_relation_type,
     btn_export_testplan_tree,btn_export_testplan_tree_for_results,
     tester_works_with_settings,btn_bulk_remove,btn_bulk_copy,
     test_grouped_by, parent_child_relation, exec_tree_counters_logic,
     platforms'),$_smarty_tpl ) );?>


<?php
$_smarty_tpl->smarty->ext->configLoad->_loadConfigFile($_smarty_tpl, "input_dimensions.conf", "treeFilterForm", 0);
?>


<form method="post" id="filter_panel_form" name="filter_panel_form"
      <?php if ($_smarty_tpl->tpl_vars['control']->value->formAction) {?> action="<?php echo $_smarty_tpl->tpl_vars['control']->value->formAction;?>
" <?php }?>

      <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_result']) {?>
        onsubmit="return validateForm(this);document.getElementById('filter_result_method').disabled=false;"
      <?php }?>
        >
  <input type="hidden" name="caller" value="filter_panel">
  <input type="hidden" name="tproject_id" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
">
          
<?php if ((isset($_smarty_tpl->tpl_vars['control']->value->form_token))) {?>
  <input type="hidden" name="form_token" value="<?php echo $_smarty_tpl->tpl_vars['control']->value->form_token;?>
">
<?php }?>

<?php $_smarty_tpl->_assignInScope('platformID', 0);?>

<?php if ($_smarty_tpl->tpl_vars['control']->value->draw_bulk_update_button) {?>
    <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="button"
           value="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_bulk_update_to_latest_version'];?>
"
           name="doBulkUpdateToLatest"
           id="doBulkUpdateToLatest"
           onclick="update2latest(<?php echo $_smarty_tpl->tpl_vars['gui']->value->tPlanID;?>
)" />
<?php }?>

<?php if ((isset($_smarty_tpl->tpl_vars['gui']->value->feature))) {?>
<input type="hidden" id="feature" name="feature" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->feature;?>
" />
<?php }?>

<?php $_smarty_tpl->_subTemplateRender("file:inc_help.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array('helptopic'=>"hlp_executeFilter",'show_help_icon'=>false), 0, false);
?>


<style type="text/css" media="all">
.x-panel-bwrap { overflow: visible; left: 0px; top: 0px; }    
</style>

<?php if ($_smarty_tpl->tpl_vars['control']->value->display_settings) {?>
  <div id="settings_panel" style="overflow: visible;">
    <div class="x-panel-header x-unselectable" style="overflow: visible;">
      <?php echo $_smarty_tpl->tpl_vars['labels']->value['caption_nav_settings'];?>

    </div>

    <div id="settings" class="x-panel-body" style="padding-top: 3px;overflow: visible;">
      <input type='hidden' id="tpn_view_settings" name="tpn_view_status"  value="0" />

      <table class="smallGrey" style="width:98%;overflow: visible;">

      <?php if ($_smarty_tpl->tpl_vars['control']->value->settings['setting_testplan']) {?>
        <tr>
          <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['test_plan'];?>
</td>
          <td>
            <select class="chosen-select" name="setting_testplan" onchange="this.form.submit()">
            <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->settings['setting_testplan']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->settings['setting_testplan']['selected']),$_smarty_tpl);?>

            </select>
          </td>
        </tr>
      <?php }?>

      <?php if ($_smarty_tpl->tpl_vars['control']->value->settings['setting_platform']) {?>
        <?php $_smarty_tpl->_assignInScope('platformID', $_smarty_tpl->tpl_vars['control']->value->settings['setting_platform']['selected']);?>
        <tr>
          <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['platform'];?>
</td>
          <td>
            <select name="setting_platform" class="chosen-select" onchange="this.form.submit()">
            <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->settings['setting_platform']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->settings['setting_platform']['selected']),$_smarty_tpl);?>

            </select>
          </td>
        </tr>
      <?php }?>

      <?php if ($_smarty_tpl->tpl_vars['control']->value->settings['setting_build']) {?>
        <tr>
          <td><?php echo $_smarty_tpl->tpl_vars['control']->value->settings['setting_build']['label'];?>
</td>
          <td>
            <select name="setting_build" class="chosen-select" onchange="this.form.submit()">
            <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->settings['setting_build']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->settings['setting_build']['selected']),$_smarty_tpl);?>

            </select>
          </td>
        </tr>
      <?php }?>

      <?php if ($_smarty_tpl->tpl_vars['control']->value->settings['setting_testsgroupby']) {?>
        <tr>
          <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['test_grouped_by'];?>
</td>
          <td>
            <select name="setting_testsgroupby" class="chosen-select" onchange="this.form.submit()">
            <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->settings['setting_testsgroupby']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->settings['setting_testsgroupby']['selected']),$_smarty_tpl);?>

             </select>
          </td>
        </tr>
      <?php }?>
    
      <?php if ($_smarty_tpl->tpl_vars['control']->value->settings['setting_refresh_tree_on_action']) {?>
        <tr>
            <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['do_auto_update'];?>
</td>
            <td>
               <input type="hidden" 
                      id="hidden_setting_refresh_tree_on_action"
                      name="hidden_setting_refresh_tree_on_action" 
                      value="<?php echo $_smarty_tpl->tpl_vars['control']->value->settings['setting_refresh_tree_on_action']['hidden_setting_refresh_tree_on_action'];?>
" />

               <input type="checkbox"
                       id="cbsetting_refresh_tree_on_action"
                       name="setting_refresh_tree_on_action"
                       <?php if ($_smarty_tpl->tpl_vars['control']->value->settings['setting_refresh_tree_on_action']['selected']) {?> checked <?php }?>
                       style="font-size: 90%;" onclick="this.form.submit()"/>
            </td>
          </tr>
      <?php }?>
    
      <?php if ($_smarty_tpl->tpl_vars['control']->value->settings['setting_get_parent_child_relation']) {?>
        <tr>
            <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['parent_child_relation'];?>
</td>
            <td>
        <input type="hidden" 
                      id="hidden_setting_get_parent_child_relation"
                      name="hidden_setting_get_parent_child_relation" 
                      value="<?php echo $_smarty_tpl->tpl_vars['control']->value->settings['setting_get_parent_child_relation']['hidden_setting_get_parent_child_relation'];?>
" />
      
        <input type="checkbox"
             id="cbsetting_get_parent_child_relation"
             name="setting_get_parent_child_relation"
             <?php if ($_smarty_tpl->tpl_vars['control']->value->settings['setting_get_parent_child_relation']['selected']) {?> checked <?php }?>
             style="font-size: 90%;" onclick="this.form.submit()"/>
            </td>
          </tr>
      <?php }?>

      <tr>
        <td>&nbsp;</td>
      </tr>

      <?php if ($_smarty_tpl->tpl_vars['control']->value->settings['setting_exec_tree_counters_logic']) {?>
        <tr>
          <td>
          <?php echo $_smarty_tpl->tpl_vars['labels']->value['exec_tree_counters_logic'];?>

          </td>
          <td>
            <select class="chosen-select" name="setting_exec_tree_counters_logic" onchange="this.form.submit()">
            <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->settings['setting_exec_tree_counters_logic']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->settings['setting_exec_tree_counters_logic']['selected']),$_smarty_tpl);?>

            </select>
          </td>
        </tr>   
      <?php }?>

      <?php if ($_smarty_tpl->tpl_vars['control']->value->draw_export_testplan_button || $_smarty_tpl->tpl_vars['control']->value->draw_import_xml_results_button) {?>
        <tr>
          <td>&nbsp;</td><td>&nbsp;</td>
        </tr>   

        <tr>
          <td>&nbsp;</td>
          <td>
            <?php if ($_smarty_tpl->tpl_vars['control']->value->draw_export_testplan_button) {?>
            <span title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_export_testplan_tree'];?>
" onclick="javascript: openExportTestPlan('export_testplan','<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
', '<?php echo $_smarty_tpl->tpl_vars['control']->value->settings['setting_testplan']['selected'];?>
', '<?php echo $_smarty_tpl->tpl_vars['platformID']->value;?>
', '<?php echo $_smarty_tpl->tpl_vars['control']->value->settings['setting_build']['selected'];?>
','tree', '<?php echo $_smarty_tpl->tpl_vars['control']->value->form_token;?>
');"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['export'];?>
</span>
            &nbsp;                                               
            <span title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_export_testplan_tree_for_results'];?>
" onclick="javascript: openExportTestPlan('export_testplan','<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
', '<?php echo $_smarty_tpl->tpl_vars['control']->value->settings['setting_testplan']['selected'];?>
', '<?php echo $_smarty_tpl->tpl_vars['platformID']->value;?>
', '<?php echo $_smarty_tpl->tpl_vars['control']->value->settings['setting_build']['selected'];?>
', '4results', '<?php echo $_smarty_tpl->tpl_vars['control']->value->form_token;?>
');"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['export_for_results_import'];?>
</span>

            &nbsp;                                               
            <?php }?>
            <?php if ($_smarty_tpl->tpl_vars['control']->value->draw_import_xml_results_button) {?>
            <span title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['import_xml_results'];?>
" onclick="javascript: openImportResult('import_xml_results',<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
, <?php echo $_smarty_tpl->tpl_vars['control']->value->settings['setting_testplan']['selected'];?>
, <?php echo $_smarty_tpl->tpl_vars['control']->value->settings['setting_build']['selected'];?>
,<?php echo $_smarty_tpl->tpl_vars['platformID']->value;?>
);"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['import_results'];?>
</span>
            <?php }?>
          </td>
        </tr>
      <?php }?>
      </table>
    </div>   </div> <?php }?> 
<?php if ($_smarty_tpl->tpl_vars['control']->value->display_filters) {?>

  <div id="filter_panel">
    <div class="x-panel-header x-unselectable">
      <?php echo $_smarty_tpl->tpl_vars['labels']->value['caption_nav_filters'];?>

    </div>

  <div id="filters" class="x-panel-body exec_additional_info" style="padding-top: 3px;overflow: visible;">
    
    <table class="smallGrey" style="width:98%;">

    <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_tc_id']) {?>
      <tr>
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['th_tcid'];?>
</td>
        <td><input type="text" name="filter_tc_id"
                               size="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TC_ID_SIZE');?>
"
                               maxlength="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TC_ID_MAXLEN');?>
"
                               value="<?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['control']->value->filters['filter_tc_id']['selected'], ENT_QUOTES, 'UTF-8', true);?>
" />
        </td>
      </tr>
    <?php }?>

    <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_testcase_name']) {?>
      <tr>
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['tc_title'];?>
</td>
        <td><input type="text" name="filter_testcase_name"
                               size="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TC_TITLE_SIZE');?>
"
                               maxlength="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TC_TITLE_MAXLEN');?>
"
                               value="<?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['control']->value->filters['filter_testcase_name']['selected'], ENT_QUOTES, 'UTF-8', true);?>
" />
        </td>
      </tr>
    <?php }?>

    <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_toplevel_testsuite']) {?>
      <tr>
          <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['testsuite'];?>
</td>
          <td>
            <select class="chosen-select" name="filter_toplevel_testsuite">
              <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_toplevel_testsuite']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_toplevel_testsuite']['selected']),$_smarty_tpl);?>

            </select>
          </td>
        </tr>
      <?php }?>

    <?php if (is_array($_smarty_tpl->tpl_vars['control']->value->filters['filter_keywords']) && count($_smarty_tpl->tpl_vars['control']->value->filters['filter_keywords']) > 0) {?>
      <tr>
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['keyword'];?>
</td>
        <td><select class="chosen-select" name="filter_keywords[]"
                    title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['keywords_filter_help'];?>
"
                    multiple="multiple"
                    size="<?php echo $_smarty_tpl->tpl_vars['control']->value->filters['filter_keywords']['size'];?>
">
            <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_keywords']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_keywords']['selected']),$_smarty_tpl);?>

          </select>
      <div>
      <?php echo smarty_function_html_radios(array('name'=>'filter_keywords_filter_type','options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_keywords']['filter_keywords_filter_type']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_keywords']['filter_keywords_filter_type']['selected']),$_smarty_tpl);?>

      </div>
      </td>
      </tr>
      <tr><td>&nbsp;</td></tr>
    <?php }?>

    <?php if (is_array($_smarty_tpl->tpl_vars['control']->value->filters['filter_platforms']) && count($_smarty_tpl->tpl_vars['control']->value->filters['filter_platforms']) > 0 && (isset($_smarty_tpl->tpl_vars['control']->value->filters['filter_platforms']['size']))) {?>
      <tr>
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['platforms'];?>
</td>
        <td><select class="chosen-select" name="filter_platforms[]"
                    title=""
                    multiple="multiple"
                    size="<?php echo $_smarty_tpl->tpl_vars['control']->value->filters['filter_platforms']['size'];?>
">
            <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_platforms']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_platforms']['selected']),$_smarty_tpl);?>

          </select>
      </td>
      </tr>
      <tr><td>&nbsp;</td></tr>
    <?php }?>


        <?php if ((isset($_smarty_tpl->tpl_vars['control']->value->filters['filter_active_inactive'])) && $_smarty_tpl->tpl_vars['control']->value->filters['filter_active_inactive']) {?>
      <tr>
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['filter_active_inactive'];?>
</td>
          <td>
            <select name="filter_active_inactive">
                   <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_active_inactive']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_active_inactive']['selected']),$_smarty_tpl);?>

            </select>
          </td>
      </tr>
    <?php }?>

    <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_workflow_status']) {?>
      <tr>
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['status'];?>
</td>
        <td>
          <select class="chosen-select" id="filter_workflow_status" 
          <?php if ($_smarty_tpl->tpl_vars['control']->value->advanced_filter_mode) {?>
             name="filter_workflow_status[]" multiple="multiple"
             size="<?php echo $_smarty_tpl->tpl_vars['control']->value->filter_item_quantity;?>
">
          <?php } else { ?>
             name="filter_workflow_status">
          <?php }?>
            <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_workflow_status']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_workflow_status']['selected']),$_smarty_tpl);?>

          </select>
        </td>
      </tr>
    <?php }?>
            
    <?php if (is_array($_smarty_tpl->tpl_vars['control']->value->filters['filter_importance']) && count($_smarty_tpl->tpl_vars['control']->value->filters['filter_importance']) > 0) {?>
      <tr>
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['importance'];?>
</td>
        <td>
          <select class="chosen-select" id="filter_importance"
          <?php if ($_smarty_tpl->tpl_vars['control']->value->advanced_filter_mode) {?>
             name="filter_importance[]" multiple="multiple"
             size="<?php echo $_smarty_tpl->tpl_vars['control']->value->filters['filter_importance']['size'];?>
">
          <?php } else { ?>
             name="filter_importance">
          <?php }?>     
             <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_importance']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_importance']['selected']),$_smarty_tpl);?>

          </select>
        </td>
      </tr>
    <?php }?>
            
    <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_priority']) {?>
      <tr>
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['priority'];?>
</td>
        <td>
          <select class="chosen-select" name="filter_priority">
          <option value=""><?php echo $_smarty_tpl->tpl_vars['control']->value->option_strings['any'];?>
</option>
          <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['gsmarty_option_importance']->value,'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_priority']['selected']),$_smarty_tpl);?>

          </select>
        </td>
      </tr>
    <?php }?>

    <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_execution_type']) {?>
      <tr>
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['execution_type'];?>
</td>
          <td>
        <select class="chosen-select" name="filter_execution_type">
          <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_execution_type']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_execution_type']['selected']),$_smarty_tpl);?>

          </select>
        </td>
      </tr>
    <?php }?>

    <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_assigned_user']) {?>
    <tr>
      <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['filter_owner'];?>
<span title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['tester_works_with_settings'];?>
"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['info_small'];?>
</span></td>
      <td>

      <?php if ($_smarty_tpl->tpl_vars['control']->value->advanced_filter_mode) {?>
        <select class="chosen-select" name="filter_assigned_user[]"
                id="filter_assigned_user"
                multiple="multiple"
                size="<?php echo $_smarty_tpl->tpl_vars['control']->value->filter_item_quantity;?>
" >
        <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_assigned_user']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_assigned_user']['selected']),$_smarty_tpl);?>

        </select>
        <?php } else { ?>
        <select class="chosen-select" name="filter_assigned_user" 
                id="filter_assigned_user"
                onchange="javascript: triggerAssignedBox('filter_assigned_user',
                                                               'filter_assigned_user_include_unassigned',
                                                               '<?php echo $_smarty_tpl->tpl_vars['control']->value->option_strings['any'];?>
',
                                                               '<?php echo $_smarty_tpl->tpl_vars['control']->value->option_strings['none'];?>
',
                                                               '<?php echo $_smarty_tpl->tpl_vars['control']->value->option_strings['somebody'];?>
');">
        <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_assigned_user']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_assigned_user']['selected']),$_smarty_tpl);?>

        </select>

        <br/>
        <br />
        <input type="checkbox"
               id="filter_assigned_user_include_unassigned"
               name="filter_assigned_user_include_unassigned"
                   value="1"
                   <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_assigned_user']['filter_assigned_user_include_unassigned']) {?>
                      checked="checked"
                   <?php }?>
            />
        <?php echo $_smarty_tpl->tpl_vars['labels']->value['include_unassigned_testcases'];?>

      <?php }?>

      </td>
    </tr>
      <?php }?>

    <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_bugs']) {?>
      <tr>
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['bugs_on_context'];?>
</td>
        <td><input type="text" name="filter_bugs" size="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUGS_FILTER_SIZE');?>
"
                               maxlength="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUGS_FILTER_MAXLEN');?>
"
                               placeholder="<?php echo $_smarty_tpl->tpl_vars['labels']->value['hint_list_of_bugs'];?>
"
                               value="<?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['control']->value->filters['filter_bugs']['selected'], ENT_QUOTES, 'UTF-8', true);?>
" />
        </td>
      </tr>
    <?php }?>
    

    
    <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_custom_fields'] && !$_smarty_tpl->tpl_vars['control']->value->filters['filter_custom_fields']['collapsed']) {?>
      <tr><td>&nbsp;</td></tr>
      <?php echo $_smarty_tpl->tpl_vars['control']->value->filters['filter_custom_fields']['items'];?>

    <?php }?>


    <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_result']) {?>

    <tr><td>&nbsp;</td></tr> 
        <tr>
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['filter_result'];?>
</td>
        <td>
        <select class="chosen-select" id="filter_result_result" 
        <?php if ($_smarty_tpl->tpl_vars['control']->value->advanced_filter_mode) {?>
              name="filter_result_result[]" multiple="multiple"
                size="<?php echo $_smarty_tpl->tpl_vars['control']->value->filter_item_quantity;?>
">
        <?php } else { ?>
              name="filter_result_result">
        <?php }?>
        <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_result']['filter_result_result']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_result']['filter_result_result']['selected']),$_smarty_tpl);?>

        </select>
        </td>
      </tr>

      <tr>
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['filter_on'];?>
</td>
        <td>
            <select class="chosen-select" name="filter_result_method" id="filter_result_method"
                    onchange="javascript: triggerBuildChooser('filter_result_build_row',
                                                            'filter_result_method',
                  <?php echo $_smarty_tpl->tpl_vars['control']->value->configuration->filter_methods['status_code']['specific_build'];?>
);">
          <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_result']['filter_result_method']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_result']['filter_result_method']['selected']),$_smarty_tpl);?>

            </select>
        </td>
      </tr>

      <tr id="filter_result_build_row">
        <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['build'];?>
</td>
        <td><select class="chosen-select" id="filter_result_build" name="filter_result_build">
          <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_result']['filter_result_build']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_result']['filter_result_build']['selected']),$_smarty_tpl);?>

          </select>
        </td>
      </tr>

  <?php }?>

    </table>

    <div>
      <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="submit"
             value="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_apply_filter'];?>
"
             id="doUpdateTree"
             name="doUpdateTree"
             style="font-size: 90%;" />

      <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="submit"
             value="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_reset_filters'];?>
"
             id="doResetTree"
             name="btn_reset_filters"
             style="font-size: 90%;" />
      
      <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_custom_fields']) {?>
      <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="submit"
             value="<?php echo $_smarty_tpl->tpl_vars['control']->value->filters['filter_custom_fields']['btn_label'];?>
"
             id="doToggleCF"
             name="btn_toggle_cf"
             style="font-size: 90%;" />
      <?php }?>
      
      <?php if ($_smarty_tpl->tpl_vars['control']->value->filter_mode_choice_enabled) {?>
      
        <?php if ($_smarty_tpl->tpl_vars['control']->value->advanced_filter_mode) {?>
          <input type="hidden" name="btn_advanced_filters" value="1" />
        <?php }?>
      
        <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="submit" 
               id="toggleFilterMode"  
               name="<?php echo $_smarty_tpl->tpl_vars['control']->value->filter_mode_button_name;?>
"
               value="<?php echo $_smarty_tpl->tpl_vars['control']->value->filter_mode_button_label;?>
"
               style="font-size: 90%;"  />
          <?php }?>
          
    </div>

  </div> 
  </div> 
<?php }?> 

<?php if ($_smarty_tpl->tpl_vars['control']->value->display_req_settings) {?>
  <div id="settings_panel">
    <div class="x-panel-header x-unselectable">
      <?php echo $_smarty_tpl->tpl_vars['labels']->value['caption_nav_settings'];?>

    </div>

    <div id="settings" class="x-panel-body exec_additional_info" "style="padding-top: 3px;">
      <input type='hidden' id="tpn_view_settings" name="tpn_view_status"  value="0" />

      <table class="smallGrey" style="width:98%;">

      <?php if ($_smarty_tpl->tpl_vars['control']->value->settings['setting_refresh_tree_on_action']) {?>
        <tr>
            <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['do_auto_update'];?>
</td>
            <td>
               <input type="hidden" 
                      id="hidden_setting_refresh_tree_on_action"
                      name="hidden_setting_refresh_tree_on_action" 
                      value="<?php echo $_smarty_tpl->tpl_vars['control']->value->settings['setting_refresh_tree_on_action']['hidden_setting_refresh_tree_on_action'];?>
" />

               <input type="checkbox"
                       id="cbsetting_refresh_tree_on_action"
                       name="setting_refresh_tree_on_action"
                       <?php if ($_smarty_tpl->tpl_vars['control']->value->settings['setting_refresh_tree_on_action']['selected']) {?> checked <?php }?>
                       style="font-size: 90%;" onclick="this.form.submit();" />
            </td>
          </tr>
      <?php }?>

      </table>
    </div>   </div> <?php }?> 
<?php if ($_smarty_tpl->tpl_vars['control']->value->display_req_filters) {?>

  <div id="filter_panel">
  <div class="x-panel-header x-unselectable">
    <?php echo $_smarty_tpl->tpl_vars['labels']->value['caption_nav_filters'];?>

  </div>

  <div id="filters" class="x-panel-body exec_additional_info" style="padding-top: 3px; overflow: visible;">

  <table class="smallGrey" style="width:98%;">

  <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_doc_id']) {?>
    <tr>
      <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['document_id'];?>
</td>
      <td><input type="text" name="filter_doc_id"
                             size="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'REQ_DOCID_SIZE');?>
"
                             maxlength="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'REQ_DOCID_MAXLEN');?>
"
                             value="<?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['control']->value->filters['filter_doc_id']['selected'], ENT_QUOTES, 'UTF-8', true);?>
" />
      </td>
    </tr>
  <?php }?>

  <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_title']) {?>
    <tr>
      <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['title'];?>
</td>
      <td><input type="text" name="filter_title"
                             size="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'REQ_NAME_SIZE');?>
"
                             maxlength="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'REQ_NAME_MAXLEN');?>
"
                             value="<?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['control']->value->filters['filter_title']['selected'], ENT_QUOTES, 'UTF-8', true);?>
" />
      </td>
    </tr>
  <?php }?>
  
  <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_status']) {?>
    <tr>
      <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['status'];?>
</td>
      <td>
         <select class="chosen-select" id="filter_status"
        <?php if ($_smarty_tpl->tpl_vars['control']->value->advanced_filter_mode) {?>
                  name="filter_status[]"
                  multiple="multiple"
                  size="<?php echo $_smarty_tpl->tpl_vars['control']->value->filter_item_quantity;?>
" >
        <?php } else { ?>
                  name="filter_status">
        <?php }?>
          <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_status']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_status']['selected']),$_smarty_tpl);?>

          </select>
          
      </td>
    </tr>
  <?php }?>
  
  <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_type']) {?>
    <tr>
      <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['req_type'];?>
</td>
      <td>
        <select class="chosen-select" id="filter_type" 
        <?php if ($_smarty_tpl->tpl_vars['control']->value->advanced_filter_mode) {?>
                  name="filter_type[]"
                  multiple="multiple"
                  size="<?php echo $_smarty_tpl->tpl_vars['control']->value->filter_item_quantity;?>
" >
        <?php } else { ?>
                  name="filter_type">
        <?php }?>
          <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_type']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_type']['selected']),$_smarty_tpl);?>

          </select>
      </td>
    </tr>
  <?php }?>

  <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_spec_type']) {?>
    <tr>
      <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['req_spec_type'];?>
</td>
      <td>
        <select class="chosen-select" id="filter_spec_type" 
        <?php if ($_smarty_tpl->tpl_vars['control']->value->advanced_filter_mode) {?>
                  name="filter_spec_type[]"
                  multiple="multiple"
                  size="<?php echo $_smarty_tpl->tpl_vars['control']->value->filter_item_quantity;?>
" >
        <?php } else { ?>
                  name="filter_spec_type">
        <?php }?>
          <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_spec_type']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_spec_type']['selected']),$_smarty_tpl);?>

          </select>
      </td>
    </tr>
  <?php }?>

  <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_coverage']) {?>
    <tr>
      <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['req_expected_coverage'];?>
</td>
      <td><input type="text" name="filter_coverage"
                             size="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'COVERAGE_SIZE');?>
"
                             maxlength="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'COVERAGE_MAXLEN');?>
"
                             value="<?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['control']->value->filters['filter_coverage']['selected'], ENT_QUOTES, 'UTF-8', true);?>
" />
      </td>
    </tr>
  <?php }?>
  
  <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_relation']) {?>
    <tr>
      <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['has_relation_type'];?>
</td>
      <td>
        <select class="chosen-select" id="filter_relation"
        <?php if ($_smarty_tpl->tpl_vars['control']->value->advanced_filter_mode) {?>
                  name="filter_relation[]"
                  multiple="multiple"
                  size="<?php echo $_smarty_tpl->tpl_vars['control']->value->filter_item_quantity;?>
" >
        <?php } else { ?>
              name="filter_relation">
        <?php }?>
          <?php echo smarty_function_html_options(array('options'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_relation']['items'],'selected'=>$_smarty_tpl->tpl_vars['control']->value->filters['filter_relation']['selected']),$_smarty_tpl);?>

          </select>
      </td>
    </tr>
  <?php }?>
  
  <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_tc_id']) {?>
    <tr>
      <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['th_tcid'];?>
</td>
      <td><input type="text" name="filter_tc_id"
                             size="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TC_ID_SIZE');?>
"
                             maxlength="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TC_ID_MAXLEN');?>
"
                             value="<?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['control']->value->filters['filter_tc_id']['selected'], ENT_QUOTES, 'UTF-8', true);?>
" />
      </td>
    </tr>
  <?php }?>
  
  <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_custom_fields'] && !$_smarty_tpl->tpl_vars['control']->value->filters['filter_custom_fields']['collapsed']) {?>
    <tr><td>&nbsp;</td></tr>
    <?php echo $_smarty_tpl->tpl_vars['control']->value->filters['filter_custom_fields']['items'];?>

  <?php }?>
  
  </table>
  
  <div>
    <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="submit"
           value="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_apply_filter'];?>
"
           id="doUpdateTree"
           name="doUpdateTree"
           style="font-size: 90%;" />

    <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="submit"
           value="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_reset_filters'];?>
"
           id="doResetTree"
           name="btn_reset_filters"
           style="font-size: 90%;" />
    
    <?php if ($_smarty_tpl->tpl_vars['control']->value->filters['filter_custom_fields']) {?>
      <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="submit"
             value="<?php echo $_smarty_tpl->tpl_vars['control']->value->filters['filter_custom_fields']['btn_label'];?>
"
             id="doToggleCF"
             name="btn_toggle_cf"
             style="font-size: 90%;" />
    <?php }?>
    
    <?php if ($_smarty_tpl->tpl_vars['control']->value->filter_mode_choice_enabled) {?>
      
      <?php if ($_smarty_tpl->tpl_vars['control']->value->advanced_filter_mode) {?>
        <input type="hidden" name="btn_advanced_filters" value="1" />
      <?php }?>
      
      <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="submit" 
             id="toggleFilterMode"  
             name="<?php echo $_smarty_tpl->tpl_vars['control']->value->filter_mode_button_name;?>
"
             value="<?php echo $_smarty_tpl->tpl_vars['control']->value->filter_mode_button_label;?>
"
             style="font-size: 90%;"  />
    <?php }?>

  </div>
  
  </div>   </div> <?php }?> 
<?php if ($_smarty_tpl->tpl_vars['control']->value->draw_tc_unassign_button) {?>
  <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="button" 
         style="font-size: 90%;"
         name="removen_all_tester_assignments"
         value="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_bulk_remove'];?>
"
         onclick="javascript:delete_testers_from_build(<?php echo $_smarty_tpl->tpl_vars['control']->value->settings['setting_build']['selected'];?>
);"
  />
<?php }
if (false && $_smarty_tpl->tpl_vars['control']->value->draw_tc_assignment_bulk_copy_button) {?>
  <input type="button" style="font-size: 90%;"
         name="copy_tester_assignments"
         value="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_bulk_copy'];?>
"
         onclick="javascript:copy_tester_assignments_from_build(<?php echo $_smarty_tpl->tpl_vars['control']->value->settings['setting_build']['selected'];?>
);"
  />
<?php }?>
</form>
<p>

<?php echo '<script'; ?>
>
/* Chosen Config */
jQuery( document ).ready(function() {
jQuery(".chosen-select").chosen({ width: "85%" , allow_single_deselect: true, search_contains: true});
jQuery('select[data-cfield="list"]').chosen({ width: "85%" , allow_single_deselect: true, search_contains: true});
});
<?php echo '</script'; ?>
><?php }
}
