{*
Testlink Open Source Project - http://testlink.sourceforge.net/
Left side menu
@filesource aside.tpl
*}
{$whoami = $smarty.template}
{include file="./labels/labels.$whoami"} 
    <aside>
      <div id="sidebar" class="nav-collapse ">
        <!-- sidebar menu start-->
        <ul class="sidebar-menu" id="nav-accordion">
          {* The brand mark and the user are both in the title bar
             (navBar.tpl): they stay visible when this menu collapses to its
             icon rail, and the mark is not worth repeating. *}
          {if $gui->showMenu != null}
            {if $gui->showMenu.dashboard == true}
            <li class="mt">
              <a class="{$gui->activeMenu.dashboard}" href="{$gui->uri->projectView}" target="mainframe">
                <i class="fa fa-dashboard"></i>
                <span>Dashboard</span>
                </a>
            </li>
            {/if}
            {if $gui->showMenu.search == true}
              <li class="sub-menu">
                <a id="a_search" href="javascript:;" class="{$gui->activeMenu.search}">
                  <i class="fa fa-search"></i>
                  <span>{$labels.search}</span>
                  </a>
                <ul class="sub">
                  <li>
                    <a id="quick_search"
                      href="{$gui->uri->tcSearch}" target="mainframe">{$labels.quick_search}</a>
                  </li>
                  <li>
                    <a id="href_search_tc"
                      href="{$gui->uri->tcSearch}" target="mainframe">{$labels.href_search_tc}</a>
                  </li>
                  <li>
                    <a id="advanced_search"
                      href="{$gui->uri->fullTextSearch}" target="mainframe">{$labels.advanced_search}</a>
                  </li>
                </ul>
              </li>
            {/if}          
            {if $gui->showMenu.system == true}
              <li class="sub-menu">
                <a id="system" href="javascript:;" class="{$gui->activeMenu.system}">
                  <i class="fa fa-desktop"></i>
                  <span>{$labels.system}</span>
                  </a>
                <ul class="sub">
                  {if $menuGrants->event_viewer == "yes"}
                    <li>
                    <a id="events"
                        href="{$gui->uri->events}" target="mainframe">{$labels.event_viewer}</a>
                    </li>
                  {/if}
                  {if $menuGrants->user_mgmt == "yes"}
                    <li>
                      <a id="userInfo" href="{$gui->uri->userInfo}" target="mainframe"><i class="fa fa-user-circle"></i> User Profile</a>
                    </li>
                    <li>
                      <a id="userMgmt" href="{$gui->uri->userMgmt}" target="mainframe">{$labels.title_user_mgmt}</a>
                    </li>
                    <li>
                      <a href="{$gui->uri->rolesView}" target="mainframe">Role Management</a>
                    </li>
                    <li>
                      <a href="{$gui->uri->usersAssign}" target="mainframe">Assign Test Project Roles</a>
                    </li>
                    <li>
                      <a href="{$gui->uri->usersAssignPlan}" target="mainframe">Assign Test Plan Roles</a>
                    </li>
                  {/if}

                  {if $menuGrants->cfield_management == "yes"}
                    <li><a id="cfieldsView" href="{$gui->uri->cfieldsView}" target="mainframe">{$labels.href_cfields_management}</a>
                    </li>
                  {/if}
                  {if $gui->access.issuetracker == 'yes'}
                    <li><a id="issueTrackerView" href="{$gui->uri->issueTrackerView}" target="mainframe">{$labels.href_issuetracker_management}</a></li>
                  {/if}
                  {if $gui->access.codetracker == 'yes'}
                    <li><a id="codeTrackerView" href="{$gui->uri->codeTrackerView}" target="mainframe">{$labels.href_codetracker_management}</a></li>
                  {/if}
                </ul>
              </li>
            {/if}
            {if $gui->showMenu.projects == true}
              <li class="sub-menu">
                <a id="projects" href="javascript:;" class="{$gui->activeMenu.projects}">
                  <i class="fa fa-flask"></i>
                  <span>{$labels.projects}</span>
                  </a>
                <ul class="sub">
                  {if $menuGrants->project_edit == "yes"}
                    <li><a id="projectView" href="{$gui->uri->projectView}" target="mainframe">{$labels.href_tproject_management}</a></li>
                  {/if}
                  {if $menuGrants->tproject_user_role_assignment == "yes"}
                    <li><a href="{$gui->uri->usersAssign}" target="mainframe">{$labels.href_assign_user_roles}</a></li>
                  {/if}
                  {if $menuGrants->cfield_management == "yes"}
                    <li><a href="{$gui->uri->cfAssignment}" target="mainframe">{$labels.href_cfields_tproject_assign}</a></li>
                  {/if}
                  {if $menuGrants->keywords_view == "yes"}
                    <li><a href="{$gui->uri->keywordsView}" target="mainframe">{$labels.href_keywords_manage}</a></li>
                  {/if}
                  {if $gui->access.platform == 'yes'}
                    <li><a href="{$gui->uri->platformsView}" target="mainframe">{$labels.href_platform_management}</a></li>
                  {/if}

                  {* project_inventory_view/management come back as the string
                     "yes" normally, but as int 1/0 when the test project has
                     inventory explicitly enabled (see getGrantSetWithExit()) -
                     check truthiness rather than an exact "yes" match. *}
                  {if $menuGrants->project_inventory_view || $menuGrants->project_inventory_management}
                    <li><a href="{$gui->uri->inventoryView}" target="mainframe">{$labels.href_inventory_management}</a></li>
                  {/if}

                  {if $gui->countPlans > 0}
                  <li><a href="{$gui->uri->metrics_dashboard}" target="mainframe">{$labels.href_metrics_dashboard}</a>
                  </li>  
                  {/if}    
                </ul>
              </li>
            {/if}
            {if $gui->showMenu.requirements_design == true}
              <li class="sub-menu">
                <a href="javascript:;" class="{$gui->activeMenu.requirements_design}">
                  <i class="fas fa-prescription-bottle"></i>
                  <span>{$labels.requirements_design}</span>
                  </a>
                <ul class="sub">
                  <li><a href="{$gui->uri->reqSpecMgmt}" target="mainframe">
                  {$labels.href_req_spec}</a></li>
                  <li><a href="{$gui->uri->reqOverView}" target="mainframe">{$labels.href_req_overview}</a></li>
                  <li><a href="{$gui->uri->printReqSpec}" target="mainframe">{$labels.href_print_req}</a></li>
                  <li><a href="{$gui->uri->searchReq}" target="mainframe">{$labels.href_search_req}</a></li>  
                  <li><a href="{$gui->uri->searchReqSpec}" target="mainframe">{$labels.href_search_req_spec}</a></li>  
                  {if $menuGrants->req_tcase_link_management == "yes"}
                    <li><a href="{$gui->uri->assignReq}" target="mainframe">{$labels.href_req_assign}</a></li>
                  {/if}
                  {if $menuGrants->monitor_req == "yes"}
                    <li><a href="{$gui->uri->reqMonOverView}" target="mainframe">{$labels.href_req_monitor_overview}</a></li>
                  {/if}
                </ul>
              </li>
            {/if}
            {if $gui->showMenu.tests_design == true}
              <li class="sub-menu">
                <a href="javascript:;" class="{$gui->activeMenu.tests_design}">
                  <i class="fas fa-drafting-compass"></i>
                  <span>{$labels.tests_design}</span>
                  </a>
                <ul class="sub">
                  <li><a href="{$gui->uri->testSpec}" target="mainframe">
                  {if $menuGrants->modify_tc eq "yes"}
                    {lang_get s='href_edit_tc'}
                  {else}
                    {lang_get s='href_browse_tc'}
                  {/if}</a></li>
                  
                  {if $menuGrants->view_tc == "yes"}
                  <li><a href="{$gui->uri->tcSearch}" target="mainframe">{$labels.href_search_tc}</a></li>
                  {/if}

                  {if $gui->hasKeywords && 
                      $menuGrants->keyword_assignment == "yes"}
                    <li><a href="{$gui->uri->keywordsAssign}" target="mainframe">{$labels.href_keywords_assign}</a></li>
                  {/if}
                  {if $menuGrants->modify_tc == 'yes'}
                    <li><a href="{$gui->uri->tcCreatedUser}" target="mainframe">{$labels.link_report_test_cases_created_per_user}</a></li>
                  {/if}
                </ul>
              </li>
            {/if}
            {if $gui->showMenu.plans == true}
              <li class="sub-menu">
                <a href="javascript:;" class="{$gui->activeMenu.plans}">
                  <i class="fas fa-swatchbook"></i>
                  <span>{$labels.title_test_plan_mgmt}</span>
                  </a>
                <ul class="sub">
                  {if $menuGrants->mgt_testplan_create == "yes"}
                  <li><a href="{$gui->uri->planView}" target="mainframe">
                  {$labels.href_plan_management}</a>
                  </li>
                  {if $gui->countPlans > 0}
                    <li><a href="{$gui->uri->usersAssignPlan}" target="mainframe">Assign Test Plan Roles</a></li>
                  {/if}
                  {/if}

                  {if $gui->uri->buildView != null 
                      && $menuGrants->testplan_create_build == "yes" 
                      && $gui->countPlans > 0}
                    <li><a href="{$gui->uri->buildView}" target="mainframe">{$labels.href_build_new}</a>
                    </li>  
                  {/if}    

                  {if $gui->uri->planAddTC != null}
                    <li><a href="{$gui->uri->planAddTC}" target="mainframe">{$labels.href_add_remove_test_cases}</a>
                    </li>  
                  {/if}

                  {if $gui->uri->platformAssign != null
                      && $menuGrants->testplan_add_remove_platforms == "yes" 
                      && $gui->countPlans > 0}
                    <li><a href="{$gui->uri->platformAssign}" target="mainframe">{$labels.href_platform_assign}</a>
                    </li>  
                  {/if}    

                  {if $gui->uri->setTestUrgency != null
                      && $menuGrants->testplan_set_urgent_testcases == "yes"}
                    <li><a href="{$gui->uri->setTestUrgency}" target="mainframe">{$labels.href_plan_assign_urgency}</a>
                  {/if}

                  {if $gui->uri->planUpdateTC != null
                      && $menuGrants->testplan_update_linked_testcase_versions == "yes"}
                    <a href="{$gui->uri->planUpdateTC}" target="mainframe">
                    {$labels.href_update_tplan}</a>
                  {/if} 

                  {if $gui->uri->showNewestTCV != null
                      && $menuGrants->testplan_show_testcases_newest_versions == "yes"}
                    <a href="{$gui->uri->showNewestTCV}" target="mainframe">{$labels.href_newest_tcversions}</a>
                  {/if} 
                </ul>
              </li>
            {/if}
            {if $gui->showMenu.execution == true}
              <li class="sub-menu">
                <a href="javascript:;" class="{$gui->activeMenu.execution}">
                  <i class="fas fa-gamepad"></i>
                  <span>{$labels.testcase_execution}</span>
                  </a>
                <ul class="sub">
                  {if $menuGrants->testplan_execute == "yes"}
                    {$lbx = $labels.href_execute_test}
                  {/if}
                  {if $menuGrants->exec_ro_access == "yes"}  
                    {$lbx = $labels.href_exec_ro_access}
                  {/if}

                  {if $gui->uri->executeTest != null}
                    <li><a href="{$gui->uri->executeTest}" target="mainframe">
                    {$lbx}</a></li>
                  {/if}

                  {if $gui->uri->assignTCVExecution != null}
                    <li><a href="{$gui->uri->assignTCVExecution}" target="mainframe">
                    {$labels.href_tc_exec_assignment}</a></li>
                  {/if}
                    
                  {if $gui->uri->testcase_assignments != null
                      && $menuGrants->exec_testcases_assigned_to_me == "yes"}
                    <li><a href="{$gui->uri->testcase_assignments}" target="mainframe">{$labels.href_my_testcase_assignments}</a>
                  {/if} 

                  {if $gui->uri->milestonesView != null
                      && $menuGrants->testplan_milestone_overview == "yes" 
                      && $gui->countPlans > 0}
                    <li><a href="{$gui->uri->milestonesView}" target="mainframe" >{$labels.href_plan_mstones}</a></li>
                  {/if}
                </ul>
              </li>
            {/if}

            {if $gui->showMenu.reports == true}
              <li>
                <a class="{$gui->activeMenu.reports}" 
                  href="{$gui->uri->showMetrics}" target="mainframe">
                  <i class="fas fa-chart-line"></i>
                  <span>{$labels.reports}</span>
                </a>
              </li>
            {/if}
            {* Plugins can inject links via EVENT_LEFTMENU_TOP/BOTTOM and
               EVENT_RIGHTMENU_TOP/BOTTOM (see e.g. plugins/TLTest/TLTest.php).
               tl-classic spreads those across two separate page columns that
               this single-rail sidebar has no equivalent of, so group
               whatever any plugin registers - plus the plugin management
               page itself, moved here from the System section since it's
               the same subject - into one "Plugins" section instead of
               floating loose links at the very top/bottom of the whole menu.
               event_signal() returns one entry per plugin listening on a
               given event, hence the nested foreach. *}
            {if $gui->plugins|@count > 0 || $menuGrants->plugin_management == "yes"}
              <li class="sub-menu">
                <a href="javascript:;" class="{$gui->activeMenu.plugins}">
                  <i class="fas fa-puzzle-piece"></i>
                  <span>Plugins</span>
                  </a>
                <ul class="sub">
                  {if $menuGrants->plugin_management == "yes"}
                    <li><a id="pluginView" href="{$gui->uri->pluginView}" target="mainframe">{$labels.installed_plugins}</a></li>
                  {/if}
                  {foreach from=$gui->plugins item=eventLinks}
                    {foreach from=$eventLinks item=menu_item}
                      <li><a href="{$menu_item.href}" target="mainframe">{$menu_item.label}</a></li>
                    {/foreach}
                  {/foreach}
                </ul>
              </li>
            {/if}
          {/if}
        </ul>
        <!-- sidebar menu end-->
      </div>
    </aside>
