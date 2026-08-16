{*
displayReqCoverageRO.inc.tpl
*}  
    <fieldset class="x-fieldset x-form-label-left">
       <legend class="legend_container">{$labels.coverage}</legend>
 
    {section name=rowCov loop=$argsReqCoverage}
        {$reqCovItem = $argsReqCoverage[rowCov]}
        <span>
        &nbsp;&nbsp; 
        {if $reqCovItem.is_obsolete ==1}
        <span class="clickable" title="{$labels.obsolete}">{$tlImages.heads_up}</span>
        {else}
          &nbsp;&nbsp;&nbsp; 
        {/if}
        <span class="clickable" onclick="javascript:openExecHistoryWindow({$reqCovItem.id});" title="{$labels.execution_history}">{$tlImages.history_small}</span>
        <span class="clickable" onclick="javascript:openTCaseWindow({$reqCovItem.id});" title="{$labels.design}">{$tlImages.edit_icon}</span>
        {$args_gui->tcasePrefix|escape}{$args_gui->glueChar}
        {$reqCovItem.tc_external_id}{$args_gui->pieceSep}
        {$reqCovItem.tcase_name|escape} [{$labels.version} 
        {$reqCovItem.version}]
        </span><br />
      {/section}
    </fieldset>