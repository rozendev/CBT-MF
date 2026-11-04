<?php
declare(strict_types=1);
// Run locally: docker compose exec -T php php tests/Exam/cheat-flow.integration.php
// Uses connection-local TEMPORARY tables and a Redis spy; no real student rows.
namespace App\Libraries {
    // Deliberately no network Redis: capture terminal events in memory.
    final class RedisClient {
        public static object $spy;
        public static function getInstance(): object { return self::$spy; }
    }
}
namespace {
    define('ENVIRONMENT', 'production');
    define('FCPATH', '/var/www/html/public/');
    require '/var/www/html/app/Config/Paths.php';
    $paths = new Config\Paths();
    require $paths->systemDirectory . '/Boot.php';
    CodeIgniter\Boot::bootConsole($paths);
    // Optional source overlay lets a dev container test an isolated worktree.
    $source = getenv('CBT_TEST_SOURCE') ?: '/var/www/html';
    require_once $source . '/app/Libraries/ExamService.php';
    Config\Services::injectMock('cache', new CodeIgniter\Cache\Handlers\DummyHandler(new Config\Cache()));
    $testSession = new class {
        public bool $destroyed = false;
        public function get($key) { return $key === 'user_id' ? 900001 : null; }
        public function destroy(): void { $this->destroyed = true; }
    };
    Config\Services::injectMock('session', $testSession);
    $redis = new class {
        public array $kv = [], $events = [];
        public function get($key) { return $this->kv[$key] ?? false; }
        public function setex($key, $ttl, $value) { $this->kv[$key] = $value; return true; }
        public function del(...$keys) { foreach ($keys as $key) unset($this->kv[$key]); return 1; }
        public function hGetAll($key) { return []; }
        public function scan(&$iterator, ...$args) { $iterator = 0; return false; }
        public function publish($channel, $value) { $this->events[] = json_decode($value, true); return 1; }
    };
    App\Libraries\RedisClient::$spy = $redis;
    $db = Config\Database::connect();
    (new ReflectionProperty($db, 'DBDebug'))->setValue($db, false);
    // Connection-local TEMPORARY tables shadow real tables. No real rows touched.
    foreach (['users','tests','test_attempts','test_logs','test_log_answers','activity_logs','settings'] as $table) {
        $schema = $db->query("SHOW CREATE TABLE `$table`")->getRowArray()['Create Table'];
        $schema = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $schema);
        $schema = preg_replace('/^  CONSTRAINT .*FOREIGN KEY.*\R/m', '', $schema);
        $schema = preg_replace('/,\R\)/', "\n)", $schema);
        if ($db->query($schema) === false) throw new RuntimeException('Cannot isolate '.$table.': '.json_encode($db->error()));
    }
    $uid = 900001; $tid = 900001; $aid = 900001;
    $db->table('users')->insert(['id'=>$uid,'username'=>'diagnosis_fixture','password'=>password_hash('fixture-only-not-a-real-password', PASSWORD_BCRYPT, ['cost'=>4]),'firstname'=>'Fixture','role'=>'siswa','is_active'=>1]);
    $db->table('tests')->insert(['id'=>$tid,'name'=>'Diagnosis fixture','user_id'=>$uid,'duration_minutes'=>60,'begin_time'=>date('Y-m-d H:i:s'),'is_enabled'=>1,'auto_submit_on_cheat'=>0]);
    $db->table('test_attempts')->insert(['id'=>$aid,'test_id'=>$tid,'user_id'=>$uid,'status'=>1,'cheat_strikes'=>0,'started_at'=>date('Y-m-d H:i:s',time()-60)]);
    foreach (['anti_cheat_enabled'=>1,'anti_cheat_force_logout'=>0,'max_cheat_strikes'=>3,'suspend_timer_seconds'=>30] as $key=>$value) {
        $db->table('settings')->insert(['key'=>$key,'value'=>(string)$value,'type'=>in_array($key,['anti_cheat_enabled','anti_cheat_force_logout'])?'boolean':'integer','group'=>'security']);
    }
    $failures = 0; $checks = 0;
    $requestFor = function(array $post) {
        $request = new CodeIgniter\HTTP\IncomingRequest(new Config\App(), new CodeIgniter\HTTP\SiteURI(new Config\App(), 'login'), null, new CodeIgniter\HTTP\UserAgent());
        $request->setMethod('POST');
        $request->setHeader('Accept','application/json');
        $request->setGlobal('post',$post);
        // CI Controller::validate() reads getVar()/REQUEST, while login reads POST.
        $request->setGlobal('request',$post);
        Config\Services::injectMock('request',$request);
        return $request;
    };
    $expect = function($actual, $expected, string $label) use (&$checks): void {
        $checks++;
        if ($actual !== $expected) throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    };
    $run = function(string $label,string $type,int $age,int $strikes,callable $verify) use ($db,$redis,$aid,$uid,$testSession,&$failures): void {
        $db->resetTransStatus();
        $testSession->destroyed = false;
        $db->table('users')->where('id',$uid)->update(['is_active'=>1]);
        $db->table('test_attempts')->where('id',$aid)->update(['status'=>1,'score'=>null,'cheat_strikes'=>$strikes,'started_at'=>date('Y-m-d H:i:s',time()-$age)]);
        $redis->kv=[]; $redis->events=[];
        $result=(new App\Libraries\ExamService())->handleCheat($aid,$uid,$type);
        $state=$db->query('SELECT u.is_active,ta.status,ta.cheat_strikes FROM users u JOIN test_attempts ta ON ta.user_id=u.id WHERE ta.id=?',[$aid])->getRowArray();
        $state = array_map('intval', $state);
        try { $verify($result, $state, $redis, $testSession); echo "PASS $label\n"; }
        catch (Throwable $e) { $failures++; echo "FAIL $label: {$e->getMessage()}\n"; }
    };
    $warning = function($r,$s,$redis,$session) use ($expect) {
        $expect($r['action']??null,'suspend','warning action');
        $expect($s,['is_active'=>1,'status'=>1,'cheat_strikes'=>1],'warning state');
        $expect($redis->events,[],'no terminal event');
        $expect($session->destroyed,false,'session retained');
    };
    $run('warning after 60 seconds','tab_switch',60,0,$warning);
    $run('early tab switch follows counter','tab_switch',10,0,$warning);
    $run('integrity heuristic follows counter','modified_browser',10,0,$warning);
    $db->table('settings')->where('key','anti_cheat_enabled')->update(['value'=>'0']);
    foreach (['fullscreen_exit','modified_browser'] as $type) $run('anticheat disabled '.$type,$type,10,0,function($r,$s,$redis) use($expect) {
        $expect($r['action']??null,'none','disabled action');
        $expect($s,['is_active'=>1,'status'=>1,'cheat_strikes'=>0],'disabled state');
        $expect($redis->events,[],'disabled events');
    });
    $db->table('settings')->where('key','anti_cheat_enabled')->update(['value'=>'1']);
    $ban = function($r,$s,$redis,$session) use ($expect,$uid,$requestFor) {
        $expect($r['action']??null,'lock','ban action');
        $expect($s['is_active'],0,'ban persists inactive account');
        $expect($s['status'],2,'attempt locked');
        $expect($redis->kv['user_login_token:'.$uid]??null,'BANNED','ban sentinel');
        $expect($redis->events[0]['event']??null,'ban','ban notification');
        $expect($session->destroyed,true,'ban ends session');
        $auth = new App\Controllers\Auth\AuthController();
        $auth->initController($requestFor(['username'=>'diagnosis_fixture','password'=>'fixture-only-not-a-real-password']),new CodeIgniter\HTTP\Response(new Config\App()),service('logger'));
        $login = $auth->attemptLogin();
        $expect($login->getStatusCode(),401,'login denied after committed ban');
        $expect(json_decode($login->getBody(),true)['message']??null,'Akun Anda telah dinonaktifkan. Hubungi administrator.','inactive guard denies valid credentials');
    };
    $run('threshold commits ban','tab_switch',60,2,$ban);
    $db->table('settings')->where('key','anti_cheat_force_logout')->update(['value'=>'1']);
    $run('explicit force logout bans on first strike','tab_switch',60,0,$ban);
    $db->table('settings')->where('key','anti_cheat_force_logout')->update(['value'=>'0']);
    $db->table('tests')->where('id',$tid)->update(['auto_submit_on_cheat'=>1]);
    $run('auto-submit precedes integrity counter','modified_browser',10,0,function($r,$s,$redis)use($expect){
        $expect($r['action']??null,'auto_submitted','auto-submit action');
        $expect($s['is_active'],1,'auto-submit retains account');
        $expect($s['status'],3,'auto-submit finishes attempt');
        $expect($redis->kv['user_login_token:900001']??null,null,'no ban sentinel');
    });
    $db->table('tests')->where('id',$tid)->update(['auto_submit_on_cheat'=>0]);
    $db->table('users')->where('id',$uid)->update(['role'=>'admin']);
    $run('admin bypass','modified_browser',10,0,function($r,$s,$redis)use($expect){
        $expect($r['action']??null,'none','admin bypass action');
        $expect($s['cheat_strikes'],0,'admin count unchanged');
        $expect($redis->events,[],'admin has no ban events');
    });
    $db->table('users')->where('id',$uid)->update(['role'=>'siswa']);
    $db->table('test_attempts')->where('id',$aid)->update(['cheat_strikes'=>0]);
    $db->query('ALTER TABLE test_attempts ADD CONSTRAINT diagnosis_counter_check CHECK (cheat_strikes = 0)');
    $run('failed warning write is not reported as suspend','tab_switch',60,0,function($r,$s,$redis)use($expect){
        $expect($r['status']??null,'error','counter error response');
        $expect($s['cheat_strikes'],0,'failed count not persisted');
        $expect($redis->events,[],'failed warning no events');
    });
    $db->query('ALTER TABLE test_attempts DROP CONSTRAINT diagnosis_counter_check');
    $db->resetTransStatus();
    $failedBan = function($r,$s,$redis,$session)use($expect){
        $expect($r['status']??null,'error','failed write reports error');
        $expect($s,['is_active'=>1,'status'=>1,'cheat_strikes'=>2],'rollback state');
        $expect($redis->events,[],'no ghost ban events');
        $expect($redis->kv['user_login_token:900001']??null,null,'no ghost sentinel');
        $expect($session->destroyed,false,'failure retains session');
    };
    // Simulated storage failure: real SQL CHECK, confined to this temporary table.
    $db->table('users')->where('id',$uid)->update(['is_active'=>1]);
    if ($db->query('ALTER TABLE users ADD CONSTRAINT diagnosis_active_check CHECK (is_active = 1)') === false) throw new RuntimeException('Cannot inject SQL failure');
    $run('failed SQL does not publish ghost ban','tab_switch',60,2,$failedBan);
    $db->query('ALTER TABLE users DROP CONSTRAINT diagnosis_active_check');
    $db->resetTransStatus();
    $db->query('ALTER TABLE users DROP COLUMN auth_version, DROP COLUMN cred_version, DROP COLUMN cred_grace_until');
    $run('missing auth columns do not publish ghost ban','tab_switch',60,2,$failedBan);
    $expect(App\Libraries\SessionTakeover::decide('BANNED',null,''),'clear_banned','active account can replace stale sentinel');
    foreach (['Student/ExamController'=>'App\\Controllers\\Student\\ExamController','Api/ExamApiController'=>'App\\Controllers\\Api\\ExamApiController'] as $path=>$class) {
        require_once $source.'/app/Controllers/'.$path.'.php';
        $db->resetTransStatus(); $redis->events=[];
        $controller = new $class();
        $controller->initController($requestFor(['attempt_id'=>$aid,'type'=>'tab_switch']),new CodeIgniter\HTTP\Response(new Config\App()),service('logger'));
        $response = $controller->reportCheat();
        try {
            $expect($response->getStatusCode(),503,$class.' failed-write HTTP status');
            $expect($redis->events,[],$class.' no ghost event');
            echo "PASS $class returns HTTP 503 on storage failure\n";
        } catch (Throwable $e) { $failures++; echo 'FAIL '.$e->getMessage()."\n"; }
    }
    $db->resetTransStatus();
    $db->table('users')->where('id',$uid)->update(['is_active'=>0]);
    $auth = new App\Controllers\Auth\AuthController();
    $auth->initController($requestFor(['username'=>'diagnosis_fixture','password'=>'fixture-only-not-a-real-password']),new CodeIgniter\HTTP\Response(new Config\App()),service('logger'));
    $login = $auth->attemptLogin();
    try { $expect($login->getStatusCode(),401,'inactive account login denied'); echo "PASS inactive account rejected by AuthController\n"; }
    catch(Throwable $e){ $failures++;echo 'FAIL '.$e->getMessage()."\n"; }
    echo "Checks: $checks; failed scenarios: $failures\n";
    exit($failures > 0 ? 1 : 0);
}
