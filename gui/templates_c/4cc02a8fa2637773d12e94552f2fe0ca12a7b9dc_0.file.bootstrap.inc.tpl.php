<?php
/* Smarty version 4.5.7, created on 2026-08-16 18:44:37
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\include\bootstrap.inc.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82051565c898_37495945',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '4cc02a8fa2637773d12e94552f2fe0ca12a7b9dc' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\include\\bootstrap.inc.tpl',
      1 => 1786862612,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6a82051565c898_37495945 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_assignInScope('bb', ($_smarty_tpl->tpl_vars['basehref']->value).("third_party/bootstrap/3.4.1"));?>
<link rel="stylesheet" href="<?php echo $_smarty_tpl->tpl_vars['bb']->value;?>
/css/bootstrap.min.css" >
<link rel="stylesheet" href="<?php echo $_smarty_tpl->tpl_vars['bb']->value;?>
/css/bootstrap-theme.min.css">
<?php echo '<script'; ?>
 src="<?php echo $_smarty_tpl->tpl_vars['bb']->value;?>
/js/bootstrap.min.js"><?php echo '</script'; ?>
><?php }
}
