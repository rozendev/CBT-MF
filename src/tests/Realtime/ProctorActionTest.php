<?php

namespace Tests\Realtime;

use App\Libraries\ProctorAction;
use PHPUnit\Framework\TestCase;

class ProctorActionTest extends TestCase
{
    public function testKnownActionsAreValid(): void
    {
        $this->assertTrue(ProctorAction::isValidAction('eject'));
        $this->assertTrue(ProctorAction::isValidAction('lock'));
        $this->assertTrue(ProctorAction::isValidAction('eject_lock'));
    }

    public function testUnknownActionIsRejected(): void
    {
        $this->assertFalse(ProctorAction::isValidAction('ban'));
        $this->assertFalse(ProctorAction::isValidAction(''));
        $this->assertFalse(ProctorAction::isValidAction('EJECT'));
    }

    public function testEjectPayloadCarriesRoutingFields(): void
    {
        $payload = ProctorAction::buildEjectPayload(3, 8, 2, 'Terindikasi membuka aplikasi lain');

        $this->assertSame('ejected', $payload['event']);
        $this->assertSame(3, $payload['user_id']);
        $this->assertSame(8, $payload['attempt_id']);
        $this->assertSame(2, $payload['test_id']);
        $this->assertStringContainsString('pengawas', $payload['message']);
    }

    public function testEjectPayloadKeepsReasonWhenGiven(): void
    {
        $payload = ProctorAction::buildEjectPayload(3, 8, 2, 'Terindikasi membuka aplikasi lain');
        $this->assertSame('Terindikasi membuka aplikasi lain', $payload['reason']);
    }

    public function testEjectPayloadUsesDefaultReasonWhenBlank(): void
    {
        $payload = ProctorAction::buildEjectPayload(3, 8, 2, '   ');
        $this->assertSame(ProctorAction::DEFAULT_REASON, $payload['reason']);
    }
}
