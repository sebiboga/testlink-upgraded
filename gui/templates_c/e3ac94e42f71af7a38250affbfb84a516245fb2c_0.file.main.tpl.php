<?php
/* Smarty version 4.5.7, created on 2026-08-17 07:00:53
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\main.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82b1a502efd8_37261355',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'e3ac94e42f71af7a38250affbfb84a516245fb2c' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\main.tpl',
      1 => 1786904984,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6a82b1a502efd8_37261355 (Smarty_Internal_Template $_smarty_tpl) {
?><!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="">
  <meta name="author" content="Dashboard">
  <meta name="keyword" content="Dashboard, Bootstrap, Admin, Template, Theme, Responsive, Fluid, Retina">
  <title>TestLink based on Dashio Bootstrap Admin Template</title>

  <!-- Favicons -->
  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
img/favicon.png" rel="icon">
  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
img/apple-touch-icon.png"
        rel="apple-touch-icon">

  <!-- Load jQuery early so iframes can use it -->
  <?php echo '<script'; ?>
 type="text/javascript"
          src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo (defined('TL_JQUERY') ? constant('TL_JQUERY') : null);?>
"
          language="javascript"><?php echo '</script'; ?>
>

  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?php echo $_smarty_tpl->tpl_vars['fontawesomeHomeURL']->value;?>
/css/all.css" rel="stylesheet" />


  <link rel="stylesheet" type="text/css" href="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
css/zabuto_calendar.css">
  <link rel="stylesheet" type="text/css" href="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
lib/gritter/css/jquery.gritter.css" />
  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
css/style.css" rel="stylesheet">
  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHome']->value;?>
css/style-responsive.css" rel="stylesheet">
  <link rel="stylesheet" type="text/css" 
        href="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
gui/themes/default/css/frame.css">
</head>

<body class="body-noscroll">
  <section id="container">
   <iframe src="<?php echo $_smarty_tpl->tpl_vars['gui']->value->titleframe;?>
" id="titlebar" name="titlebar" 
           style="display: block;" class="navigationBar">
   </iframe>

   <iframe src="<?php echo $_smarty_tpl->tpl_vars['gui']->value->mainframe;?>
" id="mainframe" name="mainframe" 
           style="display: block;" class="siteContent">
   </iframe>
  </section>

  <?php $_smarty_tpl->_assignInScope('bs', ((string)$_smarty_tpl->tpl_vars['dashioHome']->value)."lib/");?>
  <!--
  js placed at the end of the document so the pages
  load faster (jQuery already loaded in HEAD)
  -->
  <?php echo '<script'; ?>
 src="<?php echo $_smarty_tpl->tpl_vars['bs']->value;?>
bootstrap/js/bootstrap.min.js"><?php echo '</script'; ?>
>
  <?php echo '<script'; ?>
 class="include" type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['bs']->value;?>
jquery.dcjqaccordion.2.7.js"><?php echo '</script'; ?>
>
  <?php echo '<script'; ?>
 src="<?php echo $_smarty_tpl->tpl_vars['bs']->value;?>
jquery.scrollTo.min.js"><?php echo '</script'; ?>
>
  <?php echo '<script'; ?>
 src="<?php echo $_smarty_tpl->tpl_vars['bs']->value;?>
jquery.nicescroll.js" type="text/javascript"><?php echo '</script'; ?>
>
  <!--common script for all pages-->
  <?php echo '<script'; ?>
 src="<?php echo $_smarty_tpl->tpl_vars['bs']->value;?>
common-scripts.js"><?php echo '</script'; ?>
>
  <!--script for this page-->
</body>
</html><?php }
}
