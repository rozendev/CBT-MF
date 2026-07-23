<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use App\Models\UserModel;
use App\Models\GroupModel;

/**
 * @internal
 */
final class UserSoftDeleteAndReuseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations manually on tests group for App namespace
        $migrations = \Config\Services::migrations();
        $migrations->setGroup('tests');
        $migrations->setNamespace('App');
        $migrations->latest();
    }

    public function testSoftDeleteAndReuse(): void
    {
        $userModel = new UserModel();
        $groupModel = new GroupModel();
        
        $db = $this->db;

        // Ensure clean state
        $db->query("SET FOREIGN_KEY_CHECKS = 0;");
        $db->table('groups')->truncate();
        $db->table('user_groups')->truncate();
        $db->table('test_attempts')->truncate();
        $db->query("SET FOREIGN_KEY_CHECKS = 1;");
        $db->table('users')->where('username', 'teststudent123')->delete();

        // 1. Create a group
        $groupId = $db->table('groups')->insert([
            'name' => 'Test Group',
            'description' => 'Test Group Description',
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 2. Create user data
        $userData = [
            'username' => 'teststudent123',
            'password' => 'password123',
            'firstname' => 'Test',
            'lastname' => 'Student',
            'email' => 'student@test.com',
            'role' => 'siswa',
            'is_active' => 1
        ];

        // 3. Insert user manually simulating UserController::store()
        // If not exists (standard path)
        $userModel->skipValidation(true)->insert($userData);
        $userId = $userModel->getInsertID();
        $groupModel->addUserToGroup($userId, $groupId);

        // Verify creation and group membership
        $user = $userModel->find($userId);
        $this->assertNotNull($user);
        $this->assertEquals('teststudent123', $user->username);
        $this->assertEquals(1, $db->table('user_groups')->where('user_id', $userId)->countAllResults());

        // 4. Test duplicate validation rule on active user (should fail)
        $rules = $userModel->getValidationRules();
        $rules['username'] = "required|min_length[3]|max_length[100]|is_unique[users.active_username,id,{id}]";
        
        // Simulating CodeIgniter's validation logic
        $validation = \Config\Services::validation();
        $validation->setRules($rules);
        $this->assertFalse($validation->run(['username' => 'teststudent123', 'password' => 'newpassword', 'role' => 'siswa']));

        // 5. Delete user simulating UserController::delete()
        $userModel->cleanPointers($userId);
        $userModel->delete($userId);

        // Verify soft-deleted
        $this->assertNull($userModel->find($userId));
        $deletedUser = $userModel->withDeleted()->find($userId);
        $this->assertNotNull($deletedUser->deleted_at);

        // Verify group relationship is cleaned up (pointer is cleared)
        $this->assertEquals(0, $db->table('user_groups')->where('user_id', $userId)->countAllResults());

        $validation->reset();
        $validation->setRules($rules);
        $result = $validation->run(['username' => 'teststudent123', 'password' => 'newpassword', 'role' => 'siswa']);
        $this->assertTrue($result);

        // 7. Store new student with SAME username (simulating reuse logic in UserController::store())
        $newUserData = [
            'username' => 'teststudent123',
            'password' => 'newpassword123',
            'firstname' => 'Overridden',
            'lastname' => 'User',
            'email' => 'overridden@test.com',
            'role' => 'siswa',
            'is_active' => 1
        ];

        $reusedUser = $userModel->findDeletedByUsername($newUserData['username']);
        $this->assertNotNull($reusedUser);
        $this->assertEquals($userId, $reusedUser->id); // Must be the same ID!

        // Perform reuse
        $success = $userModel->reuseDeletedUser($reusedUser->id, $newUserData);
        $this->assertTrue($success);

        // Add to group
        $groupModel->addUserToGroup($reusedUser->id, $groupId);

        // Verify restored user
        $restoredUser = $userModel->find($userId);
        $this->assertNotNull($restoredUser);
        $this->assertNull($restoredUser->deleted_at);
        $this->assertEquals('Overridden', $restoredUser->firstname);
        $this->assertEquals('overridden@test.com', $restoredUser->email);
        $this->assertTrue(password_verify('newpassword123', $restoredUser->password));

        // Cleanup
        $db->table('user_groups')->where('user_id', $userId)->delete();
        $db->table('users')->where('id', $userId)->delete();
    }
}
