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
    /* #sidebar's height:100% (below) only resolves against a parent with
       a definite height. body has one, but <aside> - the element actually
       between body and #sidebar - didn't, so the percentage fell back to
       auto and #sidebar collapsed to its content's height instead of
       filling the frame. */
    aside {ldelim}
      display: block;
      height: 100%;
    {rdelim}
    #sidebar {ldelim}
      position: static;
      width: 100%;
      height: 100%;
    {rdelim}

    /* Collapsed to an icon rail. The frame itself is narrowed by the
       frameset (iframe.navigationAside.railed), this only strips the menu
       back to its icons. Submenus are hidden rather than turned into
       flyouts: a flyout would be clipped at the frame boundary. */
    body.rail .sidebar-menu > li > a > span,
    body.rail .sidebar-menu > li > a .arrow {ldelim}
      display: none;
    {rdelim}
    body.rail .sidebar-menu ul.sub {ldelim}
      display: none !important;
    {rdelim}
    body.rail .sidebar-menu > li > a {ldelim}
      text-align: center;
      padding: 12px 0;
    {rdelim}
    body.rail .sidebar-menu > li > a i {ldelim}
      margin: 0;
      font-size: 18px;
    {rdelim}
  </style>

  <script type="text/javascript"
          src="{$basehref}{$smarty.const.TL_JQUERY}"
          language="javascript"></script>
</head>
<body{if $railed} class="rail"{/if}>

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
/* Collapsing has to narrow the frame as well, otherwise the menu shrinks but
   its 210px strip stays behind and the content area gains nothing. The frame
   lives in the frameset, so both sides are set together here and the title
   bar's burger drives it through tlSetRail(). */
function tlIsRailed() {ldelim}
  return document.body.className.indexOf('rail') != -1;
{rdelim}

function tlSetRail(on) {ldelim}
  document.body.className = on ? 'rail' : '';

  var frameEl = window.parent.document.getElementById('asidebar');
  if (frameEl) {ldelim}
    frameEl.className = on ? 'navigationAside railed' : 'navigationAside';
  {rdelim}

  /* Remembered so a test project switch, which rebuilds the whole frameset,
     comes back the way it was left. Read server side by menuRailIsOn(). */
  document.cookie = '{$menuRailCookie}=' + (on ? '1' : '0') +
                    ';path=/;max-age=' + (60 * 60 * 24 * 30);
{rdelim}

$(function() {ldelim}
  /* While railed the submenus are hidden, so letting the accordion run would
     make these clicks do nothing. Expand instead, and keep the accordion's
     own handler from firing. */
  $('#sidebar ul.sidebar-menu > li.sub-menu > a').on('click',function(ev) {ldelim}
    if (tlIsRailed()) {ldelim}
      ev.preventDefault();
      ev.stopImmediatePropagation();
      tlSetRail(false);
    {rdelim}
  {rdelim});

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

  /* Long labels (e.g. "Requirement Specification Document") are truncated with
     an ellipsis - see testlink.css. Give each its untruncated text as a native
     title (keyboard/no-JS fallback) and, for the ones that actually overflow,
     wrap the text so hovering can slide it left far enough to read in full -
     see the ".menu-label" rules in testlink.css. */
  $('#sidebar ul.sidebar-menu ul.sub li a').each(function() {ldelim}
    var $link = $(this);
    var text = $link.text().trim();
    if (!this.title) {ldelim}
      this.title = text;
    {rdelim}
    $link.html('<span class="menu-label">' + $('<div>').text(text).html() + '</span>');
  {rdelim});

  /* Every ul.sub starts display:none (the accordion only opens one section at a
     time), so measuring widths in the loop above would see 0 for everything.
     Force each closed one visible just long enough to measure its children,
     then put it back - synchronous, so the browser never paints the in-between
     state and nothing actually flashes open. */
  $('#sidebar ul.sidebar-menu ul.sub').each(function() {ldelim}
    var $sub = $(this);
    var wasHidden = $sub.css('display') === 'none';
    if (wasHidden) {ldelim}
      $sub.css('display','block');
    {rdelim}

    $sub.find('> li > a').each(function() {ldelim}
      var $label = $(this).children('.menu-label');
      var overflow = $label[0].scrollWidth - this.clientWidth;
      if (overflow > 0) {ldelim}
        $label[0].style.setProperty('--menu-label-shift', (-overflow - 4) + 'px');
        $label.addClass('menu-label-overflow');
      {rdelim}
    {rdelim});

    if (wasHidden) {ldelim}
      $sub.css('display','');
    {rdelim}
  {rdelim});
{rdelim});
</script>
</body>
</html>
