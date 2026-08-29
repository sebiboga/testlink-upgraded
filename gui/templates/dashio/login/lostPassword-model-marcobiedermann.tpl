<!DOCTYPE html>
{config_load file="input_dimensions.conf" section="login"}
{lang_get var="labels"
          s='password_reset,login_name,btn_send,
             password_mgmt_is_external,link_back_to_login'}

<html >
  <head>
    <meta charset="UTF-8">
    <title>{$labels.password_reset}</title>
    <link rel="stylesheet" href="gui/icons/font-awesome-4.5.0/css/font-awesome.min.css">

    <link rel="stylesheet" href="gui/themes/default/login/codepen.io/marcobiedermann/css/style.css">
  </head>
  <body class="align">
    <div class="site__container">
      <div class="grid__container">
      <img src="{$tlCfg->theme_dir}images/{$tlCfg->logo_login}">
      </div>

      {if $gui->external_password_mgmt eq 0}
      <div class="grid__container">
      <p style="color:#333;">{$gui->note|escape}</p>
      </div>

      <div class="grid__container">
      <form name="lostpasswd" id="lostpasswd" action="lostPassword.php?viewer={$gui->viewer}" method="post" class="form form--login">

        <div class="form__field">
          <label for="login"><i class="fa fa-user"></i></label>
          <input maxlength="{#LOGIN_MAXLEN#}" name="login" id="login" type="text" class="form__input" placeholder="{$labels.login_name}" value="{$gui->login|escape}" required>
        </div>

        <div class="form__field">
          <input type="submit" name="editUser" value="{$labels.btn_send}">
        </div>

      </form>
      </div>
      {else}
      <div class="grid__container">
      <p style="color:#333;">
      {if $gui->password_mgmt_feedback == ''}
        {$labels.password_mgmt_is_external}
      {else}
        {$gui->password_mgmt_feedback|escape}
      {/if}
      </p>
      </div>
      {/if}

      <div class="grid__container">
      <p><a href="login.php">{$labels.link_back_to_login}</a></p>
      </div>
    </div>
  </body>
</html>
