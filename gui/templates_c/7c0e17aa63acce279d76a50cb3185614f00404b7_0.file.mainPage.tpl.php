<?php
/* Smarty version 4.5.7, created on 2026-08-16 18:31:00
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\mainPage.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a8201e4db2513_77753569',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '7c0e17aa63acce279d76a50cb3185614f00404b7' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\mainPage.tpl',
      1 => 1786864906,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
    'file:aside.tpl' => 1,
  ),
),false)) {
function content_6a8201e4db2513_77753569 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_checkPlugins(array(0=>array('file'=>'C:\\sebi\\CLAUDE\\testlink-upgraded\\vendor\\smarty\\smarty\\libs\\plugins\\modifier.replace.php','function'=>'smarty_modifier_replace',),));
$_smarty_tpl->_assignInScope('cfg_section', smarty_modifier_replace(call_user_func_array($_smarty_tpl->registered_plugins[ 'modifier' ][ 'basename' ][ 0 ], array( basename($_smarty_tpl->source->filepath) )),".tpl",''));
$_smarty_tpl->smarty->ext->configLoad->_loadConfigFile($_smarty_tpl, "input_dimensions.conf", $_smarty_tpl->tpl_vars['cfg_section']->value, 0);
?>

<?php echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('var'=>'labels','s'=>'tc_monthly_creation_rate_on_tproj,
     tc_monthly_creation_rate_on_tproj_hint'),$_smarty_tpl ) );?>



<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="">

  <!-- Favicons -->
  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHomeURL']->value;?>
img/favicon.png" rel="icon">
  <link href="<?php echo $_smarty_tpl->tpl_vars['dashioHomeURL']->value;?>
img/apple-touch-icon.png" rel="apple-touch-icon">

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
</head>
<body>
<?php $_smarty_tpl->_subTemplateRender("file:aside.tpl", $_smarty_tpl->cache_id, $_smarty_tpl->compile_id, 0, $_smarty_tpl->cache_lifetime, array(), 0, false);
?>
    <section id="main-content">
      <section class="wrapper">
        <div class="row">
          <div class="col-lg-9 main-chart">
            <!--CUSTOM CHART START -->
            <div class="border-head">
              <h3 style="border-bottom: 0px; 
                         margin-bottom: 0px;
                         padding-bottom: 0px;"><?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['gui']->value->tc_monthly_creation_rate_on_tproj, ENT_QUOTES, 'UTF-8', true);?>
</h3>
              <h5 style="border-bottom: 1px solid #c9cdd7;"><?php echo htmlspecialchars((string)$_smarty_tpl->tpl_vars['labels']->value['tc_monthly_creation_rate_on_tproj_hint'], ENT_QUOTES, 'UTF-8', true);?>
</h5>
              <br>
            </div>
            <div class="custom-bar-chart">
              <?php echo $_smarty_tpl->tpl_vars['gui']->value->dashboard->yAxis;?>

              <?php echo $_smarty_tpl->tpl_vars['gui']->value->dashboard->chart;?>

            </div>
            <!--custom chart end-->
          </div>
          <!-- /col-lg-9 END SECTION MIDDLE -->
        </div>
        <!-- /row -->
      </section>
    </section>
    <!--main content end-->
    <!--footer start-->
    <footer class="site-footer">
      <div class="text-center">
        <p>
          &copy; Copyrights <strong>Dashio</strong>. All Rights Reserved
        </p>
        <div class="credits">
          <!--
            You are NOT allowed to delete the credit link to TemplateMag with free version.
            You can delete the credit link only if you bought the pro version.
            Buy the pro version with working PHP/AJAX contact form: https://templatemag.com/dashio-bootstrap-admin-template/
            Licensing information: https://templatemag.com/license/
          -->
          Created with Dashio template by <a href="https://templatemag.com/">TemplateMag</a>
        </div>
        <a href="index.html#" class="go-top">
          <i class="fa fa-angle-up"></i>
          </a>
      </div>
    </footer>
    <!--footer end-->


  <!-- js placed at the end of the document so the pages load faster -->
  <?php echo '<script'; ?>
 type="text/javascript" 
          src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo (defined('TL_JQUERY') ? constant('TL_JQUERY') : null);?>
" 
          language="javascript"><?php echo '</script'; ?>
>

  <?php echo '<script'; ?>
 src="<?php echo $_smarty_tpl->tpl_vars['dashioHomeURL']->value;?>
lib/bootstrap/js/bootstrap.min.js"><?php echo '</script'; ?>
>
  <?php echo '<script'; ?>
 class="include" type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['dashioHomeURL']->value;?>
lib/jquery.dcjqaccordion.2.7.js"><?php echo '</script'; ?>
>

  <?php echo '<script'; ?>
 src="<?php echo $_smarty_tpl->tpl_vars['dashioHomeURL']->value;?>
lib/jquery.scrollTo.min.js"><?php echo '</script'; ?>
>

  <?php echo '<script'; ?>
 src="<?php echo $_smarty_tpl->tpl_vars['dashioHomeURL']->value;?>
lib/jquery.nicescroll.js" type="text/javascript"><?php echo '</script'; ?>
>  

  <!--common script for all pages-->
  <?php echo '<script'; ?>
 src="<?php echo $_smarty_tpl->tpl_vars['dashioHomeURL']->value;?>
lib/left-bar-scripts.js"><?php echo '</script'; ?>
>

  <!--script for this page-->
</body>
</html><?php }
}
