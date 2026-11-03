<?php

namespace App\Libraries;

final class WebSocketPromiseErrors
{
    public static function install(callable $report): void
    {
        if (!function_exists('React\\Promise\\set_rejection_handler')) return;
        // redis-react's SUBSCRIBE bookkeeping creates a second, unobserved
        // promise. Its rejection must not dump URI/exception contents to stderr.
        $handler = static function (\Throwable $error) use ($report, &$handler): void {
            \React\Promise\set_rejection_handler($handler);
            $report('Unhandled async rejection (' . get_class($error) . ')');
        };
        \React\Promise\set_rejection_handler($handler);
    }
}
