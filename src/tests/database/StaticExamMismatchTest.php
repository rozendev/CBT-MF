<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use App\Models\TestModel;
use App\Models\QuestionModel;
use App\Models\AnswerModel;
use App\Libraries\ExamService;

/**
 * @internal
 */
final class StaticExamMismatchTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    public function testStaticExamQuestionAlignment(): void
    {
        $db = \Config\Database::connect();
        
        // Ensure clean state from any previous failed runs
        $db->query("SET FOREIGN_KEY_CHECKS = 0;");
        $db->table('users')->where('id', 1)->delete();
        $db->table('modules')->where('id', 1)->delete();
        $db->table('subjects')->where('name', 'Test Subject')->delete();
        $db->table('tests')->where('name', 'Static Test Mismatch Verification')->delete();
        $db->query("SET FOREIGN_KEY_CHECKS = 1;");
        
        // 0. Create a dummy user
        $db->table('users')->insert([
            'id' => 1,
            'username' => 'dummy_admin',
            'email' => 'dummy@admin.com',
            'password' => 'password',
            'role' => 'admin',
            'firstname' => 'Dummy',
            'lastname' => 'Admin',
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 0.5. Create a dummy module
        $db->table('modules')->insert([
            'id' => 1,
            'name' => 'Default Module',
            'is_enabled' => 1,
            'user_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 1. Create a dummy test
        $testId = $db->table('tests')->insert([
            'name' => 'Static Test Mismatch Verification',
            'description' => 'Test Description',
            'duration_minutes' => 60,
            'max_score' => 100,
            'passing_score' => 60,
            'is_enabled' => 1,
            'exam_mode' => 'static',
            'random_questions' => 0,
            'random_answers' => 0,
            'user_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $testId = $db->insertID();

        // 2. Create a subject
        $db->table('subjects')->insert([
            'module_id' => 1, // Default module has ID 1
            'name' => 'Test Subject',
            'user_id' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $subjectId = $db->insertID();

        // 3. Create several questions
        $questionIds = [];
        for ($i = 1; $i <= 10; $i++) {
            $db->table('questions')->insert([
                'subject_id' => $subjectId,
                'description' => "Question Number {$i}",
                'type' => 1, // Single choice
                'difficulty' => 1,
                'is_enabled' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $questionIds[] = $db->insertID();
        }

        // 4. Create subject set for the test
        $db->table('test_subject_sets')->insert([
            'test_id' => $testId,
            'question_type' => 0, // All
            'difficulty' => 0,    // All
            'quantity' => 5,      // Pick 5 out of 10
            'num_answers' => 4,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $setId = $db->insertID();

        $db->table('test_subjects')->insert([
            'test_subject_set_id' => $setId,
            'subject_id' => $subjectId,
        ]);

        // 5. Simulate question selection for Static page (same as StaticExamController)
        $sets = $db->table('test_subject_sets')->where('test_id', $testId)->get()->getResult();
        $staticSelectedIds = [];
        foreach ($sets as $set) {
            $subjects = $db->table('test_subjects')->where('test_subject_set_id', $set->id)->get()->getResultArray();
            $subIds = array_column($subjects, 'subject_id');
            
            $qBuilder = $db->table('questions')
                           ->select('id')
                           ->whereIn('subject_id', $subIds)
                           ->where('is_enabled', 1)
                           ->orderBy('id', 'ASC');

            $questionRows = $qBuilder->get()->getResult();
            $qIds = array_column($questionRows, 'id');

            mt_srand($testId + $set->id);
            shuffle($qIds);
            mt_srand();

            $selected = array_slice($qIds, 0, $set->quantity);
            $staticSelectedIds = array_merge($staticSelectedIds, $selected);
        }

        // 6. Generate attempt using ExamService
        $examService = new ExamService();
        $attempt = $examService->generateAttempt($testId, 1, '127.0.0.1'); // user_id = 1
        $this->assertNotNull($attempt);

        // Fetch logs
        $logs = $db->table('test_logs')
                   ->where('test_attempt_id', $attempt->id)
                   ->orderBy('display_order', 'ASC')
                   ->get()
                   ->getResult();

        $attemptQuestionIds = array_column($logs, 'question_id');

        // 7. Verify they match EXACTLY in quantity, identity, and order!
        $this->assertCount(count($staticSelectedIds), $attemptQuestionIds);
        $this->assertEquals($staticSelectedIds, $attemptQuestionIds, 'Static generated questions do not match attempt database questions!');

        // Cleanup
        $db->table('test_logs')->where('test_attempt_id', $attempt->id)->delete();
        $db->table('test_attempts')->where('id', $attempt->id)->delete();
        $db->table('test_subjects')->where('test_subject_set_id', $setId)->delete();
        $db->table('test_subject_sets')->where('id', $setId)->delete();
        foreach ($questionIds as $qId) {
            $db->table('questions')->where('id', $qId)->delete();
        }
        $db->table('subjects')->where('id', $subjectId)->delete();
        $db->table('tests')->where('id', $testId)->delete();
        $db->table('modules')->where('id', 1)->delete();
        $db->table('users')->where('id', 1)->delete();
    }

    public function testApiRateLimitFilter(): void
    {
        $request = \Config\Services::request();
        $filter = new \App\Filters\ApiRateLimitFilter();
        
        // Mock session user ID
        $session = \Config\Services::session();
        $session->set('user_id', 9999);
        
        $redis = \App\Libraries\RedisClient::getInstance();
        if ($redis) {
            $redis->del("api_rate_limit:user:9999");
        }
        
        // 1. Call before() 30 times (should return null - allowed)
        for ($i = 0; $i < 30; $i++) {
            $res = $filter->before($request);
            $this->assertNull($res);
        }
        
        // 2. The 31st call should return a Response object (HTTP 429)
        $res = $filter->before($request);
        $this->assertInstanceOf(\CodeIgniter\HTTP\ResponseInterface::class, $res);
        $this->assertEquals(429, $res->getStatusCode());
        
        // Cleanup Redis key
        if ($redis) {
            $redis->del("api_rate_limit:user:9999");
        }
    }
}
