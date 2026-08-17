<?php
/* Smarty version 4.5.7, created on 2026-08-17 07:00:53
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\aside.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82b1a5e3a239_02409390',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'f7f439ddcaa1cbf62db4b04c84fae73c2ab40d43' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\aside.tpl',
      1 => 1786912243,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6a82b1a5e3a239_02409390 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_assignInScope('whoami', basename($_smarty_tpl->source->filepath));
$_smarty_tpl->_subTemplateRender("./labels/labels.".((string)$_smarty_tpl->tpl_vars['whoami']->value), $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, true);
?> 
    <aside>
      <div id="sidebar" class="nav-collapse ">
        <!-- sidebar menu start-->
        <ul class="sidebar-menu" id="nav-accordion">
          <p class="centered"><img src="<?php echo $_smarty_tpl->tpl_vars['gui']->value->logo;?>
" alt="TestLink"></p>
          <h4 class="centered"><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->userInfo;?>
"><?php echo $_smarty_tpl->tpl_vars['gui']->value->whoami;?>
</a></h4>
          <?php if ($_smarty_tpl->tpl_vars['gui']->value->showMenu != null) {?>
            <?php if ($_smarty_tpl->tpl_vars['gui']->value->showMenu['dashboard'] == true) {?>
            <li class="mt">
              <a class="<?php echo $_smarty_tpl->tpl_vars['gui']->value->activeMenu['dashboard'];?>
" href="../project/projectView.php">
                <i class="fa fa-dashboard"></i>
                <span>Dashboard</span>
                </a>
            </li>
            <?php }?>
            <?php if ($_smarty_tpl->tpl_vars['gui']->value->showMenu['search'] == true) {?>
              <li class="sub-menu">
                <a id="a_search" href="javascript:;" class="<?php echo $_smarty_tpl->tpl_vars['gui']->value->activeMenu['search'];?>
">
                  <i class="fa fa-search"></i>
                  <span><?php echo $_smarty_tpl->tpl_vars['labels']->value['search'];?>
</span>
                  </a>
                <ul class="sub">
                  <li>
                    <a id="quick_search"
                      href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->tcSearch;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['quick_search'];?>
</a>
                  </li>
                  <li>
                    <a id="href_search_tc"
                      href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->tcSearch;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_search_tc'];?>
</a>
                  </li>
                  <li>
                    <a id="advanced_search"
                      href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->fullTextSearch;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['advanced_search'];?>
</a>
                  </li>
                </ul>
              </li>
            <?php }?>          
            <?php if ($_smarty_tpl->tpl_vars['gui']->value->showMenu['system'] == true) {?>
              <li class="sub-menu">
                <a id="system" href="javascript:;" class="<?php echo $_smarty_tpl->tpl_vars['gui']->value->activeMenu['system'];?>
">
                  <i class="fa fa-desktop"></i>
                  <span><?php echo $_smarty_tpl->tpl_vars['labels']->value['system'];?>
</span>
                  </a>
                <ul class="sub">
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->event_viewer == "yes") {?>
                    <li>
                    <a id="events"
                        href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->events;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['event_viewer'];?>
</a>
                    </li>
                  <?php }?>
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->user_mgmt == "yes") {?>
                    <li>
                      <a id="userMgmt" href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->userMgmt;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['title_user_mgmt'];?>
</a>
                    </li>
                  <?php }?>

                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->cfield_management == "yes") {?>
                    <li><a id="cfieldsView" href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->cfieldsView;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_cfields_management'];?>
</a>
                    </li>
                  <?php }?>
                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->access['issuetracker'] == 'yes') {?>
                    <li><a id="issueTrackerView" href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->issueTrackerView;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_issuetracker_management'];?>
</a></li>
                  <?php }?>
                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->access['codetracker'] == 'yes') {?>
                    <li><a id="codeTrackerView" href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->codeTrackerView;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_codetracker_management'];?>
</a></li>
                  <?php }?>
                </ul>
              </li>
            <?php }?>
            <?php if ($_smarty_tpl->tpl_vars['gui']->value->showMenu['projects'] == true) {?>
              <li class="sub-menu">
                <a id="projects" href="javascript:;" class="<?php echo $_smarty_tpl->tpl_vars['gui']->value->activeMenu['projects'];?>
">
                  <i class="fa fa-flask"></i>
                  <span><?php echo $_smarty_tpl->tpl_vars['labels']->value['projects'];?>
</span>
                  </a>
                <ul class="sub">
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->project_edit == "yes") {?>
                    <li><a id="projectView" href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->projectView;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_tproject_management'];?>
</a></li>
                  <?php }?>
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->tproject_user_role_assignment == "yes") {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->usersAssign;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_assign_user_roles'];?>
</a></li>
                  <?php }?>
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->cfield_management == "yes") {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->cfAssignment;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_cfields_tproject_assign'];?>
</a></li>
                  <?php }?>
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->keywords_view == "yes") {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->keywordsView;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_keywords_manage'];?>
</a></li>
                  <?php }?>
                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->access['platform'] == 'yes') {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->platformsView;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_platform_management'];?>
</a></li>
                  <?php }?>

                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->countPlans > 0) {?>
                  <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->metrics_dashboard;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_metrics_dashboard'];?>
</a>
                  </li>  
                  <?php }?>    
                </ul>
              </li>
            <?php }?>
            <?php if ($_smarty_tpl->tpl_vars['gui']->value->showMenu['requirements_design'] == true) {?>
              <li class="sub-menu">
                <a href="javascript:;" class="<?php echo $_smarty_tpl->tpl_vars['gui']->value->activeMenu['requirements_design'];?>
">
                  <i class="fas fa-prescription-bottle"></i>
                  <span><?php echo $_smarty_tpl->tpl_vars['labels']->value['requirements_design'];?>
</span>
                  </a>
                <ul class="sub">
                  <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->reqSpecMgmt;?>
">
                  <?php echo $_smarty_tpl->tpl_vars['labels']->value['href_req_spec'];?>
</a></li>
                  <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->reqOverView;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_req_overview'];?>
</a></li>
                  <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->printReqSpec;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_print_req'];?>
</a></li>
                  <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->searchReq;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_search_req'];?>
</a></li>  
                  <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->searchReqSpec;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_search_req_spec'];?>
</a></li>  
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->req_tcase_link_management == "yes") {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->assignReq;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_req_assign'];?>
</a></li>
                  <?php }?>
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->monitor_req == "yes") {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->reqMonOverView;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_req_monitor_overview'];?>
</a></li>
                  <?php }?>
                </ul>
              </li>
            <?php }?>
            <?php if ($_smarty_tpl->tpl_vars['gui']->value->showMenu['tests_design'] == true) {?>
              <li class="sub-menu">
                <a href="javascript:;" class="<?php echo $_smarty_tpl->tpl_vars['gui']->value->activeMenu['tests_design'];?>
">
                  <i class="fas fa-drafting-compass"></i>
                  <span><?php echo $_smarty_tpl->tpl_vars['labels']->value['tests_design'];?>
</span>
                  </a>
                <ul class="sub">
                  <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->testSpec;?>
">
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->modify_tc == "yes") {?>
                    <?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('s'=>'href_edit_tc'),$_smarty_tpl ) );?>

                  <?php } else { ?>
                    <?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('s'=>'href_browse_tc'),$_smarty_tpl ) );?>

                  <?php }?></a></li>
                  
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->view_tc == "yes") {?>
                  <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->tcSearch;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_search_tc'];?>
</a></li>
                  <?php }?>

                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->hasKeywords && $_smarty_tpl->tpl_vars['menuGrants']->value->keyword_assignment == "yes") {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->keywordsAssign;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_keywords_assign'];?>
</a></li>
                  <?php }?>
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->modify_tc == 'yes') {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->tcCreatedUser;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['link_report_test_cases_created_per_user'];?>
</a></li>
                  <?php }?>
                </ul>
              </li>
            <?php }?>
            <?php if ($_smarty_tpl->tpl_vars['gui']->value->showMenu['plans'] == true) {?>
              <li class="sub-menu">
                <a href="javascript:;" class="<?php echo $_smarty_tpl->tpl_vars['gui']->value->activeMenu['plans'];?>
">
                  <i class="fas fa-swatchbook"></i>
                  <span><?php echo $_smarty_tpl->tpl_vars['labels']->value['title_test_plan_mgmt'];?>
</span>
                  </a>
                <ul class="sub">
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->mgt_testplan_create == "yes") {?>
                  <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->planView;?>
">
                  <?php echo $_smarty_tpl->tpl_vars['labels']->value['href_plan_management'];?>
</a>
                  </li>
                  <?php }?>

                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->uri->buildView != null && $_smarty_tpl->tpl_vars['menuGrants']->value->testplan_create_build == "yes" && $_smarty_tpl->tpl_vars['gui']->value->countPlans > 0) {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->buildView;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_build_new'];?>
</a>
                    </li>  
                  <?php }?>    

                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->uri->planAddTC != null) {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->planAddTC;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_add_remove_test_cases'];?>
</a>
                    </li>  
                  <?php }?>

                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->uri->platformAssign != null && $_smarty_tpl->tpl_vars['menuGrants']->value->testplan_add_remove_platforms == "yes" && $_smarty_tpl->tpl_vars['gui']->value->countPlans > 0) {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->platformAssign;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_platform_assign'];?>
</a>
                    </li>  
                  <?php }?>    

                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->uri->setTestUrgency != null && $_smarty_tpl->tpl_vars['menuGrants']->value->testplan_set_urgent_testcases == "yes") {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->setTestUrgency;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_plan_assign_urgency'];?>
</a>
                  <?php }?>

                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->uri->planUpdateTC != null && $_smarty_tpl->tpl_vars['menuGrants']->value->testplan_update_linked_testcase_versions == "yes") {?>
                    <a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->planUpdateTC;?>
">
                    <?php echo $_smarty_tpl->tpl_vars['labels']->value['href_update_tplan'];?>
</a>
                  <?php }?> 

                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->uri->showNewestTCV != null && $_smarty_tpl->tpl_vars['menuGrants']->value->testplan_show_testcases_newest_versions == "yes") {?>
                    <a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->showNewestTCV;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_newest_tcversions'];?>
</a>
                  <?php }?> 
                </ul>
              </li>
            <?php }?>
            <?php if ($_smarty_tpl->tpl_vars['gui']->value->showMenu['execution'] == true) {?>
              <li class="sub-menu">
                <a href="javascript:;" class="<?php echo $_smarty_tpl->tpl_vars['gui']->value->activeMenu['execution'];?>
">
                  <i class="fas fa-gamepad"></i>
                  <span><?php echo $_smarty_tpl->tpl_vars['labels']->value['testcase_execution'];?>
</span>
                  </a>
                <ul class="sub">
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->testplan_execute == "yes") {?>
                    <?php $_smarty_tpl->_assignInScope('lbx', $_smarty_tpl->tpl_vars['labels']->value['href_execute_test']);?>
                  <?php }?>
                  <?php if ($_smarty_tpl->tpl_vars['menuGrants']->value->exec_ro_access == "yes") {?>  
                    <?php $_smarty_tpl->_assignInScope('lbx', $_smarty_tpl->tpl_vars['labels']->value['href_exec_ro_access']);?>
                  <?php }?>

                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->uri->executeTest != null) {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->executeTest;?>
">
                    <?php echo $_smarty_tpl->tpl_vars['lbx']->value;?>
</a></li>
                  <?php }?>

                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->uri->assignTCVExecution != null) {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->assignTCVExecution;?>
">
                    <?php echo $_smarty_tpl->tpl_vars['labels']->value['href_tc_exec_assignment'];?>
</a></li>
                  <?php }?>
                    
                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->uri->testcase_assignments != null && $_smarty_tpl->tpl_vars['menuGrants']->value->exec_testcases_assigned_to_me == "yes") {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->testcase_assignments;?>
"><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_my_testcase_assignments'];?>
</a>
                  <?php }?> 

                  <?php if ($_smarty_tpl->tpl_vars['gui']->value->uri->milestonesView != null && $_smarty_tpl->tpl_vars['menuGrants']->value->testplan_milestone_overview == "yes" && $_smarty_tpl->tpl_vars['gui']->value->countPlans > 0) {?>
                    <li><a href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->milestonesView;?>
" ><?php echo $_smarty_tpl->tpl_vars['labels']->value['href_plan_mstones'];?>
</a></li>
                  <?php }?>
                </ul>
              </li>
            <?php }?>

            <?php if ($_smarty_tpl->tpl_vars['gui']->value->showMenu['reports'] == true) {?>
              <li>
                <a class="<?php echo $_smarty_tpl->tpl_vars['gui']->value->activeMenu['reports'];?>
" 
                  href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->uri->showMetrics;?>
">
                  <i class="fas fa-chart-line"></i>
                  <span><?php echo $_smarty_tpl->tpl_vars['labels']->value['reports'];?>
</span>
                </a>
              </li>
            <?php }?>
          <?php }?>
        </ul>
        <!-- sidebar menu end-->
      </div>
    </aside>
<?php }
}
