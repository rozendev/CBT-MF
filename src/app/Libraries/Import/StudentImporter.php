<?php
namespace App\Libraries\Import;

use App\Models\UserModel;
use App\Models\GroupModel;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class StudentImporter
{
    private \Closure $writer;

    public function __construct(?callable $writer = null, private int $pauseUs = 50000)
    {
        $this->writer = $writer === null ? $this->persist(...) : \Closure::fromCallable($writer);
    }

    public function process(array $job, callable $checkpoint): array
    {
        ImportUpload::checkArchive($job['source']);
        try {
            $reader = IOFactory::createReaderForFile($job['source']);
            $reader->setReadDataOnly(false)->setReadEmptyCells(false);
            foreach ($reader->listWorksheetInfo($job['source']) as $info) {
                if ($info['totalRows'] > 10001) throw new ImportFailure('Maksimal 10.000 baris siswa per file.');
                if ($info['totalColumns'] > 100) throw new ImportFailure('Maksimal 100 kolom per lembar Excel.');
            }
            $workbook = $reader->load($job['source']);
        } catch (ImportFailure $error) { throw $error; }
        catch (\Throwable $error) { throw new ImportFailure('File Excel tidak dapat dibaca. Periksa format dan isinya.'); }

        try {
            $sheet = $workbook->getActiveSheet();
            $lastRow = $sheet->getHighestDataRow();
            $progress = array_merge(['cursor' => 1, 'processed' => 0, 'success' => 0, 'duplicates' => 0, 'failed' => 0], $job['progress']);
            $progress['total'] = max(0, $lastRow - 1);
            $checkpoint($progress);
            for ($index = max(2, $progress['cursor'] + 1); $index <= $lastRow; $index++) {
                $values = [];
                foreach (['A', 'B', 'C', 'D', 'E'] as $column) $values[] = trim((string) $sheet->getCell($column . $index)->getFormattedValue());
                [$username, $password, $firstname, $lastname, $email] = $values;
                if ($username !== '' && $password !== '' && $firstname !== '') {
                    try {
                        $outcome = ($this->writer)([
                            'username' => $username, 'password' => $password, 'firstname' => $firstname,
                            'lastname' => $lastname, 'email' => $email === '' ? null : $email,
                            'role' => 'siswa', 'is_active' => 1,
                        ], (int) ($job['options']['group_id'] ?? 0));
                        $progress[$outcome === 'duplicate' ? 'duplicates' : 'success']++;
                    } catch (\Throwable $error) {
                        if ($error instanceof \Error) throw $error;
                        $failure = ImportFailure::from($error);
                        if ($failure->retryable) throw $failure;
                        $progress['failed']++;
                    }
                } elseif (implode('', $values) !== '') { $progress['failed']++; }
                $progress['cursor'] = $index;
                $progress['processed']++;
                $checkpoint($progress);
                if ($this->pauseUs > 0) usleep($this->pauseUs);
            }
            return ['status' => 'success', 'success' => $progress['success'], 'duplicates' => $progress['duplicates'], 'failed' => $progress['failed'], 'total' => $progress['total']];
        } finally { $workbook->disconnectWorksheets(); }
    }

    private function persist(array $data, int $groupId): string
    {
        $db = \Config\Database::connect();
        $users = new UserModel();
        $groups = new GroupModel();
        if ($groupId && !$groups->find($groupId)) throw new ImportFailure('Grup tujuan sudah tidak tersedia.');
        $existing = $users->withDeleted()->where('username', $data['username'])->first();
        if ($existing && empty($existing->deleted_at)) return 'duplicate';
        // Expensive hashing happens outside the DB transaction, using the unchanged core algorithm.
        $data['password'] = $users->hashPasswordManually($data['password']);
        $db->resetTransStatus()->transException(true);
        if (!$db->transBegin()) throw new ImportFailure('Database sementara tidak tersedia.', true);
        try {
            if ($existing) {
                $id = (int) $existing->id;
                $saved = $users->builder()->where('id', $id)->update($data + ['deleted_at' => null, 'updated_at' => date('Y-m-d H:i:s')]);
                if ($groupId) $db->table('user_groups')->where('user_id', $id)->delete();
            } else {
                $saved = $users->skipValidation(true)->insert($data);
                $id = (int) $users->getInsertID();
            }
            if (!$saved || ($groupId && !$groups->addUserToGroup($id, $groupId)) || !$db->transStatus()) {
                throw new ImportFailure('Data siswa tidak dapat disimpan.');
            }
            if (!$db->transCommit()) throw new ImportFailure('Transaksi impor gagal disimpan.', true);
            return 'success';
        } catch (\Throwable $error) {
            $db->transRollback();
            // A normal admin create can race the import; never overwrite the active account.
            if ((int) $error->getCode() === 1062) return 'duplicate';
            throw $error;
        }
    }
}
