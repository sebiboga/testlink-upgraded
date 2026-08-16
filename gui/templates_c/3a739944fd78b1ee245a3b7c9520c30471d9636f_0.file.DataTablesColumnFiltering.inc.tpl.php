<?php
/* Smarty version 4.5.7, created on 2026-08-16 18:51:01
  from 'C:\sebi\CLAUDE\testlink-upgraded\gui\templates\dashio\include\DataTablesColumnFiltering.inc.tpl' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.5.7',
  'unifunc' => 'content_6a820695016b36_24933848',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '3a739944fd78b1ee245a3b7c9520c30471d9636f' => 
    array (
      0 => 'C:\\sebi\\CLAUDE\\testlink-upgraded\\gui\\templates\\dashio\\include\\DataTablesColumnFiltering.inc.tpl',
      1 => 1786864906,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6a820695016b36_24933848 (Smarty_Internal_Template $_smarty_tpl) {
echo '<script'; ?>
>
$(document).ready(function() {


    // 20210530 
    // stateSave: true produces weird behaivour when using filter on individual columns
    var pimpedTable = $('<?php echo $_smarty_tpl->tpl_vars['DataTablesSelector']->value;?>
').DataTable( {
        orderCellsTop: true,
        fixedHeader: true,
        lengthMenu: [<?php echo $_smarty_tpl->tpl_vars['DataTablesLengthMenu']->value;?>
],
        stateSave: true,

        // https://datatables.net/reference/option/dom
        "dom": 'lrtip'
    } );

    var state = pimpedTable.state.loaded();

    // Setup - add a text input to each footer cell
    // Clone & append the whole header row
    // clone(false) -> is the solution to avoid sort action when clicking
    $('<?php echo $_smarty_tpl->tpl_vars['DataTablesSelector']->value;?>
 thead tr').clone(false).prop("id","column_filters").appendTo( '<?php echo $_smarty_tpl->tpl_vars['DataTablesSelector']->value;?>
 thead' );
    $('<?php echo $_smarty_tpl->tpl_vars['DataTablesSelector']->value;?>
 thead tr:eq(1) th').each( function (idx) {

        // Remove class from cloned <th>, to remove sort icons!!
         $(this).removeClass(['sorting','sorting_desc','sorting_asc']);

        if (typeof  $(this).data('draw-filter') != 'undefined') {
          var title = '';
          var dst = $(this).data('draw-filter');
          switch (dst) {
            case 'regexp':
              title += "regexp";
            break;

            default:
            break;
          }

          var html = '<input type="text" data-search-type="%dst%" placeholder="Filter %title%" %value% style="color: #000000;" />';
          var value='';
          // --------------------------------------------------------------------------------
          // Restore state
          if (state) {
            var colSearchSavedValue = state.columns[idx].search.search;
            if (colSearchSavedValue) {
              value=' value="' + colSearchSavedValue + '" ';
            }
          }
          // -------------------------------------------------------------------------------- 
          $(this).html(html.replace('%dst%',dst).replace('%title%',title).replace('%value%',value));

          $( 'input', this ).on( 'keyup change', function () {
              var use_regexp = false;
              var use_smartsearch = true;
              if ($(this).data('search-type') == "regexp") {
                use_regexp = true;
                use_smartsearch = false;
              }

              if ( pimpedTable.column(idx).search() !== this.value ) {
                  pimpedTable.column(idx)
                             .search( this.value, use_regexp, use_smartsearch )
                             .draw();
              }
          } );
        } else {
          $(this).html( '' );
        }
    } );
} );
<?php echo '</script'; ?>
>
<?php }
}
