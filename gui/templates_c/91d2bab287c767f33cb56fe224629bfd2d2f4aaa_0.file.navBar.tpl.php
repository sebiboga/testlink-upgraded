<?php
/* Smarty version 4.5.7, created on 2026-08-17 07:00:53
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\navBar.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82b1a5675cc5_16652932',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '91d2bab287c767f33cb56fe224629bfd2d2f4aaa' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\navBar.tpl',
      1 => 1786950024,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:inc_head.tpl' => 1,
  ),
),false)) {
function content_6a82b1a5675cc5_16652932 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_checkPlugins(array(0=>array('file'=>'C:\\sebi\\CLAUDE\\testlink-upgraded\\vendor\\smarty\\smarty\\libs\\plugins\\modifier.replace.php','function'=>'smarty_modifier_replace',),1=>array('file'=>'C:\\sebi\\CLAUDE\\testlink-upgraded\\vendor\\smarty\\smarty\\libs\\plugins\\modifier.truncate.php','function'=>'smarty_modifier_truncate',),));
echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('var'=>"labels",'s'=>"title_events,event_viewer,home,testproject,title_specification,
     title_execute,testplan,title_edit_personal_data,th_tcid,
     link_logout,title_admin,search_testcase,title_results,
     title_user_mgmt,full_text_search,reload_main_view,
     toggle_navigation"),$_smarty_tpl ) );?>


<?php $_smarty_tpl->_assignInScope('cfg_section', smarty_modifier_replace(basename($_smarty_tpl->source->filepath),".tpl",''));
$_smarty_tpl->smarty->ext->configLoad->_loadConfigFile($_smarty_tpl, "input_dimensions.conf", $_smarty_tpl->tpl_vars['cfg_section']->value, 0);
?>


<?php $_smarty_tpl->_subTemplateRender("file:inc_head.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array('openHead'=>"yes"), 0, false);
?>

  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?php echo $_smarty_tpl->tpl_vars['fontawesomeHomeURL']->value;?>
/css/all.css" rel="stylesheet" />



  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
css/style.css" rel="stylesheet">
  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
css/style-responsive.css" rel="stylesheet">
</head>

<!--
IMPORTANT DEVELOPMENT INFORMATION
=================================
About the _top Value
The _top value of the target attribute specifies that the URL should open in the top browsing context (or current, if the current is already the top browsing context).
-->
<?php $_smarty_tpl->_assignInScope('topBrowsingContext', "_top");?>

<body style="min-width: 800px;">
    <header class="header black-bg">
      <div class="sidebar-toggle-box">
        <a href="index.php?tproject_id=<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
&tplan_id=<?php echo $_smarty_tpl->tpl_vars['gui']->value->tplan_id;?>
" 
           target="<?php echo $_smarty_tpl->tpl_vars['topBrowsingContext']->value;?>
">
        <div class="fas fa-sync tooltips" data-placement="right" data-original-title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['reload_main_view'];?>
"></div>
        </a>
      </div>
      <div class="sidebar-toggle-box">
        <div class="fa fa-bars tooltips" data-placement="right" data-original-title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['toggle_navigation'];?>
"></div>
      </div>
      <a class="logo" 
         href="index.php?tproject_id=<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
&tplan_id=<?php echo $_smarty_tpl->tpl_vars['gui']->value->tplan_id;?>
" target="<?php echo $_smarty_tpl->tpl_vars['topBrowsingContext']->value;?>
" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['reload_main_view'];?>
">
         <b>TEST<span>LINK</span></b></a>

  <div class="top-menu">
        <ul class="nav pull-right top-menu">

<?php if ($_smarty_tpl->tpl_vars['gui']->value->TestProjects != '') {?>
  <li class="combo">
    <form style="display:inline" name="projectForm"
          target="<?php echo $_smarty_tpl->tpl_vars['topBrowsingContext']->value;?>
"
          action="index.php?action=projectChange"
          method="post">
       <?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject'];?>

      <select style="font-size: 80%;position:relative; top:-1px;"
        name="testproject" id="projectSelect" onchange="var v=this.value; document.getElementById('tproj').value=v; this.form.submit();">
          <?php
$_from = $_smarty_tpl->smarty->ext->_foreach->init($_smarty_tpl, $_smarty_tpl->tpl_vars['gui']->value->TestProjects, 'tproject_name', false, 'item_id');
$_smarty_tpl->tpl_vars['tproject_name']->do_else = true;
if ($_from !== null) foreach ($_from as $_smarty_tpl->tpl_vars['item_id']->value => $_smarty_tpl->tpl_vars['tproject_name']->value) {
$_smarty_tpl->tpl_vars['tproject_name']->do_else = false;
?>
          <option value="<?php echo $_smarty_tpl->tpl_vars['item_id']->value;?>
" title="<?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['tproject_name']->value, ENT_QUOTES, 'UTF-8', true);?>
"
            <?php if ($_smarty_tpl->tpl_vars['item_id']->value == $_smarty_tpl->tpl_vars['gui']->value->tproject_id) {?> selected="selected" <?php }?>>
            <?php echo htmlspecialchars((string)smarty_modifier_truncate($_smarty_tpl->tpl_vars['tproject_name']->value,$_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TESTPROJECT_TRUNCATE_SIZE')), ENT_QUOTES, 'UTF-8', true);?>
</option>
        <?php
}
$_smarty_tpl->smarty->ext->_foreach->restore($_smarty_tpl, 1);?>
      </select>
      <input type="hidden" name="tproject_id" id="tproj" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
">
    </form>
  </li>

    <?php if ($_smarty_tpl->tpl_vars['gui']->value->testPlans != null) {?> 
    <li class="combo">
      <form style="display:inline" name="planForm" 
            target="<?php echo $_smarty_tpl->tpl_vars['topBrowsingContext']->value;?>
"
            action="index.php?action=planChange" 
            method="post">
        <?php echo $_smarty_tpl->tpl_vars['labels']->value['testplan'];?>

        <input type="hidden" name="tproject_id" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
">
        <select style="font-size: 80%;position:relative; top:-1px;" 
            name="tplan_id" onchange="this.form.submit();">
            <?php
$_from = $_smarty_tpl->smarty->ext->_foreach->init($_smarty_tpl, $_smarty_tpl->tpl_vars['gui']->value->testPlans, 'tplan', false, 'idx');
$_smarty_tpl->tpl_vars['tplan']->do_else = true;
if ($_from !== null) foreach ($_from as $_smarty_tpl->tpl_vars['idx']->value => $_smarty_tpl->tpl_vars['tplan']->value) {
$_smarty_tpl->tpl_vars['tplan']->do_else = false;
?>
            <?php $_smarty_tpl->_assignInScope('planID', $_smarty_tpl->tpl_vars['tplan']->value['id']);?> 
            <?php $_smarty_tpl->_assignInScope('planName', $_smarty_tpl->tpl_vars['tplan']->value['name']);?>
            <option value="<?php echo $_smarty_tpl->tpl_vars['planID']->value;?>
" title="<?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['planName']->value, ENT_QUOTES, 'UTF-8', true);?>
"
              <?php if ($_smarty_tpl->tpl_vars['planID']->value == $_smarty_tpl->tpl_vars['gui']->value->tplan_id) {?> selected="selected" <?php }?>>
              <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['planName']->value, ENT_QUOTES, 'UTF-8', true);?>
</option>
          <?php
}
$_smarty_tpl->smarty->ext->_foreach->restore($_smarty_tpl, 1);?>
        </select>
      </form>
    </li>
  <?php }?>


<?php }?>

          <li>&nbsp;</li>
          <li><a class="logout" href="<?php echo $_smarty_tpl->tpl_vars['gui']->value->logout;?>
" target="top">Logout</a></li>

        </ul>
      </div>
    </header>


  <?php echo '<script'; ?>
 type="text/javascript" 
    src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo (defined('TL_JQUERY') ? constant('TL_JQUERY') : null);?>
" 
    language="javascript"><?php echo '</script'; ?>
>


  <?php echo '<script'; ?>
 
    src="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
lib/bootstrap/js/bootstrap.min.js"><?php echo '</script'; ?>
>
  <?php echo '<script'; ?>
 src="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
lib/jquery.nicescroll.js" type="text/javascript"><?php echo '</script'; ?>
>
  <?php echo '<script'; ?>
 class="include" type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
lib/jquery.dcjqaccordion.2.7.js"><?php echo '</script'; ?>
>
  
  <!--common script for all pages-->
  <?php echo '<script'; ?>
 src="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
lib/common-scripts.js"><?php echo '</script'; ?>
>

</body>
</html><?php }
}
