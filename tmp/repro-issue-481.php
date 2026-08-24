<?php
/**
 * Reproduces PHP 8.4's compile-time E_DEPRECATED for implicitly nullable
 * parameters ("Implicitly marking parameter $X as nullable is deprecated,
 * the explicit nullable type must be used instead").
 *
 * PHP 8.4 emits this when compiling a file that declares a parameter whose
 * type is not explicitly nullable (?T / T|null) but whose default value is
 * null. This scanner walks tokens and reports the exact same condition.
 *
 * Usage: php repro-issue-481.php <file-or-dir> [...]
 */
declare(strict_types=1);

$targets = array_slice($argv, 1);
if (!$targets) {
    fwrite(STDERR, "usage: php repro.php <file|dir>...\n");
    exit(2);
}

$files = [];
foreach ($targets as $t) {
    if (is_dir($t)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($t));
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
                $files[] = $f->getPathname();
            }
        }
    } else {
        $files[] = $t;
    }
}
sort($files);

function typeIsExplicitlyNullable(string $type): bool
{
    return str_contains($type, '?')
        || preg_match('/(^|\|)null(\||$)/i', $type) === 1;
}

$deprecated = 0;
foreach ($files as $file) {
    $tokens = @token_get_all(file_get_contents($file));
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_FUNCTION, T_FN], true)) {
            continue;
        }
        // advance to '(' of the parameter list
        $j = $i + 1;
        while ($j < $n && !in_array($tokens[$j], ['(', ')', '{', ';', ','], true)) {
            $j++;
        }
        if ($j >= $n || $tokens[$j] !== '(') {
            continue;
        }

        $depth = 1;
        $k = $j + 1;
        $paramType = '';
        $paramName = null;
        $defaultIsNull = false;
        $defaultIsNonNull = false;
        $lineRef = 0;

        $flush = function () use (&$paramType, &$paramName, &$defaultIsNull, &$defaultIsNonNull, &$deprecated, $file, &$lineRef) {
            if ($paramName !== null && $paramType !== '' && $defaultIsNull
                && !$defaultIsNonNull && !typeIsExplicitlyNullable($paramType)) {
                printf(
                    "Deprecated: Implicitly marking parameter \$%s as nullable is deprecated, the explicit nullable type must be used instead in %s on line %d\n",
                    ltrim($paramName, '$'),
                    $file,
                    $lineRef
                );
                $deprecated++;
            }
            $paramType = '';
            $paramName = null;
            $defaultIsNull = false;
            $defaultIsNonNull = false;
        };

        while ($k < $n && $depth > 0) {
            $tok = $tokens[$k];
            if (is_array($tok)) {
                switch ($tok[0]) {
                    case T_WHITESPACE:
                    case T_ATTRIBUTE:
                    case T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG:
                        break;
                    case T_VARIABLE:
                        $paramName = $tok[1];
                        $lineRef = $tok[2];
                        break;
                    case T_STRING:
                    case T_NAME_QUALIFIED:
                    case T_NAME_FULLY_QUALIFIED:
                    case T_ARRAY:
                    case T_CALLABLE:
                    case T_NS_SEPARATOR:
                    case T_ELLIPSIS:
                        if ($tok[0] === T_STRING && strcasecmp($tok[1], 'null') === 0) {
                            if ($paramName === null) { $paramType .= 'null'; }
                            else { $defaultIsNull = true; }
                            break;
                        }
                        if ($paramName === null) { $paramType .= $tok[1]; }
                        else { $defaultIsNonNull = true; }
                        break;
                    case T_LNUMBER:
                    case T_DNUMBER:
                    case T_CONSTANT_ENCAPSED_STRING:
                        if ($paramName !== null) { $defaultIsNonNull = true; }
                        break;
                }
                $k++;
                continue;
            }
            switch ($tok) {
                case '(': case '[': case '{':
                    $depth++; break;
                case ')':
                    $flush(); $depth--; break;
                case ']': case '}':
                    $depth--; break;
                case ',':
                    $flush(); break;
                case '|':
                    if ($paramName === null) { $paramType .= '|'; }
                    break;
                case '?':
                    if ($paramName === null) { $paramType .= '?'; }
                    break;
            }
            $k++;
        }
        $i = max($i, $k - 1);
    }
}

printf("\n%d implicitly-nullable parameter(s) detected across %d file(s)\n", $deprecated, count($files));
exit($deprecated > 0 ? 1 : 0);
