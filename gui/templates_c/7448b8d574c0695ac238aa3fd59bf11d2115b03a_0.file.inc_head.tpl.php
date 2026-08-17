<?php
/* Smarty version 4.5.7, created on 2026-08-17 07:00:53
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\include\inc_head.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82b1a5736575_34148704',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '7448b8d574c0695ac238aa3fd59bf11d2115b03a' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\include\\inc_head.tpl',
      1 => 1786946596,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:inc_jsCfieldsValidation.tpl' => 1,
    'file:inc_tinymce_init.tpl' => 1,
  ),
),false)) {
function content_6a82b1a5736575_34148704 (Smarty_Internal_Template $_smarty_tpl) {
?><!DOCTYPE html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=<?php echo $_smarty_tpl->tpl_vars['pageCharset']->value;?>
" />
	<meta http-equiv="Content-language" content="en" />
	<meta http-equiv="expires" content="-1" />
	<meta http-equiv="pragma" content="no-cache" />
	<meta name="author" content="TestLink" />
	<meta name="copyright" content="GNU" />
	<meta name="robots" content="NOFOLLOW" />
	<base href="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
"/>
	<title><?php echo (($tmp = $_smarty_tpl->tpl_vars['pageTitle']->value ?? null)===null||$tmp==='' ? "TestLink" ?? null : $tmp);?>
</title>
	<link rel="icon" href="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo (defined('TL_THEME_IMG_DIR') ? constant('TL_THEME_IMG_DIR') : null);?>
favicon.ico" type="image/x-icon" />

<?php $_smarty_tpl->_assignInScope('css', str_replace('default','dashio',$_smarty_tpl->tpl_vars['css']->value));?>
 
		<style media="all" type="text/css">@import "<?php echo $_smarty_tpl->tpl_vars['css']->value;?>
?v=<?php echo rawurlencode((string)$_smarty_tpl->tpl_vars['tlVersion']->value);?>
";</style>

	<?php if ($_smarty_tpl->tpl_vars['use_custom_css']->value) {?>
	<style media="all" type="text/css">@import "<?php echo $_smarty_tpl->tpl_vars['custom_css']->value;?>
";</style>
	<?php }?>
	
	<?php if ($_smarty_tpl->tpl_vars['testproject_coloring']->value == 'background') {?>
  	<style type="text/css"> body {background: <?php echo $_smarty_tpl->tpl_vars['testprojectColor']->value;?>
;}</style>
	<?php }?>
  
	<style media="print" type="text/css">@import "<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo (defined('TL_PRINT_CSS') ? constant('TL_PRINT_CSS') : null);?>
";</style>

	<?php echo '<script'; ?>
 type="text/javascript"
    src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo (defined('TL_JQUERY') ? constant('TL_JQUERY') : null);?>
" language="javascript"><?php echo '</script'; ?>
>

	<?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
gui/javascript/testlink_library.js" language="javascript"><?php echo '</script'; ?>
>
	<?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
gui/javascript/test_automation.js" language="javascript"><?php echo '</script'; ?>
>

	<?php if ($_smarty_tpl->tpl_vars['jsValidate']->value == "yes") {?>
	<?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
gui/javascript/validate.js" language="javascript"><?php echo '</script'; ?>
>
    <?php $_smarty_tpl->_subTemplateRender("file:inc_jsCfieldsValidation.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>
	<?php }?>

	<?php if ($_smarty_tpl->tpl_vars['editorType']->value == 'tinymce') {?>
    <?php echo '<script'; ?>
 type="text/javascript" language="javascript"
    	src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
third_party/tinymce/jscripts/tiny_mce/tiny_mce.js"><?php echo '</script'; ?>
>
    <?php $_smarty_tpl->_subTemplateRender("file:inc_tinymce_init.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>
	<?php }?>


  <link rel="stylesheet" href="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
third_party/chosen/chosen.css">

	<?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
third_party/chosen/chosen.jquery.js"><?php echo '</script'; ?>
>
 
  <?php $_smarty_tpl->_assignInScope('tproject_id', 0);?>
	<?php $_smarty_tpl->_assignInScope('tplan_id', 0);?>
	<?php if ((isset($_smarty_tpl->tpl_vars['gui']->value)) && property_exists($_smarty_tpl->tpl_vars['gui']->value,'tproject_id')) {?>
	  <?php $_smarty_tpl->_assignInScope('tproject_id', $_smarty_tpl->tpl_vars['gui']->value->tproject_id);?>
	<?php }?>

	<?php if ((isset($_smarty_tpl->tpl_vars['gui']->value)) && property_exists($_smarty_tpl->tpl_vars['gui']->value,'tplan_id')) {?>
	  <?php $_smarty_tpl->_assignInScope('tplan_id', $_smarty_tpl->tpl_vars['gui']->value->tplan_id);?>
	<?php }?>

	<?php echo '<script'; ?>
 type="text/javascript" language="javascript">
	//<!--
  /* inc_head.tpl */
	var fRoot = '<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
';
	var menuUrl = '<?php echo $_smarty_tpl->tpl_vars['menuUrl']->value;?>
';
	var args  = '<?php echo $_smarty_tpl->tpl_vars['args']->value;?>
';
	var additionalArgs  = '<?php echo $_smarty_tpl->tpl_vars['additionalArgs']->value;?>
';
	var printPreferences = '<?php echo $_smarty_tpl->tpl_vars['printPreferences']->value;?>
';
	var tproject_id = '<?php echo $_smarty_tpl->tpl_vars['tproject_id']->value;?>
';
	var tplan_id = '<?php echo $_smarty_tpl->tpl_vars['tplan_id']->value;?>
';
	
	// To solve problem diplaying help
	var SP_html_help_file  = '<?php echo $_smarty_tpl->tpl_vars['SP_html_help_file']->value;?>
';
	
	//attachment related JS-Stuff
	var attachmentDlg_refWindow = null;
	var attachmentDlg_refLocation = null;
	var attachmentDlg_bNoRefresh = false;
	
	// bug management (using logic similar to attachment)
	var bug_dialog = new bug_dialog();

	// for ext js
	var extjsLocation = '<?php echo (defined('TL_EXTJS_RELATIVE_PATH') ? constant('TL_EXTJS_RELATIVE_PATH') : null);?>
';
	
	//-->
	<?php echo '</script'; ?>
> 

  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHomeURL']->value;?>
lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">

  <link href="<?php echo $_smarty_tpl->tpl_vars['fontawesomeHomeURL']->value;?>
/css/all.css" rel="stylesheet" />


  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHomeURL']->value;?>
css/style.css" rel="stylesheet">
  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHomeURL']->value;?>
css/style-responsive.css" rel="stylesheet">
  <link rel="stylesheet" type="text/css" href="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
gui/themes/default/css/frame.css">  

<?php if ($_smarty_tpl->tpl_vars['openHead']->value == "no") {?> </head>
<?php }
}
}
