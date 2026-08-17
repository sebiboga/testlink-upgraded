<?php
/* Smarty version 4.5.7, created on 2026-08-17 07:04:01
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\include\attachments.inc.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82b261169476_78638766',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '045683231014a2e1674c09be270dced7d2965274' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\include\\attachments.inc.tpl',
      1 => 1786909401,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:inc_del_onclick.tpl' => 1,
  ),
),false)) {
function content_6a82b261169476_78638766 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_checkPlugins(array(0=>array('file'=>'C:\\sebi\\CLAUDE\\testlink-upgraded\\vendor\\smarty\\smarty\\libs\\plugins\\modifier.replace.php','function'=>'smarty_modifier_replace',),));
$_smarty_tpl->_assignInScope('cfg_section', smarty_modifier_replace(call_user_func_array($_smarty_tpl->registered_plugins[ 'modifier' ][ 'basename' ][ 0 ], array( basename($_smarty_tpl->source->filepath) )),".tpl",''));
$_smarty_tpl->smarty->ext->configLoad->_loadConfigFile($_smarty_tpl, "input_dimensions.conf", $_smarty_tpl->tpl_vars['cfg_section']->value, 0);
?>


<?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('var'=>'labels','s'=>'title_upload_attachment,enter_attachment_title,
             btn_upload_file,warning,attachment_title,alt_delete_attachment,click_to_get_attachment,
             display_inline,local_file,attachment_upload_ok,title_choose_local_file,btn_cancel,display_ea_string,
             max_size_file_upload,display_inline_string,
             allowed_files,allowed_filenames_regexp'),$_smarty_tpl ) );?>


<?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('s'=>'warning_delete_attachment','var'=>"warning_msg"),$_smarty_tpl ) );?>

<?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('s'=>'delete','var'=>"del_msgbox_title"),$_smarty_tpl ) );?>


<?php echo '<script'; ?>
 type="text/javascript">
function checkFileSize() {
  if (typeof FileReader !== "undefined") {
    var bytes = document.getElementById('uploadedFile').files[0].size;
    if( bytes > <?php echo $_smarty_tpl->tpl_vars['gui']->value->import_limit;?>
 ) {
      var msg = "<?php echo $_smarty_tpl->tpl_vars['labels']->value['max_size_file_upload'];?>
: <?php echo $_smarty_tpl->tpl_vars['gui']->value->import_limit;?>
 Bytes < " + bytes + ' Bytes';
      alert(msg);
      return false;
    }   
  }
  return true;
}  


var warning_delete_attachment = "<?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('s'=>'warning_delete_attachment'),$_smarty_tpl ) );?>
";
<?php if ((isset($_smarty_tpl->tpl_vars['attach_loadOnCancelURL']->value))) {?>
  var attachment_reloadOnCancelURL = '<?php echo $_smarty_tpl->tpl_vars['attach_loadOnCancelURL']->value;?>
';
<?php }?> 
<?php echo '</script'; ?>
>

<?php if ($_smarty_tpl->tpl_vars['gsmarty_attachments']->value->enabled == FALSE) {?>
  <div class="messages"><?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('s'=>'attachment_feature_disabled'),$_smarty_tpl ) );?>
<p>
  <?php echo $_smarty_tpl->tpl_vars['gsmarty_attachments']->value->disabled_msg;?>

  </div>
<?php }
$_smarty_tpl->_subTemplateRender("file:inc_del_onclick.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>

<?php if ($_smarty_tpl->tpl_vars['gsmarty_attachments']->value->enabled && ($_smarty_tpl->tpl_vars['attach_attachmentInfos']->value != '' || $_smarty_tpl->tpl_vars['attach_show_upload_btn']->value)) {?>

  <?php $_smarty_tpl->_assignInScope('displayGhost', 0);?>
  <?php if (!(isset($_smarty_tpl->tpl_vars['gui']->value->showImgInlineString)) || $_smarty_tpl->tpl_vars['gui']->value->showImgInlineString == true) {?>
    <?php $_smarty_tpl->_assignInScope('displayGhost', 1);?>
  <?php }?>
    

  <table class="<?php echo $_smarty_tpl->tpl_vars['attach_tableClassName']->value;?>
" <?php if ($_smarty_tpl->tpl_vars['attach_inheritStyle']->value == 0) {?> style="<?php echo $_smarty_tpl->tpl_vars['attach_tableStyles']->value;?>
" <?php }?>>
    <?php if ($_smarty_tpl->tpl_vars['attach_show_title']->value) {?>
    <tr>
      <td class="bold"><?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('s'=>"attached_files"),$_smarty_tpl ) );
echo $_smarty_tpl->tpl_vars['tlCfg']->value->gui_title_separator_1;?>
</td>
    </tr>
    <?php }?>

    <?php
$_from = $_smarty_tpl->smarty->ext->_foreach->init($_smarty_tpl, $_smarty_tpl->tpl_vars['attach_attachmentInfos']->value, 'info');
$_smarty_tpl->tpl_vars['info']->do_else = true;
if ($_from !== null) foreach ($_from as $_smarty_tpl->tpl_vars['info']->value) {
$_smarty_tpl->tpl_vars['info']->do_else = false;
?>
      <?php if ($_smarty_tpl->tpl_vars['info']->value['title'] == '') {?>
        <?php if ($_smarty_tpl->tpl_vars['gsmarty_attachments']->value->action_on_display_empty_title == 'show_icon') {?>
          <?php $_smarty_tpl->_assignInScope('my_link', $_smarty_tpl->tpl_vars['gsmarty_attachments']->value->access_icon);?>
        <?php } else { ?>
          <?php $_smarty_tpl->_assignInScope('my_link', $_smarty_tpl->tpl_vars['gsmarty_attachments']->value->access_string);?>
      <?php }?>
      <?php } else { ?>
        <?php $_smarty_tpl->_assignInScope('my_link', htmlspecialchars((string)$_smarty_tpl->tpl_vars['info']->value['title'], ENT_QUOTES, 'UTF-8', true));?>
      <?php }?>

        <tr>
        <td style="vertical-align:middle;"><a href="lib/attachments/attachmentdownload.php?id=<?php echo $_smarty_tpl->tpl_vars['info']->value['id'];?>
" target="_blank" class="bold" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['click_to_get_attachment'];?>
">
        <?php echo $_smarty_tpl->tpl_vars['my_link']->value;?>
</a> 
        <?php if ($_smarty_tpl->tpl_vars['info']->value['is_image']) {?> 
          <span style="border:none" title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['display_inline'];?>
" 
 onclick="c4i = document.getElementById('inline_img_container_<?php echo $_smarty_tpl->tpl_vars['info']->value['id'];?>
');
 c4i.innerHTML=toogleImageURL('inline_img_container_<?php echo $_smarty_tpl->tpl_vars['info']->value['id'];?>
',<?php echo $_smarty_tpl->tpl_vars['info']->value['id'];?>
);"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['eye'];?>
</span>
        <?php }?>
        - <span class="italic"><?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['info']->value['file_name'], ENT_QUOTES, 'UTF-8', true);?>
 (<?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['info']->value['file_size'], ENT_QUOTES, 'UTF-8', true);?>
 bytes, <?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['info']->value['file_type'], ENT_QUOTES, 'UTF-8', true);?>
) <?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['localize_date'][0], array( array('d'=>htmlspecialchars((string)$_smarty_tpl->tpl_vars['info']->value['date_added'], ENT_QUOTES, 'UTF-8', true)),$_smarty_tpl ) );?>
</span>
        
          <?php if ($_smarty_tpl->tpl_vars['info']->value['is_image'] && $_smarty_tpl->tpl_vars['displayGhost']->value) {?>
          <span><span title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['display_inline_string'];?>
" style="border:none" onclick="showHideByClass('span','ghost_' + <?php echo $_smarty_tpl->tpl_vars['info']->value['id'];?>
);"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['ghost_item'];?>
</span></span>
          <span class='ghost_<?php echo $_smarty_tpl->tpl_vars['info']->value['id'];?>
' style='display:none'><?php echo $_smarty_tpl->tpl_vars['info']->value['inlineString'];?>
</span>
          <?php }?>
    

        <?php if (!$_smarty_tpl->tpl_vars['attach_downloadOnly']->value) {?>
          <a href="javascript:delete_confirmation(<?php echo $_smarty_tpl->tpl_vars['info']->value['id'];?>
,'<?php echo htmlspecialchars((string)strtr((string)$_smarty_tpl->tpl_vars['info']->value['file_name'], array("\\" => "\\\\", "'" => "\\'", "\"" => "\\\"", "\r" => "\\r", 
                       "\n" => "\\n", "</" => "<\/", "<!--" => "<\!--", "<s" => "<\s", "<S" => "<\S",
                       "`" => "\\`", "\${" => "\\\$\{")), ENT_QUOTES, 'UTF-8', true);?>
',
          '<?php echo htmlspecialchars((string)strtr((string)$_smarty_tpl->tpl_vars['del_msgbox_title']->value, array("\\" => "\\\\", "'" => "\\'", "\"" => "\\\"", "\r" => "\\r", 
                       "\n" => "\\n", "</" => "<\/", "<!--" => "<\!--", "<s" => "<\s", "<S" => "<\S",
                       "`" => "\\`", "\${" => "\\\$\{")), ENT_QUOTES, 'UTF-8', true);?>
','<?php echo htmlspecialchars((string)strtr((string)$_smarty_tpl->tpl_vars['warning_msg']->value, array("\\" => "\\\\", "'" => "\\'", "\"" => "\\\"", "\r" => "\\r", 
                       "\n" => "\\n", "</" => "<\/", "<!--" => "<\!--", "<s" => "<\s", "<S" => "<\S",
                       "`" => "\\`", "\${" => "\\\$\{")), ENT_QUOTES, 'UTF-8', true);?>
',jsCallDeleteFile);">
            <span style="border:none;" 
 title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['alt_delete_attachment'];?>
"
><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['delete'];?>
</span></a>
        <?php }?>
        <?php if ((isset($_smarty_tpl->tpl_vars['gui']->value->showExternalAccessString)) && $_smarty_tpl->tpl_vars['gui']->value->showExternalAccessString) {?>
          <span><span title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['display_ea_string'];?>
" style="border:none" onclick="showHideByClass('span','eas_' + <?php echo $_smarty_tpl->tpl_vars['info']->value['id'];?>
);"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['cog'];?>
</span></span>
          <span class='eas_<?php echo $_smarty_tpl->tpl_vars['info']->value['id'];?>
' style='display:none'>%%EXECATT:<?php echo $_smarty_tpl->tpl_vars['info']->value['id'];?>
%%</span>
        <?php }?>  
        </td>
      </tr>
      <tr><td id="inline_img_container_<?php echo $_smarty_tpl->tpl_vars['info']->value['id'];?>
" style="vertical-align:middle;"></td></tr>      <?php
}
$_smarty_tpl->smarty->ext->_foreach->restore($_smarty_tpl, 1);?>
  </table>

  <?php if ($_smarty_tpl->tpl_vars['attach_show_upload_btn']->value && !$_smarty_tpl->tpl_vars['attach_downloadOnly']->value) {?>

    <?php $_smarty_tpl->_assignInScope('upURL', $_smarty_tpl->tpl_vars['gui']->value->fileUploadURL);?>
    <?php if ((isset($_smarty_tpl->tpl_vars['attach_uploadURL']->value))) {?>
      <?php $_smarty_tpl->_assignInScope('upURL', $_smarty_tpl->tpl_vars['attach_uploadURL']->value);?>
    <?php }?> 
 
    <div  style="text-align:left;margin:3px;background:#CDE;padding: 3px 3px 3px 3px;border-style: groove;border-width: thin;">
      <form action="<?php echo $_smarty_tpl->tpl_vars['upURL']->value;?>
" method="post" enctype="multipart/form-data" 
            id="aForm" onsubmit="javascript:return checkFileSize();">

        <?php if (property_exists($_smarty_tpl->tpl_vars['gui']->value,'tproject_id')) {?> 
          <input type="hidden" name="tproject_id" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->tproject_id;?>
" />         
        <?php }?>
        
        <?php if (property_exists($_smarty_tpl->tpl_vars['gui']->value,'tplan_id')) {?> 
          <input type="hidden" name="tplan_id" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->tplan_id;?>
" />         
        <?php }?>
        
        <?php if (property_exists($_smarty_tpl->tpl_vars['gui']->value,'itemID')) {?> 
          <input type="hidden" name="itemID" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->itemID;?>
" />         
        <?php }?>

        <?php if (property_exists($_smarty_tpl->tpl_vars['gui']->value,'show_mode')) {?> 
          <input type="hidden" name="show_mode" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->show_mode;?>
" />         
        <?php }?>

        <label for="uploadedFile" class="labelHolder"><?php echo $_smarty_tpl->tpl_vars['labels']->value['local_file'];?>
 </label>
                    <input type="hidden" 
                 name="MAX_FILE_SIZE" value="<?php echo $_smarty_tpl->tpl_vars['gui']->value->import_limit;?>
" /> 
          <input type="file" 
                 name="uploadedFile" id="uploadedFile" 
                 size="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'UPLOAD_FILENAME_SIZE');?>
" />
          <span class="labelHolder"><?php echo $_smarty_tpl->tpl_vars['labels']->value['attachment_title'];?>
:</span>
          <input type="text" id="fileTitle" name="fileTitle" maxlength="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'ATTACHMENT_TITLE_MAXLEN');?>
" 
                 size="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'ATTACHMENT_TITLE_SIZE');?>
" />
          <input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="submit" 
                 name="uploadIt" id="uploadIt"
                 value="<?php echo $_smarty_tpl->tpl_vars['labels']->value['btn_upload_file'];?>
"/>
      </form>
      <span class="clickable" 
 title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['max_size_file_upload'];?>
: <?php echo $_smarty_tpl->tpl_vars['gui']->value->import_limit;?>
 Bytes)"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['activity'];?>
</span>

      <?php if ($_smarty_tpl->tpl_vars['tlCfg']->value->attachments->allowed_filenames_regexp != '') {?>
        <span class="clickable" 
 title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['allowed_filenames_regexp'];
echo $_smarty_tpl->tpl_vars['tlCfg']->value->attachments->allowed_filenames_regexp;?>
"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['activity'];?>
</span>      
      <?php }?>
      <?php if ($_smarty_tpl->tpl_vars['tlCfg']->value->attachments->allowed_files != '') {?>
        <span class="clickable" 
 title="<?php echo $_smarty_tpl->tpl_vars['labels']->value['allowed_files'];
echo $_smarty_tpl->tpl_vars['tlCfg']->value->attachments->allowed_files;?>
"><?php echo $_smarty_tpl->tpl_vars['tlImages']->value['activity'];?>
</span>  
      <?php }?>

      <?php if ($_smarty_tpl->tpl_vars['gui']->value->fileUploadMsg != '') {?>
        <p class="bold" style="color:red"><?php echo $_smarty_tpl->tpl_vars['gui']->value->fileUploadMsg;?>
</p>
      <?php }?>
    </div>
  <?php }?>

<?php }
}
}
