<?php

namespace App\Models;

use CodeIgniter\Model;

class ActivityLogModel extends Model
{
    protected $table            = 'activity_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'user_id', 'action', 'entity_type', 'entity_id',
        'description', 'ip_address', 'user_agent', 'created_at',
    ];

    /**
     * Log an activity
     */
    public function log(
        string $action,
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $description = null
    ): void {
        $request = service('request');

        $this->insert([
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'description' => $description,
            'ip_address'  => $request->getIPAddress(),
            'user_agent'  => (string) $request->getUserAgent(),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get recent activities
     */
    public function getRecent(int $limit = 20): array
    {
        return $this->select('activity_logs.*, users.username, users.firstname')
                    ->join('users', 'users.id = activity_logs.user_id', 'left')
                    ->orderBy('activity_logs.created_at', 'DESC')
                    ->limit($limit)
                    ->findAll();
    }
}
