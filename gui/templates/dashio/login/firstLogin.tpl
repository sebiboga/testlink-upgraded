{*
TestLink Open Source Project - http://testlink.sourceforge.net/
@filesource: firstLogin.tpl
Purpose: smarty template - first login / sign up (dashio theme)
*}
<!DOCTYPE html>
{config_load file="input_dimensions.conf" section="login"}
{lang_get var='labels'
          s='login_name,password,password_again,first_name,last_name,e_mail,
             password_mgmt_is_external,btn_add_user_data,link_back_to_login'}

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TestLink - {$labels.btn_add_user_data}</title>

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
      <form class="form-login" name="signup" id="signup"
        action="firstLogin.php" method="post">

        <h2 class="form-login-heading">
        <img src="{$tlCfg->theme_dir}images/{$tlCfg->logo_login}"><br>
        {$labels.btn_add_user_data}</h2>

        {if $gui->message != ''}
          <div class="login-wrap">
            <div class="alert-danger">{$gui->message|escape}</div>
          </div>
        {/if}

        <div class="login-wrap">
          <input maxlength="{#LOGIN_MAXLEN#}" name="login" id="login"
            type="text" class="form-control" placeholder="{$labels.login_name}"
            value="{$gui->login|escape}" required autofocus>
          <br>

          {if $gui->external_password_mgmt eq 0}
            <input type="password" name="password" id="password"
              class="form-control" placeholder="{$labels.password}"
              maxlength="{#PASSWD_SIZE#}" required>
            <br>
            <input type="password" name="password2" id="password2"
              class="form-control" placeholder="{$labels.password_again}"
              maxlength="{#PASSWD_SIZE#}" required>
            <br>
          {else}
            <p>{$labels.password_mgmt_is_external}</p>
            <br>
          {/if}

          <input maxlength="{#NAMES_SIZE#}" name="firstName" id="firstName"
            type="text" class="form-control" placeholder="{$labels.first_name}"
            value="{$gui->firstName|escape}" required>
          <br>

          <input maxlength="{#NAMES_SIZE#}" name="lastName" id="lastName"
            type="text" class="form-control" placeholder="{$labels.last_name}"
            value="{$gui->lastName|escape}" required>
          <br>

          <input maxlength="{#EMAIL_MAXLEN#}" name="email" id="email"
            type="email" class="form-control" placeholder="{$labels.e_mail}"
            value="{$gui->email|escape}" required>

          <label class="checkbox">&nbsp;</label>

          <button name="doEditUser" id="doEditUser" value="1"
            class="btn btn-theme btn-block" type="submit">
            <i class="fa fa-user-plus"></i> {$labels.btn_add_user_data}
          </button>
          <hr>

          <div class="registration">
            <a class="" href="login.php" id="tl_back_to_login">
              {$labels.link_back_to_login}
            </a>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- js placed at the end of the document so the pages load faster -->
  <script src="{$dashioHome}lib/jquery/jquery.min.js"></script>
  <script src="{$dashioHome}lib/bootstrap/js/bootstrap.min.js"></script>
  <!--BACKSTRETCH-->
  <script type="text/javascript"
          src="{$dashioHome}lib/jquery.backstretch.min.js"></script>
  <script>
    $.backstretch("gui/templates/dashio/img/login/login-bg.jpg", {
      speed: 500
    });
  </script>
</body>

</html>
