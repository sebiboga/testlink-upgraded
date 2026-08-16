<?php
/* Smarty version 4.5.7, created on 2026-08-16 18:44:37
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\include\inc_ext_js.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a820515653130_82957801',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '0aa03b5d9bcd80378a85755e56ff57d8609cf1a7' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\include\\inc_ext_js.tpl',
      1 => 1786862612,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6a820515653130_82957801 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_assignInScope('ext_lang', "en");
if ($_SESSION['locale'] == "cs_CZ") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "cs");
} elseif ($_SESSION['locale'] == "de_DE") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "de");
} elseif ($_SESSION['locale'] == "en_GB") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "en_GB");
} elseif ($_SESSION['locale'] == "en_US") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "en");
} elseif ($_SESSION['locale'] == "es_AR") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "es");
} elseif ($_SESSION['locale'] == "es_ES") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "es");
} elseif ($_SESSION['locale'] == "fi_FI") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "fi");
} elseif ($_SESSION['locale'] == "fr_FR") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "fr");
} elseif ($_SESSION['locale'] == "id_ID") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "id");
} elseif ($_SESSION['locale'] == "it_IT") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "it");
} elseif ($_SESSION['locale'] == "ja_JP") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "ja");
} elseif ($_SESSION['locale'] == "ko_KR") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "ko");
} elseif ($_SESSION['locale'] == "nl_NL") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "nl");
} elseif ($_SESSION['locale'] == "pl_PL") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "pl");
} elseif ($_SESSION['locale'] == "pt_BR") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "pt_BR");
} elseif ($_SESSION['locale'] == "ru_RU") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "ru");
} elseif ($_SESSION['locale'] == "zh_CN") {?>
  <?php $_smarty_tpl->_assignInScope('ext_lang', "zh_CN");
}?>


<?php if (guard_header_smarty('__FILE__')) {?>

  <?php $_smarty_tpl->_assignInScope(((string)$_smarty_tpl->tpl_vars['css_only']->value), ((string)$_smarty_tpl->tpl_vars['css_only']->value)."|default:0");?>
  <?php $_smarty_tpl->_assignInScope('ext_location', (defined('TL_EXTJS_RELATIVE_PATH') ? constant('TL_EXTJS_RELATIVE_PATH') : null));?>
  <?php if ((isset($_smarty_tpl->tpl_vars['bResetEXTCss']->value)) && $_smarty_tpl->tpl_vars['bResetEXTCss']->value) {?>
  	<link rel="stylesheet" type="text/css" href="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/css/reset-min.css" />
  <?php }?>
  <link rel="stylesheet" type="text/css" href="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/css/ext-all.css" />
  <link rel="stylesheet" type="text/css" href="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/gridfilters/css/GridFilters.css" />
  <link rel="stylesheet" type="text/css" href="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/gridfilters/css/RangeMenu.css" />
  
  <?php if ($_smarty_tpl->tpl_vars['css_only']->value == 0) {?>
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/adapter/ext/ext-base.js" language="javascript"><?php echo '</script'; ?>
>
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ext-all.js" language="javascript"><?php echo '</script'; ?>
>
      
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/Reorderer.js" language="javascript"><?php echo '</script'; ?>
>
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/ToolbarReorderer.js" language="javascript"><?php echo '</script'; ?>
>
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/ToolbarDroppable.js" language="javascript"><?php echo '</script'; ?>
>
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/Exporter-all.js" language="javascript"><?php echo '</script'; ?>
>
      
            <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/gridfilters/menu/RangeMenu.js" language="javascript"><?php echo '</script'; ?>
>
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/gridfilters/menu/ListMenu.js" language="javascript"><?php echo '</script'; ?>
>
      
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/gridfilters/GridFilters.js" language="javascript"><?php echo '</script'; ?>
>
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/gridfilters/filter/Filter.js" language="javascript"><?php echo '</script'; ?>
>
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/gridfilters/filter/StringFilter.js" language="javascript"><?php echo '</script'; ?>
>
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/gridfilters/filter/DateFilter.js" language="javascript"><?php echo '</script'; ?>
>
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/gridfilters/filter/ListFilter.js" language="javascript"><?php echo '</script'; ?>
>
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/gridfilters/filter/NumericFilter.js" language="javascript"><?php echo '</script'; ?>
>
      <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/gridfilters/filter/BooleanFilter.js" language="javascript"><?php echo '</script'; ?>
>
      
      
            <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/src/locale/ext-lang-<?php echo $_smarty_tpl->tpl_vars['ext_lang']->value;?>
.js" language="javascript"><?php echo '</script'; ?>
>
  
            <?php echo '<script'; ?>
 type="text/javascript" src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;
echo $_smarty_tpl->tpl_vars['ext_location']->value;?>
/ux/TableGrid.js" language="javascript"><?php echo '</script'; ?>
>
  <?php }?>

<?php }
}
}
