<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="">
  <meta name="author" content="Dashboard">
  <meta name="keyword" content="Dashboard, Bootstrap, Admin, Template, Theme, Responsive, Fluid, Retina">
  <title>TestLink based on Dashio Bootstrap Admin Template</title>

  <!-- Favicons -->
  <link href="{$dashioHome}img/favicon.png" rel="icon">
  <link href="{$dashioHome}img/apple-touch-icon.png"
        rel="apple-touch-icon">

  <!-- Load jQuery early so iframes can use it -->
  <script type="text/javascript"
          src="{$basehref}{$smarty.const.TL_JQUERY}"
          language="javascript"></script>

  <link href="{$dashioHome}lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="{$fontawesomeHomeURL}/css/all.css" rel="stylesheet" />


  <link rel="stylesheet" type="text/css" href="{$dashioHome}css/zabuto_calendar.css">
  <link rel="stylesheet" type="text/css" href="{$dashioHome}lib/gritter/css/jquery.gritter.css" />
  <link href="{$dashioHome}css/style.css" rel="stylesheet">
  <link href="{$dashioHome}css/style-responsive.css" rel="stylesheet">
  <link rel="stylesheet" type="text/css"
        href="{$basehref}gui/themes/default/css/frame.css">

  <script type="text/javascript">
  // The aside menu is its own frame that only loads once (see asideFrame.tpl),
  // so it has no way to know which page the mainframe is currently showing.
  // Re-run this every time the mainframe navigates (its onload below) to mark
  // the matching leaf <li> "active" - reuses the existing
  // "ul.sub li.active a" style already used for other menu states.
  function syncAsideActiveLink() {
    var mainFrame = document.getElementById('mainframe');
    var asideFrame = document.getElementById('asidebar');
    if (!mainFrame || !asideFrame) {
      return;
    }

    var mainWin, asideDoc;
    try {
      mainWin = mainFrame.contentWindow;
      asideDoc = asideFrame.contentWindow.document;
    } catch (e) {
      return;
    }

    var mainUrl;
    try {
      mainUrl = new URL(mainWin.location.href);
    } catch (e) {
      return;
    }
    var mainPath = mainUrl.pathname;
    // frmWorkArea.php is a shared entry point for many distinct features,
    // so the path alone isn't enough to tell them apart.
    var mainFeature = mainUrl.searchParams.get('feature');

    var links = asideDoc.querySelectorAll('ul.sidebar-menu ul.sub li a[target="mainframe"]');
    for (var i = 0; i < links.length; i++) {
      var link = links[i];
      var li = link.parentElement;
      li.classList.remove('active');

      var href = link.getAttribute('href');
      if (!href) {
        continue;
      }
      var linkUrl;
      try {
        linkUrl = new URL(href, mainWin.location.href);
      } catch (e) {
        continue;
      }
      if (linkUrl.pathname !== mainPath) {
        continue;
      }
      if (mainPath.indexOf('frmWorkArea.php') !== -1 &&
          linkUrl.searchParams.get('feature') !== mainFeature) {
        continue;
      }
      li.classList.add('active');
    }
  }
  </script>
</head>

<body class="body-noscroll">
  <section id="container">
   <iframe src="{$gui->titleframe}" id="titlebar" name="titlebar"
           style="display: block;" class="navigationBar">
   </iframe>

   {* The menu is a frame of its own so it survives navigation in the main
      frame. Content pages no longer draw it - see asideFrame.tpl. *}
   <div class="siteBody">
     {* Rendered collapsed straight away when it was left that way, rather
        than snapping shut once the menu has loaded. *}
     <iframe src="{$gui->asideframe}" id="asidebar" name="asidebar"
             style="display: block;"
             class="navigationAside{if isset($gui->asideRailed) && $gui->asideRailed} railed{/if}">
     </iframe>

     <iframe src="{$gui->mainframe}" id="mainframe" name="mainframe"
             style="display: block;" class="siteContent"
             onload="syncAsideActiveLink()">
     </iframe>
   </div>
  </section>

  {$bs = "{$dashioHome}lib/"}
  <!--
  js placed at the end of the document so the pages
  load faster (jQuery already loaded in HEAD)
  -->
  <script src="{$bs}bootstrap/js/bootstrap.min.js"></script>
  <script class="include" type="text/javascript" src="{$bs}jquery.dcjqaccordion.2.7.js"></script>
  <script src="{$bs}jquery.scrollTo.min.js"></script>
  <script src="{$bs}jquery.nicescroll.js" type="text/javascript"></script>
  <!--common script for all pages-->
  <script src="{$bs}common-scripts.js"></script>
  <!--script for this page-->
</body>
</html>