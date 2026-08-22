## Description
The password field on the login page (`login.php`) uses `type="text"` instead of `type="password"`. This means the password is displayed in **cleartext** as the user types it.

## Severity
🔴 **Critical** (Security)

## Steps to Reproduce
1. Navigate to `login.php`
2. Inspect the password input field in browser devtools
3. Type in the password field

## Expected Behavior
Password field should use `type="password"` — characters should be masked (dots or asterisks).

## Actual Behavior
Password field renders as `type="text"` — the full password is visible in cleartext on screen.

## Impact
- Anyone near the user's screen can read the password (shoulder surfing)
- Screen recording, screenshots, and accessibility tools all expose the password
- Screen sharing / presentation sessions leak passwords

## Evidence
In the a11y tree snapshot, the password field appears as:
```
textbox "" required   (should be password "" required)
```

## Suggested Fix
Check the login template (`gui/templates/dashio/login.tpl` or `inc_head.tpl`) and ensure the password input field uses `type="password"`. The issue may be caused by the Dashio template CSS or JavaScript overriding the input type.
