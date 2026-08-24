<?php

namespace App\Models;

use CodeIgniter\Model;

class IntruderReportModel extends Model
{
    protected $table            = 'intruder_reports';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'photo_path', 'latitude', 'longitude', 'accuracy',
        'ip_address', 'user_agent', 'requested_uri', 'referer',
        'screen', 'platform', 'created_at',
    ];

    public function record(array $data): int
    {
        $this->insert([
            'photo_path'   => $data['photo_path'] ?? null,
            'latitude'     => $data['latitude'] ?? null,
            'longitude'    => $data['longitude'] ?? null,
            'accuracy'     => $data['accuracy'] ?? null,
            'ip_address'   => $data['ip_address'] ?? null,
            'user_agent'   => $data['user_agent'] ?? null,
            'requested_uri' => $data['requested_uri'] ?? null,
            'referer'      => $data['referer'] ?? null,
            'screen'       => $data['screen'] ?? null,
            'platform'     => $data['platform'] ?? null,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->insertID();
    }
}
