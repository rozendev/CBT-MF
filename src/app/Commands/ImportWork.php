<?php
namespace App\Commands;

use App\Libraries\Import\ImportQueue;
use App\Libraries\Import\StudentImporter;
use App\Libraries\Import\WordImporter;
use App\Models\ActivityLogModel;
use CodeIgniter\CLI\BaseCommand;

final class ImportWork extends BaseCommand
{
    protected $group = 'Tools';
    protected $name = 'imports:work';
    protected $description = 'Process queued admin imports, one at a time, outside PHP-FPM.';
    protected $usage = 'imports:work [--once]';
    protected $options = ['--once' => 'Process at most one ready job, then exit.'];

    public function run(array $params)
    {
        $queue = new ImportQueue();
        // One fresh PHP process per job: a lost DB connection or parser allocation
        // cannot poison later imports. The Compose supervisor schedules invocations.
        $queue->runOne(static function (array $job, callable $checkpoint): array {
            $importer = $job['kind'] === 'students' ? new StudentImporter() : new WordImporter();
            $result = $importer->process($job, $checkpoint);
            if (($result['status'] ?? '') === 'success') {
                try { (new ActivityLogModel())->log('import', $job['owner'], $job['kind'], 0, 'Impor latar belakang selesai: ' . $job['job_id']); }
                catch (\Throwable $error) { log_message('error', 'Import activity log unavailable: {type}', ['type' => get_class($error)]); }
            }
            return $result;
        });
        return EXIT_SUCCESS;
    }
}
