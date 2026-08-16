<?php

namespace GuzzleHttp;

// Minimal functions_include.php for guzzlehttp/guzzle
// This file provides function facades for guzzle utilities

if (!function_exists('GuzzleHttp\choose_handler')) {
    function choose_handler() {
        $handler = null;
        if (function_exists('curl_multi_exec') && function_exists('curl_exec')) {
            $handler = new Handler\CurlMultiHandler();
        }

        return $handler;
    }
}

if (!function_exists('GuzzleHttp\default_user_agent')) {
    function default_user_agent() {
        static $defaultAgent = '';
        if (!$defaultAgent) {
            $defaultAgent = 'GuzzleHttp/' . Client::VERSION;
        }
        return $defaultAgent;
    }
}

if (!function_exists('GuzzleHttp\default_ca_bundle')) {
    function default_ca_bundle() {
        static $bundle = null;
        if (null === $bundle) {
            if (defined('HHVM_VERSION')) {
                $bundle = \Composer\CA::getBundledCaBundlePath();
            } else {
                $bundle = \Composer\CA::getBundledCaBundlePath();
            }
        }
        return $bundle;
    }
}
