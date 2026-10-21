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
            $job = (new ImportQueue())->lookup($requestId, (int) session('user_id'));
            if (!$job) return service('response')->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Permintaan belum tercatat. Pilih file lagi untuk mengulang pengiriman dengan ID yang sama.']);
            return service('response')->setStatusCode(200)->setHeader('Cache-Control', 'no-store')->setJSON($job);
        } catch (\Throwable $error) { return self::error($error); }
    }

    public function status(string $id)
    {
        try {
            $job = (new ImportQueue())->status($id, (int) session('user_id'));
            if (!$job) return service('response')->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Job impor tidak ditemukan.']);
            return service('response')->setStatusCode(200)->setHeader('Cache-Control', 'no-store')->setJSON($job);
        } catch (\Throwable $error) { return self::error($error); }
    }

    public static function error(\Throwable $error)
    {
        $failure = ImportFailure::from($error);
        $response = service('response')->setStatusCode($failure->httpStatus)->setHeader('Cache-Control', 'no-store');
        if (in_array($failure->httpStatus, [429, 503], true)) $response->setHeader('Retry-After', '10');
        return $response->setJSON(['status' => 'error', 'message' => $failure->getMessage()]);
    }
}
