<?php

namespace Tests\Realtime;

use App\Libraries\WebSocketPromiseErrors;
use PHPUnit\Framework\TestCase;

final class WebSocketPromiseErrorsTest extends TestCase
{
    public function testVendorShadowRejectionsAreReportedWithoutExceptionContents(): void
    {
        $this->assertTrue(class_exists(WebSocketPromiseErrors::class), 'Daemon rejection reporter is missing');
        $before = \React\Promise\set_rejection_handler(null);
        $messages = [];
        try {
            WebSocketPromiseErrors::install(static function (string $message) use (&$messages): void { $messages[] = $message; });
            // redis-react creates an unobserved child for SUBSCRIBE internally.
            \React\Promise\reject(new \RuntimeException('redis://:secret@host?ws_token=secret'))->then(static function (): void {});
            \React\Promise\reject(new \RuntimeException('another secret'))->then(static function (): void {});
            $this->assertSame(['Unhandled async rejection (RuntimeException)', 'Unhandled async rejection (RuntimeException)'], $messages);
        } finally {
            \React\Promise\set_rejection_handler($before);
        }
    }
}
