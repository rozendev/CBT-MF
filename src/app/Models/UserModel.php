<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    protected $allowedFields = [
        'username', 'email', 'password', 'role',
        'firstname', 'lastname', 'birthdate', 'birthplace',
        'registration_number', 'ssn', 'is_active',
        'last_login_at', 'last_login_ip',
        'login_attempts', 'locked_until',
    ];

    protected $validationRules = [
        'username' => 'required|min_length[3]|max_length[100]|is_unique[users.username,id,{id}]',
        'password' => 'required|min_length[6]',
        'role'     => 'required|in_list[admin,guru,siswa]',
    ];

    protected $validationMessages = [
        'username' => [
            'required'  => 'Username wajib diisi.',
            'min_length' => 'Username minimal 3 karakter.',
            'is_unique' => 'Username sudah digunakan.',
        ],
        'password' => [
            'required'   => 'Password wajib diisi.',
            'min_length' => 'Password minimal 6 karakter.',
        ],
    ];

    protected $beforeInsert = ['hashPassword'];
    protected $beforeUpdate = ['hashPassword'];

    /**
     * Hash password before insert/update using Argon2ID
     */
    protected function hashPassword(array $data): array
    {
        if (isset($data['data']['password'])) {
            $password = $data['data']['password'];
            // Don't re-hash if already hashed
            if (!str_starts_with($password, '$argon2id$') && !str_starts_with($password, '$2y$')) {
                $data['data']['password'] = password_hash($password, PASSWORD_ARGON2ID);
            }
        }
        return $data;
    }

    /**
     * Verify a plain-text password against stored hash
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if user account is currently locked
     */
    public function isLocked(object $user): bool
    {
        if (empty($user->locked_until)) {
            return false;
        }
        return strtotime($user->locked_until) > time();
    }

    /**
     * Increment failed login attempts
     */
    public function incrementLoginAttempts(int $userId): void
    {
        $this->set('login_attempts', 'login_attempts + 1', false)
             ->where('id', $userId)
             ->update();
    }

    /**
     * Reset login attempts after successful login
     */
    public function resetLoginAttempts(int $userId): void
    {
        $this->update($userId, [
            'login_attempts' => 0,
            'locked_until'   => null,
        ]);
    }

    /**
     * Lock account for specified minutes
     */
    public function lockAccount(int $userId, int $minutes = 15): void
    {
        $this->update($userId, [
            'locked_until' => date('Y-m-d H:i:s', strtotime("+{$minutes} minutes")),
        ]);
    }

    /**
     * Record successful login metadata
     */
    public function recordLogin(int $userId, string $ip): void
    {
        $this->update($userId, [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
            'login_attempts' => 0,
            'locked_until'   => null,
        ]);
    }

    /**
     * Find user by username
     */
    public function getByUsername(string $username): ?object
    {
        return $this->where('username', $username)->first();
    }

    /**
     * Get users belonging to a group
     */
    public function getUsersInGroup(int $groupId): array
    {
        return $this->select('users.*')
                    ->join('user_groups', 'user_groups.user_id = users.id')
                    ->where('user_groups.group_id', $groupId)
                    ->findAll();
    }

    /**
     * Get user count by role
     */
    public function countByRole(string $role): int
    {
        return $this->where('role', $role)->countAllResults();
    }
}
