<?php
namespace Tests\ImportIntegration;

use App\Controllers\Admin\ImportController;
use App\Libraries\Import\ImportQueue;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class ImportStatusTest extends CIUnitTestCase
{
    public function testStatusIsOwnerScopedAndExposesNoPrivatePayload(): void
    {
        $dir = sys_get_temp_dir() . '/cbt-import-status-' . bin2hex(random_bytes(6));
        $_ENV['IMPORT_QUEUE_PATH'] = $dir;
        $source = tempnam(sys_get_temp_dir(), 'cbt-status-') . '.xlsx';
        file_put_contents($source, 'private-input');
        $job = (new ImportQueue($dir))->enqueue('students', 123, $source, ['group_id' => 4], 'request');
        try {
            Services::injectMock('session', new class {
                public int $owner = 456;
                public function get($key) { return $key === 'user_id' ? $this->owner : 'admin'; }
            });
            $controller = new ImportController();
            $controller->initController(service('request'), service('response'), service('logger'));
            $this->assertSame(404, $controller->status($job['job_id'])->getStatusCode());
            session()->owner = 123;
            $response = $controller->status($job['job_id']);
            $this->assertSame(200, $response->getStatusCode());
            $json = json_decode($response->getBody(), true);
            $this->assertSame('queued', $json['status']);
            $this->assertArrayNotHasKey('source', $json);
            $this->assertStringNotContainsString('private-input', $response->getBody());
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        } finally {
            unset($_ENV['IMPORT_QUEUE_PATH']);
            foreach (glob($dir . '/uploads/*') as $p) unlink($p);
            foreach (glob($dir . '/jobs/*') as $p) unlink($p);
            foreach (glob($dir . '/*.lock') as $p) unlink($p);
            rmdir($dir . '/uploads'); rmdir($dir . '/jobs'); rmdir($dir);
            unlink($source); unlink(substr($source, 0, -5));
        }
    }
}
