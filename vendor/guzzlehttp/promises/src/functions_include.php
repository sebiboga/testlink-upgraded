<?php

namespace GuzzleHttp\Promise;

// Minimal functions_include.php for guzzlehttp/promises
// This file provides function facades for promise utilities

if (!function_exists('GuzzleHttp\Promise\promise_for')) {
    function promise_for($value) {
        if ($value instanceof PromiseInterface) {
            return $value;
        }

        if (is_array($value)) {
            return each_recursive($value);
        }

        return new FulfilledPromise($value);
    }
}

if (!function_exists('GuzzleHttp\Promise\rejection_for')) {
    function rejection_for($reason) {
        if ($reason instanceof PromiseInterface) {
            return $reason;
        }

        return new RejectedPromise($reason);
    }
}

if (!function_exists('GuzzleHttp\Promise\all')) {
    function all($promises) {
        return EachPromise($promises, [
            'concurrency' => PHP_INT_MAX,
            'fulfilled' => null,
            'rejected' => null,
        ])->promise();
    }
}

if (!function_exists('GuzzleHttp\Promise\each_recursive')) {
    function each_recursive(&$iterable, array $options = []) {
        return new EachPromise($iterable, $options);
    }
}

if (!function_exists('GuzzleHttp\Promise\settle')) {
    function settle($promises) {
        return each_recursive($promises, [])->promise();
    }
}

if (!function_exists('GuzzleHttp\Promise\queue')) {
    function queue($task = null, $wait = false) {
        static $queue;
        if (!$queue) {
            $queue = new TaskQueue();
        }

        if ($task) {
            $queue->add($task);
        }

        if ($wait && !$queue->isEmpty()) {
            $queue->run();
        }

        return $queue;
    }
}

if (!function_exists('GuzzleHttp\Promise\coroutine')) {
    function coroutine($generatorFn) {
        return new Coroutine($generatorFn);
    }
}
