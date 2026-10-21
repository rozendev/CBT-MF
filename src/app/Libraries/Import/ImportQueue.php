<?php
namespace App\Libraries\Import;

/** Private single-host spool. All web/CLI processes must share this directory. */
final class ImportQueue
{
    private string $dir;
    private \Closure $clock;

    public function __construct(?string $directory = null, private int $capacity = 10, ?callable $clock = null)
    {
        $this->dir = $directory ?? (string) env('IMPORT_QUEUE_PATH', WRITEPATH . 'imports');
        $this->clock = $clock === null ? fn () => microtime(true) : \Closure::fromCallable($clock);
        foreach ([$this->dir, $this->dir . '/jobs', $this->dir . '/uploads'] as $path) {
            if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
                throw new ImportFailure('Antrean impor tidak dapat ditulis.', true, 503);
            }
            @chmod($path, 0700);
        }
    }

    public function enqueue(string $kind, int $owner, string $source, array $options, string $requestId, ?string $extension = null): array
    {
        if (!in_array($kind, ['students', 'word'], true) || $owner < 1 || $requestId === '' || strlen($requestId) > 128) {
            throw new ImportFailure('Permintaan impor tidak valid.');
        }
        $lock = $this->lock('submit', false);
        try {
            $this->prune();
            $fingerprint = hash('sha256', $kind . json_encode($options) . hash_file('sha256', $source));
            $identity = hash('sha256', $owner . ':' . $requestId);
            $pending = 0;
            foreach ($this->jobs() as $job) {
                if ($job['identity'] === $identity) {
                    if ($job['fingerprint'] !== $fingerprint) throw new ImportFailure('ID impor sudah dipakai untuk file atau tujuan berbeda.', false, 409);
                    return $this->publicStatus($job);
                }
                if (in_array($job['status'], ['queued', 'running', 'retrying'], true)) $pending++;
            }
            if ($pending >= $this->capacity) throw new ImportFailure('Antrean impor penuh. Coba lagi setelah job sebelumnya selesai.', true, 429);
            $id = bin2hex(random_bytes(16));
            $extension = strtolower($extension ?? pathinfo($source, PATHINFO_EXTENSION));
            if (!in_array($extension, ['xlsx', 'xls', 'docx'], true)) throw new ImportFailure('Format file tidak didukung.');
            $target = $this->dir . '/uploads/' . $id . '.' . $extension;
            if (!copy($source, $target)) throw new ImportFailure('File impor tidak dapat disimpan.', true, 503);
            chmod($target, 0600);
            $job = [
                'job_id' => $id, 'kind' => $kind, 'owner' => $owner, 'source' => $target,
                'options' => $options, 'identity' => $identity, 'fingerprint' => $fingerprint,
                'status' => 'queued', 'attempts' => 0, 'created_at' => ($this->clock)(),
                'available_at' => 0, 'progress' => [], 'result' => null, 'message' => 'Menunggu giliran impor.',
            ];
            try { $this->write($job); } catch (\Throwable $error) { @unlink($target); throw $error; }
            return $this->publicStatus($job);
        } finally { $this->unlock($lock); }
    }

    public function status(string $id, int $owner): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return null;
        $job = $this->read($this->dir . '/jobs/' . $id . '.json');
        return $job && $job['owner'] === $owner ? $this->publicStatus($job) : null;
    }

    public function lookup(string $requestId, int $owner): ?array
    {
        if ($requestId === '' || strlen($requestId) > 128 || $owner < 1) return null;
        $identity = hash('sha256', $owner . ':' . $requestId);
        foreach ($this->jobs() as $job) if ($job['identity'] === $identity && $job['owner'] === $owner) return $this->publicStatus($job);
        return null;
    }

    /** The lock spans parsing, hashing, DB writes and checkpointing across all job types. */
    public function runOne(callable $processor): bool
    {
        $lock = $this->lock('worker', true);
        if ($lock === null) return false;
        try {
            $submitLock = $this->lock('submit', false);
            try { $this->prune(); } finally { $this->unlock($submitLock); }
            foreach ($this->jobs() as $job) {
                if ($job['status'] === 'running') {
                    $this->failed($job, new ImportFailure('Worker terhenti. Melanjutkan dari progres terakhir.', true));
                    continue;
                }
                if (!in_array($job['status'], ['queued', 'retrying'], true) || $job['available_at'] > ($this->clock)()) continue;
                $job['status'] = 'running';
                $job['attempts']++;
                $job['message'] = 'Memproses impor.';
                $this->write($job);
                try {
                    if (!is_file($job['source'])) throw new ImportFailure('File impor sudah tidak tersedia. Unggah kembali.');
                    $result = $processor($job, function (array $progress) use (&$job): void {
                        $job['progress'] = $progress;
                        $this->write($job);
                    });
                    $job['status'] = 'completed';
                    $job['result'] = $result;
                    $job['message'] = 'Impor selesai.';
                    $this->write($job);
                    @unlink($job['source']);
                } catch (\Throwable $error) {
                    $this->failed($job, ImportFailure::from($error));
                }
                return true;
            }
            return false;
        } finally { $this->unlock($lock); }
    }

    private function failed(array &$job, ImportFailure $error): void
    {
        $job['message'] = $error->getMessage();
        $job['status'] = $error->retryable && $job['attempts'] < 4 ? 'retrying' : 'failed';
        $base = 2 ** max(0, $job['attempts'] - 1);
        $jitter = hexdec(substr(hash('sha256', $job['job_id'] . ':' . $job['attempts']), 0, 6)) % 1000 / 1000;
        $job['available_at'] = ($this->clock)() + $base + $jitter;
        $this->write($job);
        if ($job['status'] === 'failed') @unlink($job['source']);
    }

    private function publicStatus(array $job): array
    {
        $status = array_intersect_key($job, array_flip(['job_id', 'kind', 'status', 'attempts', 'progress', 'result', 'message']));
        $status['progress'] = array_intersect_key($job['progress'], array_flip(['phase', 'cursor', 'processed', 'total', 'success', 'duplicates', 'failed']));
        return $status;
    }

    private function jobs(): array
    {
        $jobs = [];
        foreach (glob($this->dir . '/jobs/*.json') as $file) {
            $job = $this->read($file);
            if ($job) $jobs[] = $job;
        }
        usort($jobs, fn ($a, $b) => [$a['created_at'], $a['job_id']] <=> [$b['created_at'], $b['job_id']]);
        return $jobs;
    }

    private function read(string $path): ?array
    {
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null; // Another pruner may have removed an expired job.
        $job = json_decode($raw, true);
        if (!is_array($job) || !isset($job['job_id'], $job['status'], $job['owner'], $job['identity'], $job['created_at'])) {
            @rename($path, $path . '.invalid');
            log_message('error', 'Import job metadata quarantined: {name}', ['name' => basename($path)]);
            return null;
        }
        return $job;
    }

    private function write(array $job): void
    {
        $path = $this->dir . '/jobs/' . $job['job_id'] . '.json';
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, json_encode($job, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new ImportFailure('Progres impor tidak dapat disimpan.', true, 503);
        chmod($temp, 0600);
        if (!rename($temp, $path)) { @unlink($temp); throw new ImportFailure('Progres impor tidak dapat diperbarui.', true, 503); }
    }

    private function prune(): void
    {
        $referenced = [];
        foreach (glob($this->dir . '/jobs/*.json') as $file) {
            $job = $this->read($file);
            if (!$job) continue;
            if ($job['status'] === 'running' || $job['created_at'] > ($this->clock)() - 86400) {
                $referenced[$job['source']] = true;
                continue;
            }
            @unlink($job['source']);
            @unlink($file);
        }
        foreach (array_merge(glob($this->dir . '/uploads/*'), glob($this->dir . '/jobs/*.tmp'), glob($this->dir . '/jobs/*.invalid')) as $file) {
            if (!isset($referenced[$file]) && filemtime($file) < ($this->clock)() - 86400) @unlink($file);
        }
    }

    private function lock(string $name, bool $nonblocking)
    {
        $handle = fopen($this->dir . '/' . $name . '.lock', 'c');
        if ($handle === false) throw new ImportFailure('Antrean impor tidak dapat dikunci.', true, 503);
        chmod($this->dir . '/' . $name . '.lock', 0600);
        if (!flock($handle, LOCK_EX | ($nonblocking ? LOCK_NB : 0))) { fclose($handle); return null; }
        return $handle;
    }

    private function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
