<?php

declare(strict_types=1);

namespace App\Config;

use CodeIgniter\Config\BaseConfig;

class Database extends BaseConfig
{
    public array $default = [
        'DSN'          => getenv('DB_DSN') ?: '',
        'hostname'     => getenv('DB_HOST') ?: 'postgresql',
        'username'     => getenv('DB_USERNAME') ?: 'cbt_user',
        'password'     => getenv('DB_PASSWORD') ?: 'cbt_secret_password',
        'database'     => getenv('DB_DATABASE') ?: 'cbt_improved',
        'DBDriver'     => getenv('DB_DRIVER') ?: 'Postgre',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => ENVIRONMENT !== 'production',
        'charset'      => 'utf8',
        'DBCollat'     => 'utf8_general_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => false,
        'failover'     => [],
        'port'         => (int) getenv('DB_PORT') ?: 5432,
        'numberConnect'=> true,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    /**
     * This database connection is used for
     * testing purposes only.
     */
    public array $tests = [
        'DSN'          => '',
        'hostname'     => 'localhost',
        'username'     => 'test_user',
        'password'     => 'test_password',
        'database'     => 'test_cbt',
        'DBDriver'     => 'Postgre',
        'DBPrefix'     => 'test_',
        'pConnect'     => false,
        'DBDebug'      => true,
        'charset'      => 'utf8',
        'DBCollat'     => 'utf8_general_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => false,
        'failover'     => [],
        'port'         => 5432,
        'numberConnect'=> true,
    ];

    /**
     * Migrations schema
     */
    public bool $enabled = true;

    /**
     * Migration table name
     */
    public string $table = 'migrations';

    /**
     * Current timestamp for migration versioning
     */
    public int $timestamp = 0;
}
