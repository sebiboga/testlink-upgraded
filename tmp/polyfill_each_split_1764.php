<?php
// faithful PHP-4/5 reconstructions of the removed functions, used ONLY to run the
// pre-port library on PHP 8 so old vs new output can be diffed.
if (!function_exists('each')) {
  function each(&$array) {
    $key = key($array);
    if ($key === null) return false;
    $value = current($array);
    next($array);
    return array(0=>$key, 'value'=>$value, 1=>$value, 'key'=>$key);
  }
}
if (!function_exists('split')) {
  function split($pattern, $string, $limit = -1) {
    return preg_split($pattern, $string, $limit);
  }
}
