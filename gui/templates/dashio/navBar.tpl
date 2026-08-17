{*
Testlink Open Source Project - http://testlink.sourceforge.net/

title bar + menu

@filesource navBar.tpl
*}
{lang_get var="labels"
  s="title_events,event_viewer,home,testproject,title_specification,
     title_execute,testplan,title_edit_personal_data,th_tcid,
     link_logout,title_admin,search_testcase,title_results,
     title_user_mgmt,full_text_search,reload_main_view,
     toggle_navigation"}

{$cfg_section=$smarty.template|replace:".tpl":""}
{config_load file="input_dimensions.conf" section=$cfg_section}

{include file="inc_head.tpl" openHead="yes"}

  <link href="{$dashioHome}lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="{$fontawesomeHomeURL}/css/all.css" rel="stylesheet" />



  <link href="{$dashioHome}css/style.css" rel="stylesheet">
  <link href="{$dashioHome}css/style-responsive.css" rel="stylesheet">

  {* .header is position:fixed, so it's out of normal flow and doesn't
     contribute to html/body's content height in this frame; whatever
     html/body's own background is shows through in any part of the
     navigationBar iframe (sized to 10% of viewport height in frame.css)
     that the fixed 60px header doesn't cover. Match it to the header so
     there's no visible seam, with !important since the inline style on
     body alone was not winning here. *}
  <style type="text/css">
  html, body {
    background: #DEDEAD !important;
  }
  </style>
</head>

<!--
IMPORTANT DEVELOPMENT INFORMATION
=================================
About the _top Value
The _top value of the target attribute specifies that the URL should open in the top browsing context (or current, if the current is already the top browsing context).
-->
{$topBrowsingContext = "_top"}

<body style="min-width: 800px; background: #DEDEAD; margin: 0;">
    <header class="header black-bg">
      <div class="sidebar-toggle-box">
        <div class="fa fa-bars tooltips" data-placement="right" data-original-title="{$labels.toggle_navigation}"></div>
      </div>
      {* Full logo (word mark baked into the image) instead of the solo mark
         plus a separate "TESTLINK" text label: now that the header
         background matches the login page's, the same dark-text logo used
         there reads fine here too. *}
      <a class="logo"
         href="index.php?tproject_id={$gui->tproject_id}&tplan_id={$gui->tplan_id}" target="{$topBrowsingContext}" title="{$labels.reload_main_view}">
         <img class="tlMark" src="{$basehref}{$smarty.const.TL_THEME_IMG_DIR}tl-logo-transparent-25.png" alt="TestLink"></a>

  <div class="top-menu">
        <ul class="nav pull-right top-menu">

{* style="margin-top: 20px;padding-right: 30px;" *}
{if $gui->TestProjects != ""}
  <li class="combo">
    <form style="display:inline" name="projectForm"
          target="{$topBrowsingContext}"
          action="index.php?action=projectChange"
          method="post">
       {$labels.testproject}
      <select style="font-size: 105%;position:relative; top:-1px;"
        name="testproject" id="projectSelect" onchange="switchTestProject(this);">
          {foreach key=item_id item=tproject_name from=$gui->TestProjects}
          <option value="{$item_id}" title="{$tproject_name|escape}"
            {if $item_id == $gui->tproject_id} selected="selected" {/if}>
            {$tproject_name|truncate:#TESTPROJECT_TRUNCATE_SIZE#|escape}</option>
        {/foreach}
      </select>
      <input type="hidden" name="tproject_id" id="tproj" value="{$gui->tproject_id}">
      <input type="hidden" name="returnFeature" id="returnFeature" value="">
    </form>
  </li>

  <script type="text/javascript">
  /* Switching test project reloads the whole frameset, so tell index.php which
     work area the main frame is showing to be able to come back to it. The
     feature alone is sent: item ids belong to the project being left behind.
     index.php decides which features may be returned to. */
  function switchTestProject(combo) {
    var feature = '';
    try {
      var mainFrame = window.top.frames['mainframe'];
      if (mainFrame) {
        var found = mainFrame.location.search.match(/[?&]feature=(\w+)/);
        if (found) {
          feature = found[1];
        }
      }
    } catch (accessDenied) {
      feature = '';
    }

    document.getElementById('returnFeature').value = feature;
    document.getElementById('tproj').value = combo.value;
    combo.form.submit();
  }
  </script>

  {* the place for test plans will be always displayed*}
  {if $gui->testPlans != null} 
    <li class="combo">
      <form style="display:inline" name="planForm" 
            target="{$topBrowsingContext}"
            action="index.php?action=planChange" 
            method="post">
        {$labels.testplan}
        <input type="hidden" name="tproject_id" value="{$gui->tproject_id}">
        <select style="font-size: 80%;position:relative; top:-1px;" 
            name="tplan_id" onchange="this.form.submit();">
            {foreach key=idx item=tplan from=$gui->testPlans}
            {$planID = $tplan['id']} 
            {$planName = $tplan['name']}
            <option value="{$planID}" title="{$planName|escape}"
              {if $planID == $gui->tplan_id} selected="selected" {/if}>
              {$planName|escape}</option>
          {/foreach}
        </select>
      </form>
    </li>
  {/if}


{/if}

          <li>&nbsp;</li>
          {* The user lives here rather than in the menu so it stays visible
             when the menu is collapsed to its icon rail, and so identity sits
             with Logout. *}
          {if isset($gui->whoami) && $gui->whoami != ''}
            <li class="tlWhoami">
              <a href="{$gui->uri->userInfo}" target="mainframe"
                 title="{$gui->whoami|escape}">{$gui->whoami|escape}</a>
            </li>
          {/if}
          <li><a class="logout" href="{$gui->logout}" target="top">Logout</a></li>

        </ul>
      </div>
    </header>


  <script type="text/javascript" 
    src="{$basehref}{$smarty.const.TL_JQUERY}" 
    language="javascript"></script>


  <script 
    src="{$dashioHome}lib/bootstrap/js/bootstrap.min.js"></script>
  <script src="{$dashioHome}lib/jquery.nicescroll.js" type="text/javascript"></script>
  <script class="include" type="text/javascript" src="{$dashioHome}lib/jquery.dcjqaccordion.2.7.js"></script>
  
  <!--common script for all pages-->
  <script src="{$dashioHome}lib/common-scripts.js"></script>

</body>
</html>