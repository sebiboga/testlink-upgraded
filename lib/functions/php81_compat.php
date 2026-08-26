<?php
/**
 * TestLink Open Source Project - http://testlink.org/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * PHP 8.1+ Compatibility Shims and Helpers
 *
 * @package     TestLink
 * @author      TestLink Community
 * @copyright   2026, TestLink community
 * @filesource  php81_compat.php
 * @link        http://www.testlink.org/
 *
 * Provides compatibility functions for PHP 8.1+ deprecated features
 */

/**
 * Replacement for deprecated strftime() function in PHP 8.1+
 * Converts strftime patterns to date() format strings (bug #563 fix)
 *
 * @param string $format The format string (strftime-style)
 * @param int $timestamp Unix timestamp (optional)
 * @return string Formatted date string
 */
if (!function_exists('tlStrftime')) {
  function tlStrftime($format, $timestamp = null) {
    if ($timestamp === null) {
      $timestamp = time();
    }

    if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
      // strftime() is deprecated in PHP 8.1+. Convert strftime specifiers
      // to date() equivalents. IntlDateFormatter is NOT usable here because
      // it expects ICU patterns (dd/MM/yyyy) whereas this function receives
      // strftime patterns (%d/%m/%Y) — passing strftime directly produces
      // wrong output (bug #563).
      $replacements = [
        '%%' => '%',   // Literal percent — MUST be first to avoid corruption
        '%Y' => 'Y',  // 4-digit year
        '%y' => 'y',  // 2-digit year
        '%m' => 'm',  // Month (01-12)
        '%d' => 'd',  // Day of month (01-31)
        '%e' => 'j',  // Day of month (1-31)
        '%H' => 'H',  // Hour (00-23)
        '%M' => 'i',  // Minute (00-59)
        '%S' => 's',  // Second (00-59)
        '%W' => 'W',  // ISO week number of year
        '%A' => 'l',  // Full weekday name
        '%a' => 'D',  // Abbreviated weekday name
        '%B' => 'F',  // Full month name
        '%b' => 'M',  // Abbreviated month name
        '%c' => 'r',  // Locale's date and time representation
        '%x' => 'n/j/y', // Locale's date representation
        '%X' => 'H:i:s', // Locale's time representation
      ];

      $date_format = $format;
      foreach ($replacements as $strftime_char => $date_char) {
        $date_format = str_replace($strftime_char, $date_char, $date_format);
      }

      return date($date_format, $timestamp);
    } else {
      // PHP < 8.1, use native strftime (suppressed for compatibility)
      return @strftime($format, $timestamp);
    }
  }
}

/**
 * Safe count wrapper for PHP 8.1+ type safety
 * Returns 0 for non-countable values instead of throwing TypeError
 *
 * @param mixed $value The value to count
 * @param int $mode Optional count mode
 * @return int The count of the value
 */
if (!function_exists('tlCount')) {
  function tlCount($value, $mode = COUNT_NORMAL) {
    if (is_array($value) || $value instanceof Countable) {
      return count($value, $mode);
    }
    return 0;
  }
}

/**
 * Ensure value is countable (array or Countable object)
 * Converts null to empty array for safe count() operations
 *
 * @param mixed $value The value to ensure is countable
 * @return array|Countable
 */
if (!function_exists('tlEnsureCountable')) {
  function tlEnsureCountable($value) {
    if (is_array($value) || $value instanceof Countable) {
      return $value;
    }
    return [];
  }
}
