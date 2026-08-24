<?php
/**
 * Composer post-install/post-update guard: keeps the PHP 8.4 fix for
 * symfony/translation t() in place.
 *
 * Upstream symfony/translation v6.4.0 declares
 *   function t(string $message, array $parameters = [], string $domain = null)
 * which PHP >= 8.4 rejects at compile time with
 *   E_DEPRECATED "Implicitly marking parameter $domain as nullable is deprecated…"
 *
 * The fixed signature uses an explicit nullable type (?string $domain).
 * Because composer install/update restores pristine upstream files, this
 * script re-applies the one-line patch after every install/update.
 *
 * Idempotent and fail-safe: exits 0 whether patching was needed, already
 * applied, or no longer applicable (package removed / upstream changed).
 *
 * See GitHub issue #481.
 */

$target = __DIR__ . '/../vendor/symfony/translation/Resources/functions.php';

if (!is_file($target)) {
    fwrite(STDERR, "[patch_vendor_php84] symfony/translation functions.php not found — skipping\n");
    exit(0);
}

$code = file_get_contents($target);

$broken = 'function t(string $message, array $parameters = [], string $domain = null): TranslatableMessage';
$fixed  = 'function t(string $message, array $parameters = [], ?string $domain = null): TranslatableMessage';

if (!strpos($code, $broken) && !strpos($code, $fixed)) {
    // Upstream signature changed (e.g. dependency upgrade) — nothing to do.
    fwrite(STDERR, "[patch_vendor_php84] known signatures not found — upstream may have changed; skipping\n");
    exit(0);
}

if (strpos($code, $fixed) !== false) {
    echo "[patch_vendor_php84] already patched — nothing to do\n";
    exit(0);
}

file_put_contents($target, str_replace($broken, $fixed, $code));
echo "[patch_vendor_php84] patched {$target}: \$domain is now explicitly nullable (?string)\n";
exit(0);
