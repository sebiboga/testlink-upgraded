<?php
/* Smarty version 4.5.7, created on 2026-08-17 07:04:00
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\include\inc_tree_control.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82b260da8f84_64328300',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'bd52a4ecc963b5edae1aeb9ab5ba7740e98f6934' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\include\\inc_tree_control.tpl',
      1 => 1786862612,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6a82b260da8f84_64328300 (Smarty_Internal_Template $_smarty_tpl) {
echo call_user_func_array( $_smarty_tpl->smarty->registered_plugins[Smarty::PLUGIN_FUNCTION]['lang_get'][0], array( array('var'=>'labels','s'=>'expand_tree, collapse_tree'),$_smarty_tpl ) );?>


<div class="x-panel-body exec_additional_info" style="padding:3px; padding-left: 9px;border:1px solid #99BBE8;">

<input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="button"
       value="<?php echo $_smarty_tpl->tpl_vars['labels']->value['expand_tree'];?>
" 
       id="expand_tree" 
       name="expand_tree"
       onclick="tree.expandAll();"
       style="font-size: 90%;" />

<input class="<?php echo $_smarty_tpl->smarty->ext->configLoad->_getConfigVariable($_smarty_tpl, 'BUTTON_CLASS');?>
" type="button"
       value="<?php echo $_smarty_tpl->tpl_vars['labels']->value['collapse_tree'];?>
"
       id="collapse_tree"
       name="collapse_tree"
       onclick="tree.collapseAll();"
       style="font-size: 90%;" />

</div>
<?php }
}
