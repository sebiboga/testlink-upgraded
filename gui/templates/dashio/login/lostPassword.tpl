{*
TestLink Open Source Project - http://testlink.sourceforge.net/
@filesource: lostPassword.tpl
Purpose: smarty template - lost password (dashio theme)
*}
<!DOCTYPE html>
{config_load file="input_dimensions.conf" section="login"}
{lang_get var='labels'
          s='password_reset,login_name,btn_send,
             password_mgmt_is_external,link_back_to_login'}

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$gui->page_title|escape}</title>

  <!-- Favicons -->
  <link href="{$dashioHome}favicon.png" rel="icon">
  <link href="{$dashioHome}apple-touch-icon.png" rel="apple-touch-icon">

  <!-- Bootstrap core CSS -->
  <link href="{$dashioHome}lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <!--external css-->
  <link href="{$fontawesomeHomeURL}/css/all.css" rel="stylesheet" />

  <!-- Custom styles for this template -->
  <link href="{$dashioHome}css/style.css" rel="stylesheet">
  <link href="{$dashioHome}css/style-responsive.css" rel="stylesheet">
</head>

<body>
  <div id="login-page">
    <div class="container">
      <form class="form-login" name="lostpasswd" id="lostpasswd"
        action="lostPassword.php" method="post">

        <h2 class="form-login-heading">
        <img src="{$tlCfg->theme_dir}images/{$tlCfg->logo_login}"><br>
        {$labels.password_reset|escape}</h2>

        {if $gui->external_password_mgmt eq 0}
          <div class="login-wrap">
            <p style="font-size:13px;color:#555;">{$gui->note|escape}</p>
            <input maxlength="{#LOGIN_MAXLEN#}" name="login" id="login"
              type="text" class="form-control" placeholder="{$labels.login_name}"
              value="{$gui->login|escape}" required autofocus>
            <label class="checkbox">&nbsp;</label>
            <button name="editUser" id="doEditUser" value="1"
              class="btn btn-theme btn-block" type="submit">
              <i class="fa fa-envelope"></i> {$labels.btn_send}
            </button>
            <hr>
            <div class="registration">
              <a href="login.php" id="tl_back_to_login">
                {$labels.link_back_to_login}
              </a>
            </div>
          </div>
        {else}
          <div class="login-wrap">
            <p style="font-size:13px;color:#555;">
            {if $gui->password_mgmt_feedback == ''}
              {$labels.password_mgmt_is_external}
            {else}
              {$gui->password_mgmt_feedback|escape}
            {/if}
            </p>
            <hr>
            <div class="registration">
              <a href="login.php" id="tl_back_to_login">
                {$labels.link_back_to_login}
              </a>
            </div>
          </div>
        {/if}
      </form>
    </div>
  </div>

  <!-- js placed at the end of the document so the pages load faster -->
  <script src="{$dashioHome}lib/jquery/jquery.min.js"></script>
  <script src="{$dashioHome}lib/bootstrap/js/bootstrap.min.js"></script>
  <script type="text/javascript"
          src="{$dashioHome}lib/jquery.backstretch.min.js"></script>
  <script>
    $.backstretch("gui/templates/dashio/img/login/login-bg.jpg", {
      speed: 500
    });
  </script>
</body>

</html>
