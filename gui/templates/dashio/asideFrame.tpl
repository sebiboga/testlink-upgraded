{*
Testlink Open Source Project - http://testlink.sourceforge.net/

Left side menu as a frame of its own.

The menu is global navigation, like the title bar, so main.tpl gives it a
frame instead of every content page drawing its own copy. Content pages that
used to include aside.tpl no longer do: the menu survives navigation in the
main frame and is built once per test project rather than once per page.

@filesource asideFrame.tpl
*}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <base href="{$basehref}"/>
  <title>TestLink Navigation</title>

  <link href="{$dashioHome}lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="{$fontawesomeHomeURL}/css/all.css" rel="stylesheet" />
  <link href="{$dashioHome}css/style.css" rel="stylesheet">
  <link href="{$dashioHome}css/style-responsive.css" rel="stylesheet">

  {* The TestLink theme stylesheet carries the sidebar overrides - the white
     card behind the logo and the yellow user name, both of which are
     unreadable against the dark sidebar without it. Loaded last so those
     overrides win over the dashio defaults. *}
  <link rel="stylesheet" type="text/css"
        href="{$css|replace:'default':'dashio'}?v={$tlVersion|escape:'url'}">

  <style type="text/css">
    /* The frame is exactly as wide as the menu, so drop the page chrome that
       assumes the menu shares a document with content. */
    html, body {ldelim}
      margin: 0;
      padding: 0;
      height: 100%;
      overflow-x: hidden;
      background: #2f323a;
    {rdelim}
    #sidebar {ldelim}
      position: static;
      width: 100%;
    {rdelim}
  </style>

  <script type="text/javascript"
          src="{$basehref}{$smarty.const.TL_JQUERY}"
          language="javascript"></script>
</head>
<body>

{include file="aside.tpl"}

<script src="{$dashioHome}lib/jquery.dcjqaccordion.2.7.js" type="text/javascript"></script>

{*
  common-scripts.js is deliberately not loaded here. It hides the menu
  whenever the window is 768px or narrower, which is always true now that the
  menu has a 210px frame to itself, and its sidebar toggle reaches into
  window.parent.frames[1] expecting to find the menu in the content frame.
  Only the accordion is wanted, so it is set up directly.
*}
<script type="text/javascript">
$(function() {ldelim}
  $('#nav-accordion').dcAccordion({ldelim}
    eventType: 'click',
    autoClose: true,
    saveState: true,
    disableLink: true,
    speed: 'slow',
    showCount: false,
    autoExpand: true,
    classExpand: 'dcjq-current-parent'
  {rdelim});
{rdelim});
</script>
</body>
</html>
