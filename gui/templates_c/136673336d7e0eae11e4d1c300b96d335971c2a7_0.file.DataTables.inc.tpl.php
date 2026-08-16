<?php
/* Smarty version 4.5.7, created on 2026-08-16 18:51:01
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\include\DataTables.inc.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a82069500f501_84048936',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '136673336d7e0eae11e4d1c300b96d335971c2a7' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\include\\DataTables.inc.tpl',
      1 => 1786874983,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6a82069500f501_84048936 (Smarty_Internal_Template $_smarty_tpl) {
?>


<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/v/bs/jszip-2.5.0/dt-1.12.1/af-2.4.0/b-2.2.3/b-colvis-2.2.3/b-html5-2.2.3/b-print-2.2.3/cr-1.5.6/fc-4.1.0/fh-3.2.4/kt-2.7.0/r-2.3.0/rg-1.2.0/rr-1.2.8/sc-2.0.7/sl-1.4.0/datatables.min.css"/>
<?php echo '<script'; ?>
 type="text/javascript" src="https://cdn.datatables.net/v/bs/jszip-2.5.0/dt-1.12.1/af-2.4.0/b-2.2.3/b-colvis-2.2.3/b-html5-2.2.3/b-print-2.2.3/cr-1.5.6/fc-4.1.0/fh-3.2.4/kt-2.7.0/r-2.3.0/rg-1.2.0/rr-1.2.8/sc-2.0.7/sl-1.4.0/datatables.min.js"><?php echo '</script'; ?>
>

<?php echo '<script'; ?>
 type="text/javascript" 
  src="<?php echo $_smarty_tpl->tpl_vars['basehref']->value;?>
third_party/DataTables.mjhasbach/dataTables.conditionalPaging.js"><?php echo '</script'; ?>
>


<?php if ($_smarty_tpl->tpl_vars['DataTablesSelector']->value != '') {?>
  
  <?php echo '<script'; ?>
 type="text/javascript" language="javascript" class="init">
  $(document).ready(function() {

    config = { 
      "lengthMenu": [ <?php echo $_smarty_tpl->tpl_vars['DataTablesLengthMenu']->value;?>
 ],
      "stateSave": true, 
      "conditionalPaging": true
    };

    if (addToDataTablesConfig != undefined) {
      config = { ...config,...addToDataTablesConfig };
    }

    $('<?php echo $_smarty_tpl->tpl_vars['DataTablesSelector']->value;?>
').DataTable(config);
  } );
  <?php echo '</script'; ?>
>
<?php }?>  <?php }
}
