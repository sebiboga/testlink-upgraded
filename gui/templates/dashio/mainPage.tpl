{*
TestLink Open Source Project - http://testlink.sourceforge.net/
@filesource mainPage.tpl
*}
{$cfg_section=$smarty.template|basename|replace:".tpl":"" }
{config_load file="input_dimensions.conf" section=$cfg_section}
{lang_get
  var='labels'
  s='testplan,test_status_passed,test_status_failed,test_status_blocked,
     test_status_not_run,th_tc_total,th_completed,no_records_found,
     tc_monthly_creation_rate_on_tproj,tc_monthly_creation_rate_on_tproj_hint'}


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="">

  <!-- Favicons -->
  <link href="{$dashioHomeURL}img/favicon.png" rel="icon">
  <link href="{$dashioHomeURL}img/apple-touch-icon.png" rel="apple-touch-icon">

  <link href="{$dashioHomeURL}lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="{$fontawesomeHomeURL}/css/all.css" rel="stylesheet" />      

  <link href="{$dashioHomeURL}css/style.css" rel="stylesheet">
  <link href="{$dashioHomeURL}css/style-responsive.css" rel="stylesheet">
  <link rel="stylesheet" type="text/css" href="{$basehref}gui/themes/default/css/frame.css">
  {* This template carries its own <head> instead of including inc_head.tpl,
     so the TestLink theme stylesheet has to be pulled in explicitly. Without
     it none of the theme overrides apply to the dashboard or its sidebar. *}
  <link rel="stylesheet" type="text/css" href="{$css|replace:'default':'dashio'}?v={$tlVersion|escape:'url'}">
</head>
<body>
    <section id="main-content">
      <section class="wrapper">
        <div class="row">
          <div class="col-lg-9 main-chart">
            {if $gui->dashboard == null}
              <p style="color:#7a7a7a; padding: 20px 0;">{$labels.no_records_found|escape}</p>
            {else}
              <div class="border-head">
                <h3 style="border-bottom: 0px; margin-bottom: 0px; padding-bottom: 0px;">
                  {$labels.testplan|escape}: {$gui->dashboard->tplan_name|escape}</h3>
                <h5 style="border-bottom: 1px solid #c9cdd7; color:#7a7a7a;">
                  {$labels.th_completed|escape}: {$gui->dashboard->percentage_completed}%
                  ({$gui->dashboard->executed}/{$gui->dashboard->total})</h5>
                <br>
              </div>

              <div class="row">
                <div class="col-lg-5">
                  <canvas id="execStatusPie" width="260" height="260"></canvas>
                </div>
                <div class="col-lg-7">
                  <table class="table table-bordered">
                    <tbody>
                      {foreach from=$gui->dashboard->slices item=slice}
                        <tr>
                          <td style="width:14px; background: {$slice.color};"></td>
                          <td>{$slice.label|escape}</td>
                          <td style="text-align:right;"><strong>{$slice.qty}</strong></td>
                          <td style="text-align:right; color:#7a7a7a;">{$slice.percentage}%</td>
                        </tr>
                      {/foreach}
                      <tr>
                        <td></td>
                        <td><strong>{$labels.th_tc_total|escape}</strong></td>
                        <td style="text-align:right;"><strong>{$gui->dashboard->total}</strong></td>
                        <td></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            {/if}

            {* Second widget: test-project scoped, so it stands on its own and
               is shown even when the execution pie above has no test plan. *}
            {if $gui->tcGrowth != null}
              <div class="border-head" style="margin-top: 30px;">
                <h3 style="border-bottom: 0px; margin-bottom: 0px; padding-bottom: 0px;">
                  {$labels.tc_monthly_creation_rate_on_tproj|escape}</h3>
                <h5 style="border-bottom: 1px solid #c9cdd7; color:#7a7a7a;">
                  {$labels.tc_monthly_creation_rate_on_tproj_hint|escape}</h5>
                <br>
              </div>
              <canvas id="tcGrowthBar" width="700" height="220"></canvas>
            {/if}
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
        {* The vendor template's own "Copyrights Dashio - All Rights Reserved"
           line claimed this installation for the theme author, which is wrong
           for a GPL TestLink install and is NOT the attribution the template
           licence asks for. Replaced with TestLink's own; the TemplateMag
           credit below is the part the licence actually requires. *}
        <p>
          TestLink {$tlVersion|escape} &middot;
          <a href="http://www.testlink.org/" target="_blank" rel="noopener">testlink.org</a>
        </p>
        <div class="credits" style="font-size: 11px; opacity: 0.7;">
          <!--
            You are NOT allowed to delete the credit link to TemplateMag with free version.
            You can delete the credit link only if you bought the pro version.
            Buy the pro version with working PHP/AJAX contact form: https://templatemag.com/dashio-bootstrap-admin-template/
            Licensing information: https://templatemag.com/license/
          -->
          Created with Dashio template by <a href="https://templatemag.com/" target="_blank" rel="noopener">TemplateMag</a>
        </div>
        <a href="index.html#" class="go-top">
          <i class="fa fa-angle-up"></i>
          </a>
      </div>
    </footer>
    <!--footer end-->


  <!-- js placed at the end of the document so the pages load faster -->
  <script type="text/javascript" 
          src="{$basehref}{$smarty.const.TL_JQUERY}" 
          language="javascript"></script>

  <script src="{$dashioHomeURL}lib/bootstrap/js/bootstrap.min.js"></script>
  <script class="include" type="text/javascript" src="{$dashioHomeURL}lib/jquery.dcjqaccordion.2.7.js"></script>

  <script src="{$dashioHomeURL}lib/jquery.scrollTo.min.js"></script>

  <script src="{$dashioHomeURL}lib/jquery.nicescroll.js" type="text/javascript"></script>  

  <!--common script for all pages-->
  <script src="{$dashioHomeURL}lib/left-bar-scripts.js"></script>

  <!--script for this page-->
  {* Loaded once for whichever dashboard widgets are on the page. *}
  {if $gui->dashboard != null || $gui->tcGrowth != null}
    <script src="{$dashioHomeURL}lib/chart-master/Chart.js"></script>
  {/if}

  {if $gui->dashboard != null}
    <script type="text/javascript">
    {* Chart.js shipped with dashio is v1: .Doughnut() taking {ldelim}value,color{rdelim}. *}
    {* Zero-valued statuses are kept in the array - Chart.js just draws no
       segment for them - so the separators stay trivially correct. *}
    new Chart(document.getElementById("execStatusPie").getContext("2d")).Doughnut([
      {foreach from=$gui->dashboard->slices item=slice name=sl}
        {ldelim}value: {$slice.qty},
         color: "{$slice.color}",
         label: "{$slice.label|escape:'javascript'}"{rdelim}{if !$smarty.foreach.sl.last},{/if}
      {/foreach}
    ], {ldelim}segmentStrokeWidth: 1, percentageInnerCutout: 45{rdelim});
    </script>
  {/if}

  {if $gui->tcGrowth != null}
    <script type="text/javascript">
    {* v1 .Bar() wants parallel labels[] / datasets[].data[] arrays. *}
    new Chart(document.getElementById("tcGrowthBar").getContext("2d")).Bar({ldelim}
      labels: [{foreach from=$gui->tcGrowth->labels item=lb name=lx}"{$lb|escape:'javascript'}"{if !$smarty.foreach.lx.last},{/if}{/foreach}],
      datasets: [{ldelim}
        fillColor: "rgba(78,205,196,0.55)",
        strokeColor: "rgba(78,205,196,1)",
        data: [{foreach from=$gui->tcGrowth->values item=qty name=vx}{$qty}{if !$smarty.foreach.vx.last},{/if}{/foreach}]
      {rdelim}]
    {rdelim}, {ldelim}scaleBeginAtZero: true, barValueSpacing: 6{rdelim});
    </script>
  {/if}
</body>
</html>