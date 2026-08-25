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
        'samesite'  => 'None',
    ];

    /**
     * Session Expiration (seconds). 7200 = 2 hours.
     */
    public int $expiration = 7200;

    /**
     * Session Save Path — Redis connection string
     * Host, port, dan password dirakit dari env di __construct(). Nilai di sini
     * cuma cadangan kalau env-nya tidak ada sama sekali.
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

        // Host dan port ikut dibaca dari env, sejajar dengan Config\Cache.
        // Sebelumnya host 'redis' tertulis mati di sini dan ditimpa ulang tiap
        // kali REDIS_PASSWORD terisi, jadi Redis di host lain -- layanan
        // terkelola, server terpisah -- tidak pernah terpakai dan sesi gagal
        // dengan gejala yang tidak menunjuk ke Redis sama sekali.
        //
        // session.savePath yang ditulis eksplisit di .env tetap menang: perakitan
        // di bawah hanya jalan kalau tidak ada yang menyetelnya.
        if (empty(env('session.savePath'))) {
            // ?: dipakai, bukan argumen default env(), supaya variabel yang ada
            // tapi bernilai kosong tidak menghasilkan 'tcp://:6379'.
            $host = env('session.redis.host') ?: env('REDIS_HOST') ?: 'redis';
            $port = (int) (env('session.redis.port') ?: env('REDIS_PORT') ?: 6379);
            $password = env('session.redis.password') ?: env('REDIS_PASSWORD') ?: '';

            $this->savePath = 'tcp://' . $host . ':' . $port;
            if ($password !== '') {
                $this->savePath .= '?auth=' . rawurlencode((string) $password);
            }
        }

        // Dynamically set session cookie secure flag based on base_url scheme
        // to prevent SecurityException when accessed via HTTP.
        $this->cookie['secure'] = (strpos(base_url(), 'https://') === 0);
    }
}
