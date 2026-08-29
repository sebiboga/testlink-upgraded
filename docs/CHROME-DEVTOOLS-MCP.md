# Chrome DevTools MCP — Quick Reference

## Start Chrome (Linux)

```bash
google-chrome --remote-debugging-port=9222 --user-data-dir=/home/sebi/.chrome-debug http://localhost:8082 &
```

**Key flags:**
- `--remote-debugging-port=9222` — required for DevTools MCP
- `--user-data-dir=/home/sebi/.chrome-debug` — REQUIRED (Chrome won't enable remote debugging without non-default data dir)
- `--no-sandbox` — NOT supported anymore, don't use it
- `--user-data-dir` must be an absolute path to a writable directory

## NEVER DO:
- Don't use `--no-sandbox` (deprecated/unsupported)
- Don't use `--headless` with DevTools MCP (MCP needs visible browser)
- Don't create new profiles every time — reuse `--user-data-dir=/home/sebi/.chrome-debug`
- Don't kill Chrome mid-session (`pkill chrome` kills the MCP connection)

## opencode.jsonc MCP config (already set):
```json
"mcp": {
  "chrome-devtools": {
    "type": "local",
    "command": ["npx", "-y", "chrome-devtools-mcp@latest"],
    "enabled": true
  }
}
```

## How to use Chrome DevTools MCP (learned the hard way)

### Session startup
1. Start Chrome FIRST: `nohup google-chrome --remote-debugging-port=9222 --user-data-dir=/home/sebi/.chrome-debug http://localhost:8082 > /dev/null 2>&1 &`
2. Then start opencode — MCP tools load at startup
3. Use `chrome-devtools_list_pages` to verify connection

### Key concepts
- **TestLink uses iframes!** The navBar, aside menu, and main content are all in separate iframes
- Each iframe has its own RootWebArea with different uid numbers
- You need to snapshot the specific iframe RootWebArea to interact with elements inside it
- Use `pageId=1` always (it's the main Chrome tab)

### Navigation workflow
1. **Select project**: snapshot page → find the combobox in the navBar iframe → `chrome-devtools_fill` with project prefix (e.g. "TLU:")
2. **Navigate to screen**: snapshot aside iframe → click the section link → click the sub-item
3. **The workframe iframe** loads the actual screen content

### Common patterns
- `chrome-devtools_take_snapshot` → get uids → `chrome-devtools_click` or `chrome-devtools_fill`
- After click that causes navigation: call `chrome-devtools_take_snapshot` again to get new uids
- `chrome-devtools_wait_for` to wait for text to appear after AJAX loads
- `chrome-devtools_take_screenshot` for visual verification

### DO NOT:
- Don't kill Chrome mid-session (`pkill chrome` kills MCP connection)
- Don't start new Chrome instances without checking if one exists
- Don't use `--no-sandbox` (unsupported)
- Don't use `--headless` with MCP (needs visible browser)

## Troubleshooting
- If MCP tools not available: restart opencode session (tools load at startup)
- If Chrome won't start: check if already running (`pgrep chrome`)
- If port 9222 busy: `lsof -i :9222` to see what's using it
- If Chrome killed accidentally: `nohup google-chrome --remote-debugging-port=9222 --user-data-dir=/home/sebi/.chrome-debug http://localhost:8082 > /dev/null 2>&1 &` then restart opencode
