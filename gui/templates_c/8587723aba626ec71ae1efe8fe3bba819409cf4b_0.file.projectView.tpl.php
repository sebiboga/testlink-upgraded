<?php
/* Smarty version 4.5.7, created on 2026-08-16 18:51:00
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\project\projectView.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a820694f05ed9_55520950',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '8587723aba626ec71ae1efe8fe3bba819409cf4b' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\project\\projectView.tpl',
      1 => 1786874980,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:inc_head.tpl' => 1,
    'file:inc_del_onclick.tpl' => 1,
    'file:bootstrap.inc.tpl' => 1,
    'file:DataTables.inc.tpl' => 1,
    'file:DataTablesColumnFiltering.inc.tpl' => 1,
    'file:aside.tpl' => 1,
    'file:supportJS.inc.tpl' => 1,
  ),
),false)) {
function content_6a820694f05ed9_55520950 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_checkPlugins(array(0=>array('file'=>'C:\\sebi\\CLAUDE\\testlink-upgraded\\vendor\\smarty\\smarty\\libs\\plugins\\modifier.replace.php','function'=>'smarty_modifier_replace',),));
?>

<?php $_smarty_tpl->_assignInScope('cfg_section', smarty_modifier_replace(call_user_func_array($_smarty_tpl->registered_plugins[ 'modifier' ][ 'basename' ][ 0 ], array( basename($_smarty_tpl->source->filepath) )),".tpl",''));
$_smarty_tpl->smarty->ext->configLoad->_loadConfigFile($_smarty_tpl, "input_dimensions.conf", $_smarty_tpl->tpl_vars['cfg_section']->value, 0);
?>


<?php $_smarty_tpl->_assignInScope('managerURL', $_smarty_tpl->tpl_vars['gui']->value->actions->managerURL);
$_smarty_tpl->_assignInScope('deleteAction', $_smarty_tpl->tpl_vars['gui']->value->actions->deleteAction);
$_smarty_tpl->_assignInScope('editAction', $_smarty_tpl->tpl_vars['gui']->value->actions->editAction);
$_smarty_tpl->_assignInScope('createAction', $_smarty_tpl->tpl_vars['gui']->value->actions->createAction);
$_smarty_tpl->_assignInScope('searchAction', $_smarty_tpl->tpl_vars['gui']->value->actions->searchAction);?>


<?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('s'=>'popup_product_delete','var'=>"warning_msg"),$_smarty_tpl ) );?>

<?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('s'=>'delete','var'=>"del_msgbox_title"),$_smarty_tpl ) );?>


<?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('var'=>"labels",'s'=>'title_testproject_management,testproject_txt_empty_list,tcase_id_prefix,
          th_name,th_notes,testproject_alt_edit,testproject_alt_active,btn_search_filter,
          th_requirement_feature,testproject_alt_delete,btn_create,public,hint_like_search_on_name,
          testproject_alt_requirement_feature,th_active,th_delete,th_id,btn_reset_filter,
          th_issuetracker,th_codetracker,th_reqmgrsystem_short,active_click_to_change,inactive_click_to_change,
          click_to_enable,click_to_disable'),$_smarty_tpl ) );?>



<?php $_smarty_tpl->_subTemplateRender("file:inc_head.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array('openHead'=>"yes",'enableTableSorting'=>"yes"), 0, false);
$_smarty_tpl->_subTemplateRender("file:inc_del_onclick.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>

<?php echo '<script'; ?>
 type="text/javascript">
/* All this stuff is needed for logic contained in inc_del_onclick.tpl */
var del_action=fRoot+'<?php echo $_smarty_tpl->tpl_vars['deleteAction']->value;?>
';
<?php echo '</script'; ?>
>

<?php $_smarty_tpl->_subTemplateRender("file:bootstrap.inc.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>

<?php if ($_smarty_tpl->tpl_vars['gui']->value->tprojects != '') {?>
  <?php $_smarty_tpl->_assignInScope('ll', $_smarty_tpl->tpl_vars['tlCfg']->value->gui->{$_smarty_tpl->tpl_vars['cfg_section']->value}->pagination->length);?>
    <?php $_smarty_tpl->_subTemplateRender("file:DataTables.inc.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array('DataTablesSelector'=>'','DataTablesLengthMenu'=>$_smarty_tpl->tpl_vars['ll']->value), 0, false);
?>
  <?php $_smarty_tpl->_subTemplateRender("file:DataTablesColumnFiltering.inc.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array('DataTablesSelector'=>"#item_view",'DataTablesLengthMenu'=>$_smarty_tpl->tpl_vars['ll']->value), 0, false);
}?>

</head>

<body <?php echo $_smarty_tpl->tpl_vars['body_onload']->value;?>
 style="background-color: #eaeaea">
<?php $_smarty_tpl->_subTemplateRender("file:aside.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>

<div id="main-content">
  <h1 class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TITLE_CLASS');?>
"><?php echo $_smarty_tpl->tpl_vars['gui']->value->pageTitle;?>
</h1>
  <div class="workBack">
    <div class="groupBtn">
      <?php if ($_smarty_tpl->tpl_vars['gui']->value->canManage) {?>
      <form id="createItem" id="createItem" method="post" action="<?php echo $_smarty_tpl->tpl_vars['createAction']->value;?>
" style="display:inline;">
        <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="submit"  id="create" name="create" value="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_create'];?>
" />
      </form>
      <?php }?>
    </div>
    <p>
    <div id="testproject_management_list">
      <?php if ($_smarty_tpl->tpl_vars['gui']->value->tprojects == '') {?>
        <?php if ($_smarty_tpl->tpl_vars['gui']->value->feedback != '') {?>
          <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['gui']->value->feedback, ENT_QUOTES, 'UTF-8', true);?>

        <?php } else { ?>
          <?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_txt_empty_list'];?>

        <?php }?>
      <?php } else { ?>
        <form method="post" id="testProjectView" name="testProjectView"
          action="<?php echo $_smarty_tpl->tpl_vars['managerURL']->value;?>
">
          <input type="hidden" name="doAction" id="doAction" value="">
          <input type="hidden" name="itemID" id="itemID" value="">
          <input type="hidden" name="tproject_id" id="tproject_id" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
">
          <input type="hidden" name="tplan_id" id="tplan_id" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->tplan_id;?>
">

          <table id="item_view" class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'item_view_table');?>
">
            <thead class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'item_view_thead');?>
">
              <tr>
                <th <?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'SMART_SEARCH');?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['th_name'];?>
</th>
                <th <?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'NOT_SORTABLE');?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['th_notes'];?>
</th>
                <th <?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'SMART_SEARCH');?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['tcase_id_prefix'];?>
</th>
                <th ><?php echo $_smarty_tpl->tpl_vars['labels']->value['th_issuetracker'];?>
</th>
                <th ><?php echo $_smarty_tpl->tpl_vars['labels']->value['th_codetracker'];?>
</th>
                <th class="icon_cell" <?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'NOT_SORTABLE');?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['th_requirement_feature'];?>
</th>
                <th class="icon_cell" <?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'NOT_SORTABLE');?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['th_active'];?>
</th>
                <th class="icon_cell" <?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'NOT_SORTABLE');?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['public'];?>
</th>
                <?php if ($_smarty_tpl->tpl_vars['gui']->value->canManage == "yes") {?>
                  <th class="icon_cell" <?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'NOT_SORTABLE');?>
></th>
                <?php }?>
              </tr>
            </thead>
            <tbody>
            <?php
$_from = $_smarty_tpl->smarty->ext->_foreach->init($_smarty_tpl, $_smarty_tpl->tpl_vars['gui']->value->tprojects, 'testproject');
$_smarty_tpl->tpl_vars['testproject']->do_else = true;
if ($_from !== null) foreach ($_from as $_smarty_tpl->tpl_vars['testproject']->value) {
$_smarty_tpl->tpl_vars['testproject']->do_else = false;
?>
            <tr>
              <td>   
              <i class="fas fa-cubes" style="cursor: help" title="API <?php echo smarty_modifier_replace($_smarty_tpl->tpl_vars['tlCfg']->value->api->id_format,"%s",$_smarty_tpl->tpl_vars['testproject']->value['id']);?>
"></i>
                     <a href="<?php echo $_smarty_tpl->tpl_vars['editAction']->value;
echo $_smarty_tpl->tpl_vars['testproject']->value['id'];?>
">
                     <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['testproject']->value['name'], ENT_QUOTES, 'UTF-8', true);?>

                     <?php if ($_smarty_tpl->tpl_vars['gsmarty_gui']->value->show_icon_edit) {?>
                          <img title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_alt_edit'];?>
" alt="<?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_alt_edit'];?>
"
                               src="<?php echo $_smarty_tpl->tpl_vars['tlImages']->value['edit'];?>
"/>
                      <?php }?>
                   </a>
              </td>
              <td>
                <?php if ($_smarty_tpl->tpl_vars['gui']->value->editorType == 'none') {
echo nl2br((string) $_smarty_tpl->tpl_vars['testproject']->value['notes'], (bool) 1);
} else {
echo $_smarty_tpl->tpl_vars['testproject']->value['notes'];
}?></td>
              </td>
              <td width="7%">
                <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['testproject']->value['prefix'], ENT_QUOTES, 'UTF-8', true);?>

              </td>
              
              <td width="7%">
                <?php echo $_smarty_tpl->tpl_vars['testproject']->value['itstatusImg'];?>
 &nbsp; <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['testproject']->value['itname'], ENT_QUOTES, 'UTF-8', true);?>
 
              </td>
              <td width="7%">
                <?php echo $_smarty_tpl->tpl_vars['testproject']->value['ctstatusImg'];?>
 &nbsp; <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['testproject']->value['ctname'], ENT_QUOTES, 'UTF-8', true);?>
 
              </td>
              <td class="clickable_icon">
                <?php if ($_smarty_tpl->tpl_vars['testproject']->value['opt']->requirementsEnabled) {?>
                  <i class="fas fa-toggle-on" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['active_click_to_change'];?>
"
                     onclick = "doAction.value='disableRequirements';itemID.value=<?php echo $_smarty_tpl->tpl_vars['testproject']->value['id'];?>
;$('#testProjectView').submit();"></i>       
                <?php } else { ?>
                  <i class="fas fa-toggle-off" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['inactive_click_to_change'];?>
"   
                     onclick = "doAction.value='enableRequirements';itemID.value=<?php echo $_smarty_tpl->tpl_vars['testproject']->value['id'];?>
;$('#testProjectView').submit();"></i>       
                <?php }?>
              </td>
              <td class="clickable_icon">
                <?php if ($_smarty_tpl->tpl_vars['testproject']->value['active']) {?>
                  <i class="fas fa-toggle-on" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['active_click_to_change'];?>
"
                     onclick="doAction.value='setInactive';itemID.value=<?php echo $_smarty_tpl->tpl_vars['testproject']->value['id'];?>
;$('#testProjectView').submit();"></i>       
                <?php } else { ?>
                  <i class="fas fa-toggle-off" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['inactive_click_to_change'];?>
"   
                     onclick="doAction.value='setActive';itemID.value=<?php echo $_smarty_tpl->tpl_vars['testproject']->value['id'];?>
;$('#testProjectView').submit();"></i>       
                <?php }?>
              </td>
              <td class="clickable_icon">
                <?php if ($_smarty_tpl->tpl_vars['testproject']->value['is_public']) {?>
                  <i class="fas fa-check-circle" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['public'];?>
"></i>
                <?php } else { ?>
                  &nbsp;
                <?php }?>
              </td>
              <?php if ($_smarty_tpl->tpl_vars['gui']->value->canManage == "yes") {?>
              <td class="clickable_icon">
                <i class="fas fa-minus-circle" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_alt_delete'];?>
" 
                   onclick="delete_confirmation(<?php echo $_smarty_tpl->tpl_vars['testproject']->value['id'];?>
,'<?php echo htmlspecialchars((string)strtr((string)$_smarty_tpl->tpl_vars['testproject']->value['name'], array("\\" => "\\\\", "'" => "\\'", "\"" => "\\\"", "\r" => "\\r", 
                       "\n" => "\\n", "</" => "<\/", "<!--" => "<\!--", "<s" => "<\s", "<S" => "<\S",
                       "`" => "\\`", "\${" => "\\\$\{")), ENT_QUOTES, 'UTF-8', true);?>
',
                                                '<?php echo $_smarty_tpl->tpl_vars['del_msgbox_title']->value;?>
','<?php echo $_smarty_tpl->tpl_vars['warning_msg']->value;?>
');"></i>

              </td>
              <?php }?>
            </tr>
            <?php
}
$_smarty_tpl->smarty->ext->_foreach->restore($_smarty_tpl, 1);?>
           </tbody>
          </table>
        </form>
      <?php }?>
    </div>
  </div>
</div>

<?php if ($_smarty_tpl->tpl_vars['gui']->value->doViewReload == true) {?>
  <?php echo '<script'; ?>
 type="text/javascript">
  
  // According to amount of test projects the user has found while accessing
  // the project edit feature TO CREATE a test project
  // the type and target of refresh will change
  //
  // remove query string to avoid reload of home page,
  // instead of reload only navbar
  // DEBUG -
  //DEBUG console.log('parent.titlebar.location.href -> ' + parent.titlebar.location.href);
  var href_pieces = parent.titlebar.location.href.split('?');

  <?php if ($_smarty_tpl->tpl_vars['gui']->value->projectCount > 0) {?>  
    // will refresh ONLY the NAVBAR
    // It seems that when operation is DELETE we will need to refresh also
    // the left side menu, but this has a minore annoyance
    // How to do this without exiting from the project view page??
    //
    var hn = href_pieces[0] + '?tproject_id=<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
&updateMainPage=1';
    //DEBUG console.log('planView.tpl -> ' + hn);
    parent.titlebar.location = hn;
  <?php } else { ?>
    //DEBUG alert('8888');
    //DEBUG console.log('888888 p - planView.tpl ->>>>>> ');
    
    // we are creating the FIRST Test Project, we need to update also the left side menu
    // var hn = href_pieces[0] + '?tproject_id=<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
&updateMainPage=1&activeMenu=projects';  
    var hn = href_pieces[0] + '?tproject_id=<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
' 
                            + '&updateMainPage=1&activeMenu=projects&projectView=1';  
    hn = hn.replace('lib/general/navBar.php','index.php');
    //DEBUG console.log('0 p - planView.tpl -> ' + hn);
    //DEBUG alert('9999');
    //DEBUG alert('0 p - planView.tpl -> ' + hn);

    parent.location = hn;
  <?php }?>
  <?php echo '</script'; ?>
>
<?php }?>

<?php $_smarty_tpl->_subTemplateRender("file:supportJS.inc.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>
</body>
</html><?php }
}
