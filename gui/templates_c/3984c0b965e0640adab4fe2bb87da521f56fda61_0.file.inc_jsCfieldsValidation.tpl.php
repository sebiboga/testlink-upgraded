<?php
/* Smarty version 4.5.7, created on 2026-08-16 18:44:37
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\include\inc_jsCfieldsValidation.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a8205156388e6_56244869',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '3984c0b965e0640adab4fe2bb87da521f56fda61' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\include\\inc_jsCfieldsValidation.tpl',
      1 => 1786862612,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6a8205156388e6_56244869 (Smarty_Internal_Template $_smarty_tpl) {
echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('var'=>"cf_warning_msg",'s'=>"warning_numeric_cf,warning_float_cf,warning_email_cf,warning_text_area_cf"),$_smarty_tpl ) );?>


<?php echo '<script'; ?>
 type="text/javascript" src='gui/javascript/cfield_validation.js'><?php echo '</script'; ?>
>


<?php echo '<script'; ?>
 type="text/javascript">

var cfMessages= new Object;
cfMessages.warning_numeric_cf="<?php echo $_smarty_tpl->tpl_vars['cf_warning_msg']->value['warning_numeric_cf'];?>
";
cfMessages.warning_float_cf="<?php echo $_smarty_tpl->tpl_vars['cf_warning_msg']->value['warning_float_cf'];?>
";
cfMessages.warning_email_cf="<?php echo $_smarty_tpl->tpl_vars['cf_warning_msg']->value['warning_email_cf'];?>
";
cfMessages.warning_text_area_cf="<?php echo $_smarty_tpl->tpl_vars['cf_warning_msg']->value['warning_text_area_cf'];?>
";


var cfChecks = new Object;
cfChecks.email = <?php echo $_smarty_tpl->tpl_vars['tlCfg']->value->validation_cfg->user_email_valid_regex_js;?>
;
cfChecks.textarea_length = <?php echo $_smarty_tpl->tpl_vars['tlCfg']->value->custom_fields->max_length;?>
;

<?php echo '</script'; ?>
>
<?php }
}
