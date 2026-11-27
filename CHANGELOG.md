
2.0.1 / YYYY-MM-DD (current)
- Screens: added Test Plan Milestone Delivery Report `milestoneDelivery`, a read-only plan report joining milestone priority mix with dated execution evidence, with coverage/pass-rate/status split, filters, detail modal and guarded CSV (Refs #2079; fixes #2080–#2086).
- Screens: added Test Plan Defect Verification Gap `defectVerification`, a read-only plan report over defect links with escaped/unverified/verified verdicts, KPIs, filters, evidence modal and guarded CSV (Refs #2044; fixes #2045, #2046).
- Login: implemented SSO auto-login (WEBSERVER_VAR/CLIENT_CERTIFICATE) on the modern login page with `?ssodisable` bypass; added BFF routes (`/api/auth/sso`) and i18n keys (Refs #1048).
- Bugfix: `planAddTC` save no longer logs 3× `E_WARNING "Undefined array key send_mail"` when the tester-assignment field is omitted (`api/plans/index.php:1990`) (Refs #1919).
