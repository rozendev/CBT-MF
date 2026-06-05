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
    public string $driver = \CodeIgniter\Session\Handlers\DatabaseHandler::class;

    /**
     * Session Cookie Name
     */
    public string $cookieName = 'ci_session';

    /**
     * Session Expiration (seconds). 7200 = 2 hours.
     */
    public int $expiration = 7200;

    /**
     * Session Save Path — Database table name
     */
    public string $savePath = 'ci_sessions';

    /**
     * Whether to match the user's IP address when reading the session data.
     */
    public bool $matchIP = false;

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
}
