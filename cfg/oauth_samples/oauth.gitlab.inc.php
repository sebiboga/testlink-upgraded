<?php
# filesource oauth.gitlab.inc.php
#
# Some useful examples/documentation
# https://docs.gitlab.com/ce/api/oauth2.html
# https://grafana.com/docs/grafana/latest/auth/gitlab/

# Libraries used to create client
# https://github.com/thephpleague/oauth2-client
# https://github.com/omines/oauth2-gitlab
#
# IMPORTANT NOTICE
# key in $tlCfg->OAuthServers[]
# can be anything you want that make this configuration
# does not overwrite other or will be overwritten
#
# HOW TO use this file ?
# 1. copy this file to 
#     [TESTLINK_INSTALL]/cfg/
#
# 2. configure according your application
#
# 3. add the following line to your custom_config.inc.php
#    require('aouth.gitlab.inc.php');
#
# ##############################################################
#
# This is a working example for test site
# http://fman.hopto.org/
#
# You need to create the configuration for your site
# This is only a working example that is useful
# for the TestLink Development Team
#
$tlCfg->OAuthServers['gitlab'] = array();

$tlCfg->OAuthServers['gitlab']['redirect_uri'] = 
   'http://fman.hopto.org/login.php?oauth=gitlab';

$tlCfg->OAuthServers['gitlab']['oauth_enabled'] = true;
$tlCfg->OAuthServers['gitlab']['oauth_name'] = 'gitlab';

$tlCfg->OAuthServers['gitlab']['oauth_client_id'] = 'CHANGE_WITH_CLIENT_ID';

$tlCfg->OAuthServers['gitlab']['oauth_client_secret'] = 'CHANGE_WITH_CLIENT_SECRET';
