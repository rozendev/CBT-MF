<?php

namespace Tests\Realtime;

use App\Libraries\WebSocketToken;
use PHPUnit\Framework\TestCase;

final class InMemoryRedisStub
{
    /** @var array<string, string> */
    public array $kv = [];

    /** @var array<string, int> */
    public array $ttls = [];

    public function get(string $key): string|false
    {
        return array_key_exists($key, $this->kv) ? $this->kv[$key] : false;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->kv[$key]   = $value;
        $this->ttls[$key] = $ttl;
        return true;
    }

    public function del(string ...$keys): int
    {
        $deleted = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->kv)) {
                unset($this->kv[$key], $this->ttls[$key]);
                $deleted++;
            }
        }
        return $deleted;
    }
}

class WebSocketTokenTest extends TestCase
{
    public function testStudentTokenMaintainsSingleActiveTokenPerAttemptAnd32HexFormat(): void
    {
        $redis = new InMemoryRedisStub();

        $first = WebSocketToken::issueStudentToken(10, 55, 3, 2, $redis, false);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $first);
        $this->assertSame($first, $redis->get('attempt_ws_token:55'));
        $this->assertSame(WebSocketToken::TTL_SECONDS, $redis->ttls['ws_student_token:' . $first]);

        $decodedFirst = json_decode((string) $redis->get('ws_student_token:' . $first), true);
        $this->assertSame([
            'user_id'      => 10,
            'attempt_id'   => 55,
            'test_id'      => 3,
            'auth_version' => 2,
        ], $decodedFirst);

        // Reloading take() or init() rotates the token and revokes the previous one
        $second = WebSocketToken::issueStudentToken(10, 55, 3, 2, $redis, false);
        $this->assertNotSame($first, $second);
        $this->assertFalse($redis->get('ws_student_token:' . $first), 'Old token must be deleted upon rotation');
        $this->assertSame($second, $redis->get('attempt_ws_token:55'));
        $this->assertNotFalse($redis->get('ws_student_token:' . $second));
    }

    public function testProctorTokenCarriesUserIdTestIdAndNormalizedAuthVersion(): void
    {
        $redis = new InMemoryRedisStub();

        $token = WebSocketToken::issueProctorToken(7, 12, 0, $redis, false);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $token);

        $decoded = json_decode((string) $redis->get('ws_proctor_token:' . $token), true);
        $this->assertSame([
            'user_id'      => 7,
            'test_id'      => 12,
            'auth_version' => 1,
        ], $decoded);
    }

    public function testRevokeUserAttemptTokensDeletesAllAttemptTokensAndReverseIndexes(): void
    {
        $redis = new InMemoryRedisStub();

        $t1 = WebSocketToken::issueStudentToken(10, 101, 3, 1, $redis, false);
        $t2 = WebSocketToken::issueStudentToken(10, 102, 4, 1, $redis, false);
        $other = WebSocketToken::issueStudentToken(99, 201, 3, 1, $redis, false);

        $revokedCount = WebSocketToken::revokeUserAttemptTokens(10, [101, 102, 102, 0, -5], $redis, false);
        $this->assertSame(2, $revokedCount);

        $this->assertFalse($redis->get('ws_student_token:' . $t1));
        $this->assertFalse($redis->get('attempt_ws_token:101'));
        $this->assertFalse($redis->get('ws_student_token:' . $t2));
        $this->assertFalse($redis->get('attempt_ws_token:102'));

        // Unrelated student's token remains intact
        $this->assertNotFalse($redis->get('ws_student_token:' . $other));
    }

    public function testIsTokenAllowedByAuthStateEnforcesActiveFlagAndAuthVersion(): void
    {
        // Cache miss in Redis -> allowed (do not touch synchronous DB in WS loop)
        $this->assertTrue(WebSocketToken::isTokenAllowedByAuthState(
            ['user_id' => 10, 'auth_version' => 1],
            null
        ));

        // Inactive user -> rejected even if auth_version matches
        $this->assertFalse(WebSocketToken::isTokenAllowedByAuthState(
            ['user_id' => 10, 'auth_version' => 2],
            ['is_active' => 0, 'auth_version' => 2]
        ));

        // Stale auth_version (e.g. password/role changed for proctor or pre-ban token) -> rejected
        $this->assertFalse(WebSocketToken::isTokenAllowedByAuthState(
            ['user_id' => 7, 'auth_version' => 1],
            ['is_active' => 1, 'auth_version' => 2]
        ));

        // Legacy token without auth_version defaults to 1: valid when state is 1, rejected when state is 2
        $this->assertTrue(WebSocketToken::isTokenAllowedByAuthState(
            ['user_id' => 7],
            ['is_active' => 1, 'auth_version' => 1]
        ));
        $this->assertFalse(WebSocketToken::isTokenAllowedByAuthState(
            ['user_id' => 7],
            ['is_active' => 1, 'auth_version' => 2]
        ));
    }

    public function testParseAuthStateJsonHandlesNullEmptyAndMalformedStrings(): void
    {
        $this->assertNull(WebSocketToken::parseAuthStateJson(null));
        $this->assertNull(WebSocketToken::parseAuthStateJson(false));
        $this->assertNull(WebSocketToken::parseAuthStateJson(''));
        $this->assertNull(WebSocketToken::parseAuthStateJson('{broken-json'));
        $this->assertNull(WebSocketToken::parseAuthStateJson('{"unrelated":1}'));

        $parsed = WebSocketToken::parseAuthStateJson('{"auth_version":3,"cred_version":1,"is_active":1,"role":"admin"}');
        $this->assertIsArray($parsed);
        $this->assertSame(3, $parsed['auth_version']);
        $this->assertSame(1, $parsed['is_active']);
    }
}
