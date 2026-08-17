<?php
/* Smarty version 4.5.7, created on 2026-08-17 07:04:01
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\testcases\containerView.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82b2610ecd84_67009365',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '1da54e3649736b521177159146a310c50723ffc2' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\testcases\\containerView.tpl',
      1 => 1786911755,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:inc_head.tpl' => 1,
    'file:inc_del_onclick.tpl' => 1,
    'file:bootstrap.inc.tpl' => 1,
    'file:inc_update.tpl' => 1,
    'file:containerViewTestSuiteTextButtons.inc.tpl' => 1,
    'file:tsuiteViewerRO.inc.tpl' => 1,
    'file:attachments.inc.tpl' => 1,
    'file:inc_refreshTreeWithFilters.tpl' => 1,
  ),
),false)) {
function content_6a82b2610ecd84_67009365 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_checkPlugins(array(0=>array('file'=>'C:\\sebi\\CLAUDE\\testlink-upgraded\\vendor\\smarty\\smarty\\libs\\plugins\\modifier.replace.php','function'=>'smarty_modifier_replace',),));
$_smarty_tpl->_assignInScope('cfg', smarty_modifier_replace(call_user_func_array($_smarty_tpl->registered_plugins[ 'modifier' ][ 'basename' ][ 0 ], array( basename($_smarty_tpl->source->filepath) )),".tpl",''));
$_smarty_tpl->smarty->ext->configLoad->_loadConfigFile($_smarty_tpl, "input_dimensions.conf", $_smarty_tpl->tpl_vars['cfg']->value, 0);
?>


<?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('var'=>'labels','s'=>'th_product_name,edit_testproject_basic_data,
     th_notes,test_suite,
     details,none,keywords,alt_del_testsuite,
     alt_edit_testsuite, alt_move_cp_testcases, 
     alt_move_cp_testsuite, 
     btn_new_testsuite, btn_reorder,btn_execute_automatic_testcases,
     btn_edit_testsuite,btn_del_testsuite,btn_move_cp_testsuite,
     btn_testcases_table_view,
     btn_del_testsuites_bulk,btn_delete_testcases,
     btn_reorder_testcases_alpha,
     btn_reorder_testcases_externalid,btn_reorder_testsuites_alpha,
     actions,btn_gen_test_spec,btn_gen_test_spec_new_window,
     btn_gen_test_spec_word,btn_gen_test_suite_spec_word,
     btn_gen_test_suite_spec,btn_gen_test_suite_spec_new_window,
     btn_export_testsuite, btn_export_all_testsuites, 
     btn_import_testsuite,btn_new_tc,btn_move_cp_testcases, btn_import_tc, btn_export_tc, th_testplan_name,
     testsuite_operations,testcase_operations,
     btn_create_from_issue_xml,file_upload_ko'),$_smarty_tpl ) );?>


<?php $_smarty_tpl->_assignInScope('ft', '');
if ((isset($_smarty_tpl->tpl_vars['gui']->value->form_token))) {?>
  <?php $_smarty_tpl->_assignInScope('ft', $_smarty_tpl->tpl_vars['gui']->value->form_token);
}?>

<?php $_smarty_tpl->_assignInScope('container_id', $_smarty_tpl->tpl_vars['gui']->value->container_data['id']);
$_smarty_tpl->_assignInScope('tproject_id', $_smarty_tpl->tpl_vars['gui']->value->tproject_id);
$_smarty_tpl->_assignInScope('tplan_id', $_smarty_tpl->tpl_vars['gui']->value->tplan_id);?>


<?php $_smarty_tpl->_assignInScope('tcImportAction', "lib/testcases/tcImport.php?containerID=".((string)$_smarty_tpl->tpl_vars['container_id']->value));
$_smarty_tpl->_assignInScope('importToTProjectAction', ((string)$_smarty_tpl->tpl_vars['basehref']->value).((string)$_smarty_tpl->tpl_vars['tcImportAction']->value)."&bIntoProject=1&useRecursion=1&tproject_id=".((string)$_smarty_tpl->tpl_vars['tproject_id']->value));
$_smarty_tpl->_assignInScope('importToTSuiteAction', ((string)$_smarty_tpl->tpl_vars['basehref']->value).((string)$_smarty_tpl->tpl_vars['tcImportAction']->value)."&useRecursion=1");
$_smarty_tpl->_assignInScope('importTestCasesAction', ((string)$_smarty_tpl->tpl_vars['basehref']->value).((string)$_smarty_tpl->tpl_vars['tcImportAction']->value));
$_smarty_tpl->_assignInScope('tcExportAction', "lib/testcases/tcExport.php?tproject_id=".((string)$_smarty_tpl->tpl_vars['tproject_id']->value)."&containerID=".((string)$_smarty_tpl->tpl_vars['container_id']->value)."&amp;form_token=".((string)$_smarty_tpl->tpl_vars['ft']->value));
$_smarty_tpl->_assignInScope('exportTestCasesAction', ((string)$_smarty_tpl->tpl_vars['basehref']->value).((string)$_smarty_tpl->tpl_vars['tcExportAction']->value));?>

<?php $_smarty_tpl->_assignInScope('testSpecFullDocAction', "lib/results/printDocument.php?type=testspec&level=testproject&allOptionsOn=1&format=0&id=".((string)$_smarty_tpl->tpl_vars['container_id']->value)."&form_token=".((string)$_smarty_tpl->tpl_vars['ft']->value));
$_smarty_tpl->_assignInScope('testSpecFullDocAction', ((string)$_smarty_tpl->tpl_vars['basehref']->value).((string)$_smarty_tpl->tpl_vars['testSpecFullDocAction']->value));?>

<?php $_smarty_tpl->_assignInScope('testSpecFullWordDocAction', "lib/results/printDocument.php?type=testspec&level=testproject&allOptionsOn=1&format=4&id=".((string)$_smarty_tpl->tpl_vars['container_id']->value));
$_smarty_tpl->_assignInScope('testSpecFullWordDocAction', ((string)$_smarty_tpl->tpl_vars['basehref']->value).((string)$_smarty_tpl->tpl_vars['testSpecFullWordDocAction']->value));?>

<?php $_smarty_tpl->_assignInScope('testSuiteDocAction', "lib/results/printDocument.php?type=testspec&level=testsuite&allOptionsOn=1&format=0&id=".((string)$_smarty_tpl->tpl_vars['container_id']->value)."&form_token=".((string)$_smarty_tpl->tpl_vars['ft']->value));
$_smarty_tpl->_assignInScope('testSuiteDocAction', ((string)$_smarty_tpl->tpl_vars['basehref']->value).((string)$_smarty_tpl->tpl_vars['testSuiteDocAction']->value));?>

<?php $_smarty_tpl->_assignInScope('testSuiteWordDocAction', "lib/results/printDocument.php?type=testspec&level=testsuite&allOptionsOn=1&format=4&id=".((string)$_smarty_tpl->tpl_vars['container_id']->value));
$_smarty_tpl->_assignInScope('testSuiteWordDocAction', ((string)$_smarty_tpl->tpl_vars['basehref']->value).((string)$_smarty_tpl->tpl_vars['testSuiteWordDocAction']->value));?>


<?php $_smarty_tpl->_assignInScope('tsuiteExportAction', ((string)$_smarty_tpl->tpl_vars['basehref']->value).((string)$_smarty_tpl->tpl_vars['tcExportAction']->value)."&amp;useRecursion=1&amp;form_token=".((string)$_smarty_tpl->tpl_vars['ft']->value));?>

<?php $_smarty_tpl->_assignInScope('tcMantisXMLAction', "lib/testcases/tcCreateFromIssueMantisXML.php?containerID=".((string)$_smarty_tpl->tpl_vars['container_id']->value));
$_smarty_tpl->_assignInScope('createTCFromIssueMantisXMLAction', ((string)$_smarty_tpl->tpl_vars['basehref']->value).((string)$_smarty_tpl->tpl_vars['tcMantisXMLAction']->value));?>


<?php $_smarty_tpl->_subTemplateRender("file:inc_head.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array('openHead'=>"yes"), 0, false);
$_smarty_tpl->_assignInScope('ext_location', (defined('TL_EXTJS_RELATIVE_PATH') ? constant('TL_EXTJS_RELATIVE_PATH') : null));?>
<link rel="stylesheet" type="text/css" href="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/css/ext-all.css" />

<?php $_smarty_tpl->_subTemplateRender("file:inc_del_onclick.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array('openHead'=>"yes"), 0, false);
?>

<?php echo '<script'; ?>
 type="text/javascript">
/**
 * Be Carefull this TRUST on existence of $gui->delAttachmentURL
 */
function jsCallDeleteFile(btn, text, o_id)
{ 
  var my_action='';
  if( btn == 'yes' )
  {
    my_action='<?php echo $_smarty_tpl->tpl_vars['gui']->value->delAttachmentURL;?>
'+o_id;
    window.location=my_action;
  }
}        
<?php echo '</script'; ?>
> 

<?php $_smarty_tpl->_subTemplateRender("file:bootstrap.inc.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
echo '<script'; ?>
 src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
third_party/bootbox/bootbox.all.min.js"><?php echo '</script'; ?>
>
</head>

<body>
<h1 class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TITLE_CLASS');?>
"><?php echo $_smarty_tpl->tpl_vars['gui']->value->page_title;
echo $_smarty_tpl->tpl_vars['tlCfg']->value->gui_title_separator_1;
echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['gui']->value->container_data['name'], ENT_QUOTES, 'UTF-8', true);?>
</h1>
<div class="workBack">
<?php $_smarty_tpl->_subTemplateRender("file:inc_update.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array('result'=>$_smarty_tpl->tpl_vars['gui']->value->sqlResult,'item'=>$_smarty_tpl->tpl_vars['gui']->value->level,'name'=>$_smarty_tpl->tpl_vars['gui']->value->moddedItem['name'],'refresh'=>$_smarty_tpl->tpl_vars['gui']->value->refreshTree,'user_feedback'=>$_smarty_tpl->tpl_vars['gui']->value->user_feedback), 0, false);
?>

<?php if ($_smarty_tpl->tpl_vars['gui']->value->uploadOp != null) {?>
  <?php echo '<script'; ?>
>
  var uplMsg = "<?php echo $_smarty_tpl->tpl_vars['labels']->value['file_upload_ko'];?>
<br>";
  var doAlert = false;
  <?php if ($_smarty_tpl->tpl_vars['gui']->value->uploadOp->statusOK == false) {?>
    uplMsg += "<?php echo $_smarty_tpl->tpl_vars['gui']->value->uploadOp->msg;?>
<br>";
    doAlert = true;
  <?php }?>
  if (doAlert) {
    bootbox.alert(uplMsg);
  }
  <?php echo '</script'; ?>
>
<?php }?>

<?php $_smarty_tpl->_assignInScope('bDownloadOnly', true);
$_smarty_tpl->_assignInScope('drawReorderButton', true);
$_smarty_tpl->_assignInScope('drawReorderButton', false);?>

<?php if ($_smarty_tpl->tpl_vars['gui']->value->level == 'testproject') {?>

  <?php if ($_smarty_tpl->tpl_vars['gui']->value->modify_tc_rights == 'yes') {?>
    <?php $_smarty_tpl->_assignInScope('bDownloadOnly', false);?>
  <i class="fa fa-cog" aria-hidden="true"
     onclick="javascript:toogleShowHide('tproject_control_panel');"
     title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['actions'];?>
">
  </i>
  <div id="tproject_control_panel" style="display:<?php echo $_smarty_tpl->tpl_vars['tlCfg']->value->gui->op_area_display->test_spec_container;?>
;">
    <fieldset class="groupBtn">
    <b><?php echo $_smarty_tpl->tpl_vars['labels']->value['testsuite_operations'];?>
</b>
    <form method="post" action="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
lib/testcases/containerEdit.php">

      <?php if (property_exists($_smarty_tpl->tpl_vars['gui']->value,'calledByMethod')) {?> 
        <input type="hidden" name="calledByMethod" id="calledByMethod" 
               value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->calledByMethod;?>
" />
      <?php }?>

      <input type="hidden" name="treeFormToken" id="treeFormToken" value="<?php echo $_smarty_tpl->tpl_vars['ft']->value;?>
" />

      <input type="hidden" name="treeFormToken" id="treeFormToken" value="<?php echo $_smarty_tpl->tpl_vars['ft']->value;?>
" />
      <input type="hidden" name="doAction" id="doAction" value="" />
      <input type="hidden" name="containerID" 
             value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->container_data['id'];?>
" />
      <input type="hidden" name="containerType" id="containerType" 
             value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->level;?>
" />

      <button type="submit" name="new_testsuite" id="new_testsuite" onclick="doAction.value='new_testsuite'" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_new_testsuite'];?>
"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['add'];?>
</button>
      
      <button type="submit" name="reorder_testproject_testsuites_alpha" id="reorder_testproject_testsuites_alpha" onclick="doAction.value='reorder_testproject_testsuites_alpha'" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_reorder_testsuites_alpha'];?>
"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['order_alpha'];?>
</button>

      <span onclick="location='<?php echo $_smarty_tpl->tpl_vars['importToTProjectAction']->value;?>
'" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_import_testsuite'];?>
"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['import'];?>
</span>

      <?php if ($_smarty_tpl->tpl_vars['gui']->value->canDoExport) {?>
        <span onclick="location='<?php echo $_smarty_tpl->tpl_vars['tsuiteExportAction']->value;?>
'" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_export_all_testsuites'];?>
"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['export'];?>
</span>
      <?php }?>

      <span onclick="window.open('<?php echo $_smarty_tpl->tpl_vars['testSpecFullDocAction']->value;?>
')" 
 title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_gen_test_spec_new_window'];?>
"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['report'];?>
</span>

      <span onclick="window.open('<?php echo $_smarty_tpl->tpl_vars['testSpecFullWordDocAction']->value;?>
')" 
 title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_gen_test_spec_word'];?>
"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['report_word'];?>
</span>

    </form>
    </fieldset>
  </div>
  <?php }?>

  <table class="simple" >
    <tr>
      <th><?php echo $_smarty_tpl->tpl_vars['labels']->value['th_product_name'];?>
</th>
    </tr>
    <tr>
      <td>
      <?php if ($_smarty_tpl->tpl_vars['gui']->value->mgt_modify_product == 'yes') {?>
        <a href="lib/project/projectView.php"  target="mainframe"
                title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['edit_testproject_basic_data'];?>
"><?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['gui']->value->container_data['name'], ENT_QUOTES, 'UTF-8', true);?>
</a>
      <?php } else { ?>
         <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['gui']->value->container_data['name'], ENT_QUOTES, 'UTF-8', true);?>

      <?php }?>
      </td>
    </tr>
    <tr>
      <th><?php echo $_smarty_tpl->tpl_vars['labels']->value['th_notes'];?>
</th>
    </tr>
    <tr>
      <td><?php if ($_smarty_tpl->tpl_vars['gui']->value->testProjectEditorType == 'none') {
echo nl2br((string) $_smarty_tpl->tpl_vars['gui']->value->container_data['notes'], (bool) 1);
} else {
echo $_smarty_tpl->tpl_vars['gui']->value->container_data['notes'];
}?></td>
    </tr>
  </table>

<?php } elseif ($_smarty_tpl->tpl_vars['gui']->value->level == 'testsuite') {?>

  <?php if ($_smarty_tpl->tpl_vars['gui']->value->modify_tc_rights == 'yes' || $_smarty_tpl->tpl_vars['gui']->value->sqlResult != '') {?>
     <?php $_smarty_tpl->_subTemplateRender("file:containerViewTestSuiteTextButtons.inc.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?> 
  <?php }?>
  
    <?php $_smarty_tpl->_subTemplateRender("file:tsuiteViewerRO.inc.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>

  <?php if ($_smarty_tpl->tpl_vars['gui']->value->modify_tc_rights == 'yes') {?>
    <?php $_smarty_tpl->_assignInScope('bDownloadOnly', false);?>
  <?php }
}?> 
<?php $_smarty_tpl->_subTemplateRender("file:attachments.inc.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array('attach_attachmentInfos'=>$_smarty_tpl->tpl_vars['gui']->value->attachmentInfos,'attach_id'=>$_smarty_tpl->tpl_vars['gui']->value->id,'attach_tableName'=>"nodes_hierarchy",'attach_downloadOnly'=>$_smarty_tpl->tpl_vars['bDownloadOnly']->value), 0, false);
?>

</div>
<?php if ($_smarty_tpl->tpl_vars['gui']->value->refreshTree) {?>
  <?php $_smarty_tpl->_subTemplateRender("file:inc_refreshTreeWithFilters.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
}?>
</body>
</html><?php }
}
