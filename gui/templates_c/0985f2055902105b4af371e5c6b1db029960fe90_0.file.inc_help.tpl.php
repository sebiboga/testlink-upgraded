<?php
/* Smarty version 4.5.7, created on 2026-08-17 07:04:00
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\include\inc_help.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82b260d89a19_21635885',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '0985f2055902105b4af371e5c6b1db029960fe90' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\include\\inc_help.tpl',
      1 => 1786862612,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6a82b260d89a19_21635885 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_checkPlugins(array(0=>array('file'=>'C:\\sebi\\CLAUDE\\testlink-upgraded\\vendor\\smarty\\smarty\\libs\\plugins\\modifier.regex_replace.php','function'=>'smarty_modifier_regex_replace',),1=>array('file'=>'C:\\sebi\\CLAUDE\\testlink-upgraded\\vendor\\smarty\\smarty\\libs\\plugins\\modifier.replace.php','function'=>'smarty_modifier_replace',),));
?>

<?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('s'=>'help','var'=>'img_alt'),$_smarty_tpl ) );?>

<?php $_smarty_tpl->_assignInScope('img_style', (($tmp = $_smarty_tpl->tpl_vars['inc_help_style']->value ?? null)===null||$tmp==='' ? "vertical-align: top;" ?? null : $tmp));
echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('var'=>"help_text_raw",'s'=>$_smarty_tpl->tpl_vars['helptopic']->value),$_smarty_tpl ) );?>

<?php $_smarty_tpl->_assignInScope('help_text', (($tmp = smarty_modifier_replace(smarty_modifier_replace(smarty_modifier_regex_replace($_smarty_tpl->tpl_vars['help_text_raw']->value,"/[\r\t\n]/"," "),"'","&#39;"),"\"","&quot;") ?? null)===null||$tmp==='' ? "Help: Localization/Text is missing." ?? null : $tmp));?>

<?php echo '<script'; ?>
 type="text/javascript">
<!--
	var help_localized_text = "<img style='float: right' " +
		"src='<?php echo (defined('TL_THEME_IMG_DIR') ? constant('TL_THEME_IMG_DIR') : null);?>
/x-icon.gif' " +
		"onclick='javascript: close_help();' /> <?php echo strtr((string)$_smarty_tpl->tpl_vars['help_text']->value, array("\\" => "\\\\", "'" => "\\'", "\"" => "\\\"", "\r" => "\\r", 
                       "\n" => "\\n", "</" => "<\/", "<!--" => "<\!--", "<s" => "<\s", "<S" => "<\S",
                       "`" => "\\`", "\${" => "\\\$\{"));?>
";
//-->
<?php echo '</script'; ?>
>  
<?php if ($_smarty_tpl->tpl_vars['show_help_icon']->value !== false) {?>
<img alt="<?php echo $_smarty_tpl->tpl_vars['img_alt']->value;?>
" style="<?php echo $_smarty_tpl->tpl_vars['img_style']->value;?>
" 
	src="<?php echo (defined('TL_THEME_IMG_DIR') ? constant('TL_THEME_IMG_DIR') : null);?>
/sym_question.gif" 
	onclick='javascript: show_help(help_localized_text);'
/>
<?php }
}
}
