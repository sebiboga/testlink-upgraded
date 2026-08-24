<?php
/**
 * Composer post-install/post-update guard: keeps the PHP 8.4 fix for
 * symfony/translation t() in place.
 *
 * Upstream symfony/translation v6.4.0 declares
 *   function t(string $message, array $parameters = [], string $domain = null)
 * and its direct callee TranslatableMessage::__construct() carries the same
 * implicit-nullable signature. PHP >= 8.4 rejects both at compile time with
 *   E_DEPRECATED "Implicitly marking parameter $domain as nullable is deprecated…"
 *
 * functions.php is included eagerly by Composer's files autoloader on every
 * request; TranslatableMessage is lazy-loaded on first actual use of t().
 *
 * The fixed signatures use an explicit nullable type (?string $domain).
 * Because composer install/update restores pristine upstream files, this
 * script re-applies the patches after every install/update.
 *
 * Idempotent and fail-safe: exits 0 whether patching was needed, already
 * applied, or no longer applicable (package removed / upstream changed).
 *
 * See GitHub issue #481.
 */

$patches = [
    [
        'file'   => __DIR__ . '/../vendor/symfony/translation/Resources/functions.php',
        'broken' => 'function t(string $message, array $parameters = [], string $domain = null): TranslatableMessage',
        'fixed'  => 'function t(string $message, array $parameters = [], ?string $domain = null): TranslatableMessage',
        'label'  => '$domain',
    ],
    [
        'file'   => __DIR__ . '/../vendor/symfony/translation/TranslatableMessage.php',
        'broken' => 'public function __construct(string $message, array $parameters = [], string $domain = null)',
        'fixed'  => 'public function __construct(string $message, array $parameters = [], ?string $domain = null)',
        'label'  => '$domain',
    ],
    [
        'file'   => __DIR__ . '/../vendor/symfony/translation/TranslatableMessage.php',
        'broken' => 'public function trans(TranslatorInterface $translator, string $locale = null): string',
        'fixed'  => 'public function trans(TranslatorInterface $translator, ?string $locale = null): string',
        'label'  => '$locale',
    ],
];

foreach ($patches as $patch) {
    $target = $patch['file'];

    if (!is_file($target)) {
        fwrite(STDERR, "[patch_vendor_php84] {$target} not found — skipping\n");
        continue;
    }

    $code = file_get_contents($target);
    if ($code === false) {
        fwrite(STDERR, "[patch_vendor_php84] could not read {$target} — skipping\n");
        continue;
    }

    if (strpos($code, $patch['fixed']) !== false) {
        echo "[patch_vendor_php84] already patched — nothing to do: {$target}\n";
        continue;
    }

    if (strpos($code, $patch['broken']) === false) {
        // Upstream signature changed (e.g. dependency upgrade) — nothing to do.
        fwrite(STDERR, "[patch_vendor_php84] known signatures not found in {$target} — upstream may have changed; skipping\n");
        continue;
    }

    $result = file_put_contents($target, str_replace($patch['broken'], $patch['fixed'], $code));
    if ($result === false) {
        fwrite(STDERR, "[patch_vendor_php84] FAILED to write {$target} — vendor dir read-only or disk error\n");
        exit(1);
    }
    echo "[patch_vendor_php84] patched {$target}: {$patch['label']} is now explicitly nullable (?string)\n";
}

exit(0);
