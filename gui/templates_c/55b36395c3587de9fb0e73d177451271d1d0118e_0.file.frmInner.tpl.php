<?php
/* Smarty version 4.5.7, created on 2026-08-17 07:04:00
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\frmInner.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82b2607e9555_42516868',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '55b36395c3587de9fb0e73d177451271d1d0118e' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\frmInner.tpl',
      1 => 1786862612,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:bootstrap.inc.tpl' => 1,
  ),
),false)) {
function content_6a82b2607e9555_42516868 (Smarty_Internal_Template $_smarty_tpl) {
?><!DOCTYPE html>
<html>
    <head>
    	<meta http-equiv="Content-Type" content="text/html; charset=<?php echo $_smarty_tpl->tpl_vars['pageCharset']->value;?>
" />
    	<meta http-equiv="Content-language" content="en" />
    	<meta http-equiv="expires" content="-1" />
    	<meta http-equiv="pragma" content="no-cache" />
    	<meta name="generator" content="testlink" />
    	<meta name="author" content="Martin Havlat" />
    	<meta name="copyright" content="GNU" />
    	<meta name="robots" content="NOFOLLOW" />
    	<base href="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
" />
    	<title>TestLink Inner Frame</title>
    	<style media="all" type="text/css">@import "<?php echo $_smarty_tpl->tpl_vars['css']->value;?>
";</style>
    	<link rel="stylesheet" type="text/css" href="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
gui/themes/default/css/frame.css">

    	<?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
third_party/chosen/chosen.jquery.js"><?php echo '</script'; ?>
>

    	<?php $_smarty_tpl->_subTemplateRender("file:bootstrap.inc.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>
    </head>
    <body>
      <iframe src="<?php echo $_smarty_tpl->tpl_vars['treeframe']->value;?>
" name="treeframe" class="treeframe"></iframe>
      <iframe src="<?php echo $_smarty_tpl->tpl_vars['workframe']->value;?>
" name="workframe" class="workframe"></iframe>
    </body>
</html>
<?php }
}
