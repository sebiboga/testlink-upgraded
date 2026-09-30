# Issue #1764 — Trac XML-RPC transport: phpxmlrpc wire layer fataled on PHP 8

## Symptom
The vendored `third_party/phpxmlrpc/lib/xmlrpc.inc` used the PHP 4 functions `each()`
(removed in PHP 8.0) and `split()` (removed in PHP 7.0) at **15 sites**. The *first*
command the Trac XML-RPC interface sends — `sendCmd()` → `xmlrpcmsg::createPayload()`
→ `xmlrpcval::serialize()` — died:

```
PHP Fatal error: Uncaught Error: Call to undefined function each() in
  third_party/phpxmlrpc/lib/xmlrpc.inc:2946
```

so `getIssue()`, `checkBugIDExistence()`, `addIssue()` and `buildViewBugLink()` (the bug
link rendered on execution screens) were all dead. `connect()` still reported success,
because it only builds the client object and never touches a socket — which is why the
failure was silent until a real command was sent.

## Root cause
`third_party/phpxmlrpc` is the unmaintained TestLink 1.9.20 drop, written for PHP 4.
#1635 fixed only the *compile-time* layer of the file; the *runtime* wire layer was
never ported.

| layer | site | conversion |
|---|---|---|
| header parse | 2277 | `split("\r?\n", …)` → `preg_split('/\r?\n/', …)` |
| full walks | 2278, 2830, 3022, 3033, 3038, 3202, 3350 | `while(list($k,$v)=each($ar))` → `foreach ($ar as $k => $v)` |
| single pairs | 2946, 2959, 3013, 3055, 3068, 3216 | `reset()` + `list($a,$b)=each()` → guarded `key()`/`current()` |
| advancing pair | 3004 + callers 3244/3253 | `structeach()` re-implemented on `key()`/`current()`/`next()` |
| response length | 2545 | `count($data)` (TypeError on strings since PHP 8.0) → `strlen($data)` |

`structeach()` was the only non-mechanical one: it *returns* the advancing pair, so it
could not become a `foreach`. It was re-implemented with **PHP's own `each()` algorithm** —
read the current pair, advance the pointer, return `(0, 'value', 1, 'key')`, `false` when
exhausted — so its public contract is unchanged. Its two internal callers are full walks
preceded by `structreset()`, so they became `foreach`.

Two fidelity traps found and handled: `current()` returns `false` on an empty array where
`each()` yielded `null` (matters for `scalarval()` on a zero-arg `xmlrpcval`), and the slot
*order* of the returned array is part of the observable contract.

## Verification
* **Behaviour-preservation proof** — the pre-port library, with `each()`/`split()`
  reconstructed as a polyfill, was run against the patched file over 60+ observables
  (scalars, arrays, nested structs, every legacy accessor, the `dump()` helper, encode/decode
  of php objects, faults, datetimes). The diff of the serialized results is **empty**.
* **Live round trip** — `ticket.get` posted over TCP to a fixture endpoint: valid
  `methodCall` on the wire, `set-cookie` captured by `parseResponseHeaders()`, struct decoded.
* 31-case suite `php tmp/verify_1764.php` → 31 passed, 0 failed.

## Not in scope
* `xmlrpcs.inc` (server side, 24 `=& new`) and `xmlrpc_wrappers.inc` (7) still fail `php -l`.
  TestLink never loads them, so the app is unaffected.
* `tracxmlrpcInterface::connect()` never probes the server — inherited 1.9.20 behaviour, and a
  product decision rather than part of this fix.
* No real Trac server exists in the CI environment, so the round trip is proven against a
  fixture speaking the same wire format, not against Trac's XmlRpcPlugin.
