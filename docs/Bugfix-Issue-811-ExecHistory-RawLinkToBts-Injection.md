# Bugfix: Issue #811 — execHistory console SyntaxError when linked-bug summary embeds raw issue body

## Summary

On the modernized **Execution History** screen
(`gui/templates/execute/execHistory.html`), expanding an execution whose linked
GitHub bug's `link_to_bts` (a server-generated anchor whose visible text can be
the **full raw GitHub issue body** — newlines, backticks, quotes, `<br>`) was fed
raw into the page threw a browser console error:

```
Uncaught SyntaxError: Failed to execute 'appendChild' on 'Node':
Unexpected identifier 'events'
```

`renderDetail()` built each bug row as
`'<div class="bug-item"><i class="fa fa-bug"></i>' + (b.link_to_bts ? b.link_to_bts : esc(b.id)) + stepMark + '</div>'`
and handed the whole string to `$('#histTable tbody').html(h)`. jQuery's
regex/tokenizer-based HTML parser mis-tokenized the embedded raw body (it can
even be swallowed as a fake `<script>` and mis-parsed), polluting the console
and sometimes leaving stray DOM text nodes drawn from the template source.

## Root Cause

1. `api/execute/index.php` transports the bug-tracker's ready-made HTML anchor as
   `link_to_bts` (untrusted raw HTML), alongside a safe `bug_url` +
   `id`/`step_number` (`?action=history` ~lines 248-254). The legacy `.tpl`
   screens assumed the browser would trust that HTML.
2. `renderDetail()` in `execHistory.html` injected `link_to_bts` **unescaped**
   into a jQuery `.html()` string. Any `link_to_bts` whose visible text is not
   plain/simple (newlines, backticks, quotes, literal `<` from the issue body)
   breaks jQuery's parser, throwing the `SyntaxError` and corrupting part of the
   rendered fragment.

## Fix (the HOW)

Stop treating the bug-tracker's raw HTML as trusted. In `renderDetail()`'s bugs
loop, build the link from the **escaped** `bug_url` + an **escaped** label
`#<id>`, falling back to `esc(b.id)` when no `bug_url` is present (ITS-less
mode). This matches the already-modernized **Execute Tests** screen
(`execTest.html` `bugBadgeHtml()`, lines 548-562), which never injected
`link_to_bts` — it renders a safe `<a href="{escaped bug_url}" ...>#<id></a>`.

Why this approach (and what was rejected): sanitizing arbitrary issue-body text
is fragile and open-ended; there is no server contract guaranteeing what
`link_to_bts` may contain. Dropping the raw HTML and rendering the stable,
escaped `#<id>` link to `bug_url` is both safe and consistent with the rest of
the modernized UI. The step marker (`· st. {n}`, `exe.bugOnStepShort`) is kept
unchanged.

## Files Changed

- `gui/templates/execute/execHistory.html` — `renderDetail()` bugs loop: replaced
  `(b.link_to_bts ? b.link_to_bts : esc(b.id))` with an escaped `bug_url`-based
  anchor (`#<id>` label), fallback to `esc(b.id)`. No new i18n keys; no API change
  (`bug_url`/`id`/`step_number` already returned by the BFF).

## Verification

- `node --check` on the extracted inline `<script>` body: no syntax errors.
- `grep` confirms no remaining `link_to_bts` reference in `execHistory.html`.
- In-browser (admin/admin, `execHistory.html?tcase_id=...`): calling
  `renderDetail({... bugs:[{id:12,bug_url:".../12",step_number:0},{id:13,...
  step_number:2}]})` and feeding it through `$('#histTable tbody').html()` renders
  clean escaped anchors:
  - `<a href=".../12" target="_blank" rel="noopener">#12</a>` (case-level)
  - `<a href=".../13" target="_blank" rel="noopener">#13</a><span class="bug-step-mark">· st. 2</span>`
  with **no** console `SyntaxError`, regardless of the original issue-body content.
- Event Viewer / `events` table: no new Error/Warning rows from this fix.
- Regression suite **TC-811.1–811.7** recorded in `tmp/TLU_Test_Cases.md`: 7/7 PASS.

## Screenshot

![execHistory linked-bugs rendered as clean escaped links](docs/issue-811-exechistory-bugs-clean-links.png)
