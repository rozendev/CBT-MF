<?php

namespace App\Models;

use CodeIgniter\Model;

class TestAttemptModel extends Model
{
    protected $table            = 'test_attempts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $allowedFields = [
        'test_id', 'user_id', 'attempt_number', 'status', 'cheat_strikes', 'score', 'comment', 
        'started_at', 'finished_at'
    ];

    protected $afterInsert = ['clearAttemptCache'];
    protected $afterUpdate = ['clearAttemptCacheAfterUpdate'];
    protected $afterDelete = ['clearAttemptCacheAfterDelete'];

    /** @var array<int, object> */
    private array $pendingDeleteCacheRows = [];

    /**
     * SEBELUM hapus, bukan sesudah.
     *
     * Versi lama memasang kait ini di afterDelete, lalu mencari barisnya
     * dengan SELECT. Barisnya sudah tidak ada pada saat itu (tabel ini tidak
     * memakai soft delete), jadi hasilnya selalu kosong dan tidak ada satu pun
     * kunci cache yang pernah terhapus.
     */
    protected $beforeDelete = ['clearAttemptCacheBeforeDelete'];


    /**
     * Get active or uncompleted attempt for a user and test
     */
    public function getActiveAttempt(int $testId, int $userId)
    {
        return $this->where('test_id', $testId)
                    ->where('user_id', $userId)
                    ->whereIn('status', [0, 1, 2]) // Not completed or locked
                    ->orderBy('id', 'DESC')
                    ->first();
    }

    /**
     * Get cached active or uncompleted attempt for a user and test
     */
    public function getActiveAttemptCached(int $testId, int $userId)
    {
        $cache = service('cache');
        $cacheKey = "active_attempt_{$testId}_{$userId}";
        $attempt = $cache->get($cacheKey);
        if ($attempt === null) {
            $attempt = $this->getActiveAttempt($testId, $userId);
            try {
                $cache->save($cacheKey, $attempt ?: false, 300); // 5 minutes
            } catch (\Exception $e) {}
        }
        return $attempt === false ? null : $attempt;
    }

    /**
     * Find an attempt by ID and cache it
     */
    public function findCached(int $id)
    {
        $cache = service('cache');
        $cacheKey = "attempt_{$id}";
        $attempt = $cache->get($cacheKey);
        if ($attempt === null) {
            $attempt = $this->find($id);
            if ($attempt) {
                try {
                    $cache->save($cacheKey, $attempt, 3600); // 1 hour
                } catch (\Exception $e) {}
            }
        }
        return $attempt;
    }

    protected function clearAttemptCache(array $data)
    {
        if (isset($data['data']['test_id']) && isset($data['data']['user_id'])) {
            $testId = $data['data']['test_id'];
            $userId = $data['data']['user_id'];
            $this->clearCacheForAttempt($data['id'] ?? null, $testId, $userId);
        }
        return $data;
    }

    protected function clearAttemptCacheAfterUpdate(array $data)
    {
        if (isset($data['id'])) {
            $ids = is_array($data['id']) ? $data['id'] : [$data['id']];
            foreach ($ids as $id) {
                $attempt = $this->db->table($this->table)->select('test_id, user_id')->where('id', $id)->get()->getRow();
                if ($attempt) {
                    $this->clearCacheForAttempt($id, $attempt->test_id, $attempt->user_id);
                }
            }
        }
        return $data;
    }

    protected function clearAttemptCacheBeforeDelete(array $data)
    {
        $this->pendingDeleteCacheRows = [];
        if (isset($data['id'])) {
            $ids = is_array($data['id']) ? $data['id'] : [$data['id']];
            $this->pendingDeleteCacheRows = $this->findCacheRowsWhereIn('id', $ids);
            $this->clearCacheRows($this->pendingDeleteCacheRows);
        }

        return $data;
    }

    protected function clearAttemptCacheAfterDelete(array $data)
    {
        // Hapus lagi sesudah delete sukses. Request paralel bisa membaca baris
        // di antara invalidasi pertama dan commit lalu mengisi cache kembali.
        $this->clearCacheRows($this->pendingDeleteCacheRows, true);
        $this->pendingDeleteCacheRows = [];

        return $data;
    }

    /**
     * Bersihkan cache setiap attempt yang cocok, SEBELUM barisnya dihapus.
     *
     * Wajib dipanggil oleh setiap penghapusan yang lewat query builder mentah.
     * Lima jalur melakukannya — hapus hasil ujian, reset seluruh sesi siswa,
     * reset satu attempt, hapus ujian, dan bersih-bersih pointer pengguna —
     * dan tidak satu pun melewati Model, jadi kait model tidak pernah jalan.
     * Yang tertinggal bukan sekadar data basi: `active_attempt_*` bertahan 5
     * menit dan `attempt_*` satu jam penuh, jadi "Reset Ujian Siswa" tampak
     * berhasil sementara siswa tetap melanjutkan attempt yang sudah dihapus.
     *
     * @param string       $field  kolom penyaring: 'id', 'user_id', atau 'test_id'
     * @param array<int>|int $values
     */
    public function clearCacheWhereIn(string $field, $values): void
    {
        $this->clearCacheRows($this->findCacheRowsWhereIn($field, $values));
    }

    /**
     * @param array<int>|int $values
     * @return array<int, object>
     */
    private function findCacheRowsWhereIn(string $field, $values): array
    {
        if (!in_array($field, ['id', 'user_id', 'test_id'], true)) {
            throw new \InvalidArgumentException('Kolom attempt cache tidak didukung: ' . $field);
        }

        $values = is_array($values) ? $values : [$values];
        $values = array_values(array_filter(array_map('intval', $values), static fn ($value) => $value > 0));
        if ($values === []) {
            return [];
        }

        try {
            return $this->db->table($this->table)
                ->select('id, test_id, user_id')
                ->whereIn($field, $values)
                ->get()
                ->getResult();
        } catch (\Throwable $e) {
            log_message('error', 'Gagal membaca attempt untuk pembersihan cache: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @param array<int, object> $rows
     */
    private function clearCacheRows(array $rows, bool $clearRealtimeAnswers = false): void
    {
        foreach ($rows as $row) {
            $this->clearCacheForAttempt($row->id, $row->test_id, $row->user_id);
            if ($clearRealtimeAnswers) {
                $this->clearRealtimeAnswers((int) $row->id);
            }
        }
    }

    private function clearRealtimeAnswers(int $attemptId): void
    {
        if ($attemptId <= 0) {
            return;
        }

        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->del("exam_answers:{$attemptId}");
            }
        } catch (\Throwable $e) {
            log_message('error', 'Gagal menghapus jawaban realtime attempt: ' . $e->getMessage());
        }
    }

    public function clearCacheForAttempt($attemptId, $testId, $userId)
    {
        $cache = service('cache');
        try {
            if ($testId && $userId) {
                $cache->delete("active_attempt_{$testId}_{$userId}");
            }
            if ($attemptId) {
                $cache->delete("attempt_{$attemptId}");
                $cache->delete("attempt_questions_{$attemptId}");
                $cache->delete("attempt_answers_{$attemptId}");
            }
        } catch (\Exception $e) {
            // Ignore cache driver issues
        }
    }
}
