<?php

declare(strict_types=1);

namespace App\Config;

use CodeIgniter\Config\BaseConfig;

class Session extends BaseConfig
{
    /**
     * Session Driver
     * 
     * Supported: "file", "database", "redis", "array"
     */
    public string $driver = 'redis';

    /**
     * Session Cookie Name
     */
    public string $cookieName = 'cbt_session';

    /**
     * Session Expiration (in seconds)
     */
    public int $expiration = 7200;

    /**
     * Session Save Path
     * For Redis: tcp://host:port?database=1&password=secret&prefix=cbt_session:
     */
    public string $savePath = 'tcp://' . (getenv('REDIS_HOST') ?: 'redis') . ':' . (getenv('REDIS_PORT') ?: 6379) . '?database=1&password=' . urlencode(getenv('REDIS_PASSWORD') ?: '') . '&prefix=cbt_session:';

    /**
     * Session Match IP
     */
    public bool $matchIP = true;

    /**
     * Session Time to Update
     */
    public int $timeToUpdate = 300;

    /**
     * Session Regenerate Destroy
     */
    public bool $regenerateDestroy = false;

    /**
     * Session Database Group
     */
    public ?string $DBGroup = null;

    /**
     * Lock Retry Interval (microseconds)
     */
    public int $lockRetryInterval = 100_000;

    /**
     * Lock Max Retries
     */
    public int $lockMaxRetries = 300;

    /**
     * Cookie Settings
     */
    public function __construct()
    {
        parent::__construct();
        
        // Secure cookie in production
        if (ENVIRONMENT === 'production') {
            $this->cookieSecure = true;
        }
        
        // HTTP only cookies
        $this->cookieHTTPOnly = true;
        
        // SameSite policy
        $this->cookieSameSite = 'Lax';
    }
}
