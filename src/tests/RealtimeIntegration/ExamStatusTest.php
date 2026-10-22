<?php
namespace Tests\RealtimeIntegration;

use App\Controllers\Api\ExamStatusController;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class ExamStatusTest extends CIUnitTestCase
{
    private int $owner;
    private int $testId;
    private int $attempt;
    private array $connections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Config\Database::connect('default');
        $this->db->resetTransStatus();
        $instances = new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $instances->getValue();
        $instances->setValue(null, array_merge($this->connections, ['tests' => $this->db]));
        $this->db->transBegin();
        $prefix = 'ws_poll_test_' . bin2hex(random_bytes(6));
        $this->db->table('users')->insert(['username' => $prefix, 'password' => 'test-only-not-a-login-hash', 'firstname' => 'Fixture', 'role' => 'siswa', 'is_active' => 1]);
        $this->owner = (int) $this->db->insertID();
        $this->db->table('tests')->insert(['name' => $prefix, 'user_id' => $this->owner, 'duration_minutes' => 60, 'begin_time' => '2026-10-22 08:00:00', 'is_enabled' => 1]);
        $this->testId = (int) $this->db->insertID();
        $this->db->table('test_attempts')->insert(['test_id' => $this->testId, 'user_id' => $this->owner, 'status' => 1, 'started_at' => '2026-10-22 08:00:00']);
        $this->attempt = (int) $this->db->insertID();
        $this->asUser($this->owner);
    }

    protected function tearDown(): void
    {
        $this->db->transRollback();
        $this->db->resetTransStatus();
        $this->assertSame(0, $this->db->table('test_attempts')->where('id', $this->attempt)->countAllResults());
        (new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        parent::tearDown();
    }

    private function asUser(?int $owner): void
    {
        Services::injectMock('session', new class ($owner) {
            public function __construct(private ?int $owner) {}
            public function get($key) { return $key === 'user_id' ? $this->owner : null; }
        });
    }

    private function requestStatus()
    {
        $controller = new ExamStatusController();
        $controller->initController(service('request'), service('response'), service('logger'));
        return $controller->show($this->attempt);
    }

    public function testSnapshotIsPrivateOwnerScopedAndRequiresLogin(): void
    {
        $response = $this->requestStatus();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $json = json_decode($response->getBody(), true);
        $this->assertSame('success', $json['status']);
        $this->assertArrayNotHasKey('password', $json);
        $this->assertArrayNotHasKey('user_id', $json);
        $this->asUser($this->owner + 1);
        $this->assertSame(404, $this->requestStatus()->getStatusCode());
        $this->asUser(null);
        $this->assertSame(401, $this->requestStatus()->getStatusCode());
    }

    public function testLockedFinishedAndDisabledAccountsAreVisibleWithoutWebSocket(): void
    {
        foreach ([2 => 'ejected', 3 => 'finished', 4 => 'ejected'] as $value => $expected) {
            $this->db->table('test_attempts')->where('id', $this->attempt)->update(['status' => $value]);
            $this->assertSame($expected, json_decode($this->requestStatus()->getBody(), true)['status']);
        }
        $this->db->table('users')->where('id', $this->owner)->update(['is_active' => 0]);
        $this->assertSame('ban', json_decode($this->requestStatus()->getBody(), true)['status']);
    }

    public function testTimeExtensionAndModeChangesReadFreshRowsWithoutWritingAnswers(): void
    {
        $this->db->table('tests')->where('id', $this->testId)->update(['duration_minutes' => 90, 'exam_mode' => 'static', 'static_page_path' => 'static/test.html']);
        $json = json_decode($this->requestStatus()->getBody(), true);
        $this->assertSame(90, $json['duration_minutes']);
        $this->assertSame(strtotime('2026-10-22 09:30:00') * 1000, $json['end_time_ms']);
        $this->assertSame('static', $json['exam_mode']);
        $this->assertSame('static/test.html', $json['static_page_path']);
        $this->assertSame(0, $this->db->table('test_logs')->where('test_attempt_id', $this->attempt)->countAllResults());
    }
}
