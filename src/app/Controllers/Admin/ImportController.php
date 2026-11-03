<?php
namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Import\ImportFailure;
use App\Libraries\Import\ImportQueue;

final class ImportController extends BaseController
{
    public function lookup(string $requestId)
    {
        try {
            $queue = new ImportQueue();
            $job = $queue->lookup($requestId, (int) session('user_id'));
            if (!$job) return service('response')->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Permintaan belum tercatat. Pilih file lagi untuk mengulang pengiriman dengan ID yang sama.']);
            return service('response')->setStatusCode(200)->setHeader('Cache-Control', 'no-store')->setJSON(self::withWorkerState($queue, $job));
        } catch (\Throwable $error) { return self::error($error); }
    }

    public function status(string $id)
    {
        try {
            $queue = new ImportQueue();
            $job = $queue->status($id, (int) session('user_id'));
            if (!$job) return service('response')->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Job impor tidak ditemukan.']);
            return service('response')->setStatusCode(200)->setHeader('Cache-Control', 'no-store')->setJSON(self::withWorkerState($queue, $job));
        } catch (\Throwable $error) { return self::error($error); }
    }

    /** A queued job with no worker heartbeat would otherwise look "waiting" forever. */
    private static function withWorkerState(ImportQueue $queue, array $job): array
    {
        if (!in_array($job['status'], ['queued', 'retrying'], true)) return $job;
        $job['worker_alive'] = $queue->workerAlive();
        if (!$job['worker_alive']) {
            $job['message'] = 'Worker impor tidak aktif, job tetap antre dan diproses otomatis begitu worker menyala. '
                . 'Minta admin server menjalankan: sudo bash scripts/cbt.sh docker up';
        }
        return $job;
    }

    public static function error(\Throwable $error)
    {
        $failure = ImportFailure::from($error);
        $response = service('response')->setStatusCode($failure->httpStatus)->setHeader('Cache-Control', 'no-store');
        if (in_array($failure->httpStatus, [429, 503], true)) $response->setHeader('Retry-After', '10');
        return $response->setJSON(['status' => 'error', 'message' => $failure->getMessage()]);
    }
}
