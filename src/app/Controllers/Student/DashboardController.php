<?php

namespace App\Controllers\Student;

use App\Controllers\BaseController;
use App\Models\TestModel;

class DashboardController extends BaseController
{
    public function index()
    {
        $userId = session('user_id');
        $db = \Config\Database::connect();
        
        $cache = \Config\Services::cache();
        $cacheKey = 'student_dashboard_tests_' . $userId;
        
        $availableTests = $cache->get($cacheKey);

        if ($availableTests === null) {
            $now = date('Y-m-d H:i:s');
            
            $sql = "
                SELECT DISTINCT t.*,
                       (SELECT status FROM test_attempts ta WHERE ta.test_id = t.id AND ta.user_id = ? ORDER BY ta.id DESC LIMIT 1) as attempt_status
                FROM tests t
                JOIN test_groups tg ON tg.test_id = t.id
                JOIN user_groups ug ON ug.group_id = tg.group_id
                WHERE ug.user_id = ?
                  AND t.is_enabled = 1
                  AND t.deleted_at IS NULL
                ORDER BY t.created_at DESC
            ";
            
            $query = $db->query($sql, [$userId, $userId]);
            $availableTests = $query->getResult();

            $globals = \App\Libraries\ExamVisibility::globals();

            foreach ($availableTests as $t) {
                // results_visible tetap jadi lapis di antaranya: kolom itu
                // keputusan guru untuk ujian ini, jadi ia menang atas setelan
                // global meski show_score_after_exam belum diisi.
                if ($t->show_score_after_exam === null && isset($t->results_visible)) {
                    $t->can_show_score = (bool) $t->results_visible;
                } else {
                    $t->can_show_score = \App\Libraries\ExamVisibility::resolve(
                        $t->show_score_after_exam,
                        $globals['show_score']
                    );
                }

                $t->can_allow_review = \App\Libraries\ExamVisibility::resolve(
                    $t->allow_review,
                    $globals['allow_review']
                );
            }
            
            // Simpan ke cache selama 60 detik
            $cache->save($cacheKey, $availableTests, 60);
        }

        return view('student/dashboard', [
            'availableTests' => $availableTests
        ]);
    }
}
