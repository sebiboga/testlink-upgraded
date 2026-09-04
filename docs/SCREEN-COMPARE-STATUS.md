# SCREEN COMPARE STATUS (legacy vs modern feature-parity)

| Screen | Legacy controller | Modern screen | BFF | Status | Issues filed | Verdict |
|---|---|---|---|---|---|---|
| Test Plan with Custom Fields | `lib/results/testPlanWithCF.php` + `testPlanWithCF.tpl` | `gui/templates/results/tplanWithCF.html` | `api/reports` `tplan_with_cf` | ✓ | #847 #848 #858 #859 #860 | 5 gaps filed: #847 (suite grouping + collapsible group toolbar) #848 (direct edit dropped → read-only viewer) #858 (info_testPlanWithCF text missing) #859 (generated-by-timestamp missing) #860 (doubled glue-char in external ID) |
