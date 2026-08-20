## Description
Currently the header shows user info as a single line: `sebiboga [Test Manager]`

This should be improved to show the user's full name and role on separate lines, for better readability and a more professional look.

## Current Behavior
```
sebiboga [Test Manager]
```

## Expected Behavior
```
Sebastian Boga
Test Manager
```

- **Line 1:** First Name + Last Name (from `users.first` + `users.last`)
- **Line 2:** Role name (from `roles.name`)
- The login name should NOT be displayed in the header (it's for authentication only)

## Technical Details
- Header is generated in `gui/templates/dashio/navBar.tpl` (compiled to `gui/templates_c/`)
- The `gui->whoami` variable is set in `lib/functions/common.php` via `initUserEnv()`
- Currently `whoami` is formatted as `login [role]` — needs to be changed to use `firstName lastName` and render role on a second line
- The `tlWhoami` CSS class in the Dashio template needs styling for two-line display

## Why
- First name and last name are already collected during user creation but not used in the header
- Showing login names in the UI is not user-friendly and exposes internal identifiers
- Role displayed separately makes the hierarchy clearer
- Consistent with modern UI patterns (showing display name, not username)

## Related
- This affects the Dashio template only (`gui/templates/dashio/navBar.tpl`)
- The `tl-classic` template may also need updating for consistency
- The `userInfo.php` page (Account Settings) already shows full name properly
