<?php

namespace App\Models;

use CodeIgniter\Model;

class GroupModel extends Model
{
    protected $table            = 'groups';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    protected $allowedFields = [
        'name', 'description', 'is_active',
    ];

    protected $validationRules = [
        'name' => 'required|max_length[255]|is_unique[groups.name,id,{id}]',
    ];

    /**
     * Get groups a user belongs to
     */
    public function getUserGroups(int $userId): array
    {
        return $this->select('groups.*')
                    ->join('user_groups', 'user_groups.group_id = groups.id')
                    ->where('user_groups.user_id', $userId)
                    ->findAll();
    }

    /**
     * Add user to group
     */
    public function addUserToGroup(int $userId, int $groupId): bool
    {
        $db = \Config\Database::connect();
        $existing = $db->table('user_groups')
                       ->where('user_id', $userId)
                       ->where('group_id', $groupId)
                       ->countAllResults();

        if ($existing > 0) {
            return true;
        }

        return $db->table('user_groups')->insert([
            'user_id'    => $userId,
            'group_id'   => $groupId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Remove user from group
     */
    public function removeUserFromGroup(int $userId, int $groupId): bool
    {
        $db = \Config\Database::connect();
        return $db->table('user_groups')
                  ->where('user_id', $userId)
                  ->where('group_id', $groupId)
                  ->delete();
    }

    /**
     * Get member count for a group
     */
    public function getMemberCount(int $groupId): int
    {
        $db = \Config\Database::connect();
        return $db->table('user_groups')
                  ->where('group_id', $groupId)
                  ->countAllResults();
    }
}
