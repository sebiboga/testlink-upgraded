
2.0.1 / YYYY-MM-DD (current)
- Login: implemented SSO auto-login (WEBSERVER_VAR/CLIENT_CERTIFICATE) on the modern login page with `?ssodisable` bypass; added BFF routes (`/api/auth/sso`) and i18n keys (Refs #1048).
- Bugfix: `planAddTC` save no longer logs 3× `E_WARNING "Undefined array key send_mail"` when the tester-assignment field is omitted (`api/plans/index.php:1990`) (Refs #1919).
