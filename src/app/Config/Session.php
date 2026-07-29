<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Session\Handlers\BaseHandler;
use CodeIgniter\Session\Handlers\RedisHandler;

class Session extends BaseConfig
{
    /**
     * Session Driver — Using Redis for reliability in Docker
     *
     * @var class-string<BaseHandler>
     */
    public string $driver = \CodeIgniter\Session\Handlers\RedisHandler::class;

    /**
     * Session Cookie Name
     */
    public string $cookieName = 'ci_session';

    /**
     * Session Cookie Options (for secure cookies in production)
     * @var array<string, mixed>
     */
    public array $cookie = [
        'lifetime' => 7200,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly'  => true,
        'samesite'  => 'Lax',
    ];

    /**
     * Session Expiration (seconds). 7200 = 2 hours.
     */
    public int $expiration = 7200;

    /**
     * Session Save Path — Redis connection string
     * Password is dynamically injected from env('REDIS_PASSWORD') in __construct()
     */
    public string $savePath = 'tcp://redis:6379';

    /**
     * Whether to match the user's IP address when reading the session data.
     */
    public bool $matchIP = true;

    /**
     * How many seconds between CI regenerating the session ID.
     * Set higher to reduce chance of session loss during rapid requests.
     */
    public int $timeToUpdate = 600;

    /**
     * Whether to destroy session data associated with the old session ID
     * when auto-regenerating the session ID.
     * FALSE = old data kept until garbage collected (safer for concurrent requests)
     */
    public bool $regenerateDestroy = false;

    /**
     * DB Group for the database session.
     */
    public ?string $DBGroup = null;

    /**
     * Time (microseconds) to wait if lock cannot be acquired.
     */
    public int $lockRetryInterval = 100_000;

    /**
     * Maximum number of lock acquisition attempts.
     */
    public int $lockMaxRetries = 300;

    public function __construct()
    {
        parent::__construct();

        $password = env('REDIS_PASSWORD', '');
        if (!empty($password)) {
            $this->savePath = 'tcp://redis:6379?auth=' . $password;
        }
    }
}
