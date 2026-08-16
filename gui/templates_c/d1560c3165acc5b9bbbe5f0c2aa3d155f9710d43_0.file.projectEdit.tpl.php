<?php
/* Smarty version 4.5.7, created on 2026-08-16 18:50:42
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\project\projectEdit.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82068253ca10_62041948',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'd1560c3165acc5b9bbbe5f0c2aa3d155f9710d43' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\project\\projectEdit.tpl',
      1 => 1786906215,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:inc_head.tpl' => 1,
    'file:inc_del_onclick.tpl' => 1,
    'file:bootstrap.inc.tpl' => 1,
    'file:aside.tpl' => 1,
    'file:supportJS.inc.tpl' => 1,
  ),
),false)) {
function content_6a82068253ca10_62041948 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_checkPlugins(array(0=>array('file'=>'C:\\sebi\\CLAUDE\\testlink-upgraded\\vendor\\smarty\\smarty\\libs\\plugins\\modifier.replace.php','function'=>'smarty_modifier_replace',),));
$_smarty_tpl->_assignInScope('cfg_section', smarty_modifier_replace(call_user_func_array($_smarty_tpl->registered_plugins[ 'modifier' ][ 'basename' ][ 0 ], array( basename($_smarty_tpl->source->filepath) )),".tpl",''));
$_smarty_tpl->smarty->ext->configLoad->_loadConfigFile($_smarty_tpl, "input_dimensions.conf", $_smarty_tpl->tpl_vars['cfg_section']->value, 0);
?>


<?php $_smarty_tpl->_assignInScope('displayListURL', $_smarty_tpl->tpl_vars['gui']->value->actions->displayListURL);
$_smarty_tpl->_assignInScope('managerURL', $_smarty_tpl->tpl_vars['gui']->value->actions->managerURL);
$_smarty_tpl->_assignInScope('editAction', $_smarty_tpl->tpl_vars['gui']->value->actions->editAction);?>

<?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('var'=>"labels",'s'=>'show_event_history,th_active,cancel,info_failed_loc_prod,invalid_query,
  create_from_existent_tproject,opt_no,caption_edit_tproject,caption_new_tproject,name,
  title_testproject_management,testproject_enable_priority, testproject_enable_automation,
  public,testproject_color,testproject_alt_color,testproject_enable_requirements,
  testproject_enable_inventory,testproject_features,testproject_description,
  testproject_prefix,availability,mandatory,warning,warning_empty_tcase_prefix,api_key,
  warning_empty_tproject_name,testproject_issue_tracker_integration,issue_tracker,
  testproject_code_tracker_integration,code_tracker,testproject_reqmgr_integration,reqmgrsystem,
  no_rms_defined,no_issuetracker_defined,no_codetracker_defined,testproject_prefix_hint'),$_smarty_tpl ) );?>


<?php $_smarty_tpl->_subTemplateRender("file:inc_head.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array('openHead'=>"yes",'jsValidate'=>"yes",'editorType'=>$_smarty_tpl->tpl_vars['editorType']->value), 0, false);
$_smarty_tpl->_subTemplateRender("file:inc_del_onclick.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>

<?php echo '<script'; ?>
 type="text/javascript">
var alert_box_title = "<?php echo strtr((string)$_smarty_tpl->tpl_vars['labels']->value['warning'], array("\\" => "\\\\", "'" => "\\'", "\"" => "\\\"", "\r" => "\\r", 
                       "\n" => "\\n", "</" => "<\/", "<!--" => "<\!--", "<s" => "<\s", "<S" => "<\S",
                       "`" => "\\`", "\${" => "\\\$\{"));?>
";
var warning_empty_tcase_prefix = "<?php echo strtr((string)$_smarty_tpl->tpl_vars['labels']->value['warning_empty_tcase_prefix'], array("\\" => "\\\\", "'" => "\\'", "\"" => "\\\"", "\r" => "\\r", 
                       "\n" => "\\n", "</" => "<\/", "<!--" => "<\!--", "<s" => "<\s", "<S" => "<\S",
                       "`" => "\\`", "\${" => "\\\$\{"));?>
";
var warning_empty_tproject_name = "<?php echo strtr((string)$_smarty_tpl->tpl_vars['labels']->value['warning_empty_tproject_name'], array("\\" => "\\\\", "'" => "\\'", "\"" => "\\\"", "\r" => "\\r", 
                       "\n" => "\\n", "</" => "<\/", "<!--" => "<\!--", "<s" => "<\s", "<S" => "<\S",
                       "`" => "\\`", "\${" => "\\\$\{"));?>
";

function validateForm(f)
{
  if (isWhitespace(f.tprojectName.value))
  {
     alert_message(alert_box_title,warning_empty_tproject_name);
     selectField(f, 'tprojectName');
     return false;
  }
  if (isWhitespace(f.tcasePrefix.value))
  {
     alert_message(alert_box_title,warning_empty_tcase_prefix);
     selectField(f, 'tcasePrefix');
     return false;
  }

  return true;
}

/**
 *
 *
 */
function manageTracker(selectOID,targetOID)
{
  var so;
  var to;

  to = document.getElementById(targetOID);
  if ( typeof(to) == 'undefined' || to == null) {
    return;
  }

  so = document.getElementById(selectOID);
  to.disabled = false;
  if (so.selectedIndex == 0){
    to.checked = false;
    to.disabled = true;
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

<body onload="manageTracker('issue_tracker_id','issue_tracker_enabled');
      manageTracker('code_tracker_id','code_tracker_enabled');">

<?php $_smarty_tpl->_subTemplateRender("file:aside.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>
<div id="main-content">
<h1 class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TITLE_CLASS');?>
">
  <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['gui']->value->pageTitle, ENT_QUOTES, 'UTF-8', true);?>
 
  <?php if ($_smarty_tpl->tpl_vars['gui']->value->mgt_view_events == "yes" && $_smarty_tpl->tpl_vars['gui']->value->itemID) {?>
    <img style="margin-left:5px;" class="clickable" src="<?php echo $_smarty_tpl->tpl_vars['tlImages']->value['help'];?>
" 
           onclick="showEventHistoryFor('<?php echo $_smarty_tpl->tpl_vars['gui']->value->itemID;?>
','testprojects')" 
           alt="<?php echo $_smarty_tpl->tpl_vars['labels']->value['show_event_history'];?>
" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['show_event_history'];?>
"/>
  <?php }?>
</h1>

<?php if (property_exists($_smarty_tpl->tpl_vars['gui']->value,'user_feedback') && $_smarty_tpl->tpl_vars['gui']->value->user_feedback != '') {?>  
  <?php echo '<script'; ?>
>
  var userMsg = "<?php echo $_smarty_tpl->tpl_vars['gui']->value->user_feedback;?>
"
  bootbox.alert(userMsg);
  <?php echo '</script'; ?>
>
<?php }?>

<div class="workBack">
  <?php if ($_smarty_tpl->tpl_vars['gui']->value->found == "yes") {?>
    <div style="width:90%; margin: auto;">
    <form name="edit_testproject" id="edit_testproject"
          method="post" action="<?php echo $_smarty_tpl->tpl_vars['managerURL']->value;?>
"
          onSubmit="javascript:return validateForm(this);">

    <?php $_smarty_tpl->_assignInScope('tdStyle', 'style="padding:10px;"');?>      
    <table id="item_view" style="width:100%;outline-style: solid; outline-width: 2px;">

      <?php if ($_smarty_tpl->tpl_vars['gui']->value->itemID == 0) {?>
                <?php if ($_smarty_tpl->tpl_vars['gui']->value->testprojects != '') {?>
          <tr>
            <td <?php echo $_smarty_tpl->tpl_vars['tdStyle']->value;?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['create_from_existent_tproject'];?>
</td>
            <td>
              <select id="copy_from_tproject_id" name="copy_from_tproject_id">
              <option value="0"><?php echo $_smarty_tpl->tpl_vars['labels']->value['opt_no'];?>
</option>
               <?php
$_from = $_smarty_tpl->smarty->ext->_foreach->init($_smarty_tpl, $_smarty_tpl->tpl_vars['gui']->value->testprojects, 'testproject');
$_smarty_tpl->tpl_vars['testproject']->do_else = true;
if ($_from !== null) foreach ($_from as $_smarty_tpl->tpl_vars['testproject']->value) {
$_smarty_tpl->tpl_vars['testproject']->do_else = false;
?>
                 <option value="<?php echo $_smarty_tpl->tpl_vars['testproject']->value['id'];?>
"><?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['testproject']->value['name'], ENT_QUOTES, 'UTF-8', true);?>
</option>
               <?php
}
$_smarty_tpl->smarty->ext->_foreach->restore($_smarty_tpl, 1);?>
              </select>
            </td>
          </tr>
        <?php }?>
      <?php }?>
      <tr>
        <td <?php echo $_smarty_tpl->tpl_vars['tdStyle']->value;?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['name'];?>
 *</td>
        <td><input type="text" id="tprojectName" name="tprojectName" size="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TESTPROJECT_NAME_SIZE');?>
"
            value="<?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['gui']->value->tprojectName, ENT_QUOTES, 'UTF-8', true);?>
" maxlength="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TESTPROJECT_NAME_MAXLEN');?>
" required />
        </td>
      </tr>
      <tr>
        <td <?php echo $_smarty_tpl->tpl_vars['tdStyle']->value;?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_prefix'];?>
 *</td>
        <td><input type="text" id="tcasePrefix" name="tcasePrefix" 
                   size="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TESTCASE_PREFIX_SIZE');?>
"
                   value="<?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['gui']->value->tcasePrefix, ENT_QUOTES, 'UTF-8', true);?>
" 
                   maxlength="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'TESTCASE_PREFIX_MAXLEN');?>
" required />
            <i class="fa fa-info-circle" aria-hidden="true" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_prefix_hint'];?>
"></i>       
        </td>
      </tr>
      <tr>
        <td <?php echo $_smarty_tpl->tpl_vars['tdStyle']->value;?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_description'];?>
</td>
        <td style="width:80%"><?php echo $_smarty_tpl->tpl_vars['gui']->value->notes;?>
</td>
      </tr>
      <tr>
        <td <?php echo $_smarty_tpl->tpl_vars['tdStyle']->value;?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_features'];?>
</td><td></td>
      </tr>
      <tr>
        <td></td><td>
            <input type="checkbox" id="optReq" name="optReq" 
                <?php if ($_smarty_tpl->tpl_vars['gui']->value->projectOptions->requirementsEnabled) {?> checked="checked"  <?php }?> />
          <?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_enable_requirements'];?>

        </td>
      </tr>
      <tr>
        <td></td><td>
          <input type="checkbox" id="optPriority" name="optPriority" 
              <?php if ($_smarty_tpl->tpl_vars['gui']->value->projectOptions->testPriorityEnabled) {?> checked="checked"  <?php }?> />
          <?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_enable_priority'];?>

        </td>
      </tr>
      <tr>
        <td></td><td>
          <input type="checkbox" id="optAutomation" name="optAutomation"
                <?php if ($_smarty_tpl->tpl_vars['gui']->value->projectOptions->automationEnabled) {?> checked="checked" <?php }?> />
          <?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_enable_automation'];?>

        </td>
      </tr>
      <tr>
        <td></td><td>
          <input type="checkbox" id="optInventory" name="optInventory"
                <?php if ($_smarty_tpl->tpl_vars['gui']->value->projectOptions->inventoryEnabled) {?> checked="checked" <?php }?> />
          <?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_enable_inventory'];?>

        </td>
      </tr>
      <tr>
        <td <?php echo $_smarty_tpl->tpl_vars['tdStyle']->value;?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_issue_tracker_integration'];?>
</td><td></td>
      </tr>
      <?php if ($_smarty_tpl->tpl_vars['gui']->value->issueTrackers == '') {?>
        <tr>
          <td></td>
          <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['no_issuetracker_defined'];?>
</td>
        </tr>
      <?php } else { ?>
        <tr>
          <td></td>
          <td>
            <input type="checkbox" id="issue_tracker_enabled"
                   name="issue_tracker_enabled" <?php if ($_smarty_tpl->tpl_vars['gui']->value->issue_tracker_enabled == 1) {?> checked="checked" <?php }?> />
            <?php echo $_smarty_tpl->tpl_vars['labels']->value['th_active'];?>

          </td>
        </tr>
        <tr>
          <td></td>
          <td>
            <?php echo $_smarty_tpl->tpl_vars['labels']->value['issue_tracker'];?>

             <select name="issue_tracker_id" id="issue_tracker_id"
             onchange="manageTracker('issue_tracker_id','issue_tracker_enabled');">
             <option value="0">&nbsp;</option>
             <?php
$_from = $_smarty_tpl->smarty->ext->_foreach->init($_smarty_tpl, $_smarty_tpl->tpl_vars['gui']->value->issueTrackers, 'issue_tracker');
$_smarty_tpl->tpl_vars['issue_tracker']->do_else = true;
if ($_from !== null) foreach ($_from as $_smarty_tpl->tpl_vars['issue_tracker']->value) {
$_smarty_tpl->tpl_vars['issue_tracker']->do_else = false;
?>
               <option value="<?php echo $_smarty_tpl->tpl_vars['issue_tracker']->value['id'];?>
" 
                 <?php if ($_smarty_tpl->tpl_vars['issue_tracker']->value['id'] == $_smarty_tpl->tpl_vars['gui']->value->issue_tracker_id) {?> selected <?php }?> 
               >
               <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['issue_tracker']->value['verbose'], ENT_QUOTES, 'UTF-8', true);?>
</option>
             <?php
}
$_smarty_tpl->smarty->ext->_foreach->restore($_smarty_tpl, 1);?>
             </select>
          </td>
        </tr>
      <?php }?>

      <tr>
        <td <?php echo $_smarty_tpl->tpl_vars['tdStyle']->value;?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['testproject_code_tracker_integration'];?>
</td><td></td>
      </tr>
      <?php if ($_smarty_tpl->tpl_vars['gui']->value->codeTrackers == '') {?>
        <tr>
          <td></td>
          <td><?php echo $_smarty_tpl->tpl_vars['labels']->value['no_codetracker_defined'];?>
</td>
        </tr>
      <?php } else { ?>
        <tr>
          <td></td>
          <td>
            <input type="checkbox" id="code_tracker_enabled"
                   name="code_tracker_enabled" <?php if ($_smarty_tpl->tpl_vars['gui']->value->code_tracker_enabled == 1) {?> checked="checked" <?php }?> />
            <?php echo $_smarty_tpl->tpl_vars['labels']->value['th_active'];?>

          </td>
        </tr>
        <tr>
          <td></td>
          <td>
            <?php echo $_smarty_tpl->tpl_vars['labels']->value['code_tracker'];?>

             <select name="code_tracker_id" id="code_tracker_id"
             onchange="manageTracker('code_tracker_id','code_tracker_enabled');">
             <option value="0">&nbsp;</option>
             <?php
$_from = $_smarty_tpl->smarty->ext->_foreach->init($_smarty_tpl, $_smarty_tpl->tpl_vars['gui']->value->codeTrackers, 'code_tracker');
$_smarty_tpl->tpl_vars['code_tracker']->do_else = true;
if ($_from !== null) foreach ($_from as $_smarty_tpl->tpl_vars['code_tracker']->value) {
$_smarty_tpl->tpl_vars['code_tracker']->do_else = false;
?>
               <option value="<?php echo $_smarty_tpl->tpl_vars['code_tracker']->value['id'];?>
" 
                 <?php if ($_smarty_tpl->tpl_vars['code_tracker']->value['id'] == $_smarty_tpl->tpl_vars['gui']->value->code_tracker_id) {?> selected <?php }?> 
               >
               <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['code_tracker']->value['verbose'], ENT_QUOTES, 'UTF-8', true);?>
</option>
             <?php
}
$_smarty_tpl->smarty->ext->_foreach->restore($_smarty_tpl, 1);?>
             </select>
          </td>
        </tr>
      <?php }?>
         
      <tr>
        <td <?php echo $_smarty_tpl->tpl_vars['tdStyle']->value;?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['availability'];?>
</td><td></td>
      </tr>
      <tr>
        <td></td><td>
            <input type="checkbox" id="active" name="active" <?php if ($_smarty_tpl->tpl_vars['gui']->value->active == 1) {?> checked="checked" <?php }?> />
            <?php echo $_smarty_tpl->tpl_vars['labels']->value['th_active'];?>

          </td>
          </tr>

      <tr>
        <td></td><td>
            <input type="checkbox" id="is_public" name="is_public" <?php if ($_smarty_tpl->tpl_vars['gui']->value->is_public == 1) {?> checked="checked"  <?php }?> />
            <?php echo $_smarty_tpl->tpl_vars['labels']->value['public'];?>

          </td>
      </tr>
      
      <?php if ($_smarty_tpl->tpl_vars['gui']->value->api_key != '') {?>
      <tr>
        <td <?php echo $_smarty_tpl->tpl_vars['tdStyle']->value;?>
><?php echo $_smarty_tpl->tpl_vars['labels']->value['api_key'];?>
</td>
        <td><?php echo $_smarty_tpl->tpl_vars['gui']->value->api_key;?>
</td>
      </tr>
      <?php }?>


      <tr><td cols="2">&nbsp;</td></tr>

      <tr><td cols="2" <?php echo $_smarty_tpl->tpl_vars['tdStyle']->value;?>
>
        <?php if ($_smarty_tpl->tpl_vars['gui']->value->canManage == "yes") {?>
        <div class="groupBtn">
          <input type="hidden" name="doAction" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->doActionValue;?>
" />
          <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="submit"
                 name="doActionButton" id="doActionButton"
                 value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->buttonValue;?>
" />
                 
          <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="button" 
                 name="go_back" id="go_back"
                 value="<?php echo $_smarty_tpl->tpl_vars['labels']->value['cancel'];?>
" 
                 onclick="javascript: location.href=fRoot+'<?php echo $_smarty_tpl->tpl_vars['displayListURL']->value;?>
';" />

          <input type="hidden" name="itemID" id="itemID" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->itemID;?>
">
          <input type="hidden" name="tproject_id" id="tproject_id" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
">
          <input type="hidden" name="tplan_id" id="tplan_id" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->tplan_id;?>
">

        </div>
        <?php }?>
      </td></tr>
    </table>
    </form>
  </div>
  <?php } else { ?>
    <p class="info">
    <<<projectEdit.tpl>>>
    <?php if ($_smarty_tpl->tpl_vars['gui']->value->tprojectName != '') {?>
      <?php echo $_smarty_tpl->tpl_vars['labels']->value['info_failed_loc_prod'];?>
 - <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['gui']->value->tprojectName, ENT_QUOTES, 'UTF-8', true);?>
!<br />
    <?php }?>
    <?php echo $_smarty_tpl->tpl_vars['labels']->value['invalid_query'];?>
: <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['sqlResult']->value, ENT_QUOTES, 'UTF-8', true);?>
</p>
  <?php }?>
</div>
</div>
<?php $_smarty_tpl->_subTemplateRender("file:supportJS.inc.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>
</body>
</html>
<?php }
}
