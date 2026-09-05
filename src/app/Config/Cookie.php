<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use DateTimeInterface;

class Cookie extends BaseConfig
{
    /**
     * --------------------------------------------------------------------------
     * Cookie Prefix
     * --------------------------------------------------------------------------
     *
     * Set a cookie name prefix if you need to avoid collisions.
     */
    public string $prefix = '';

    /**
     * --------------------------------------------------------------------------
     * Cookie Expires Timestamp
     * --------------------------------------------------------------------------
     *
     * Default expires timestamp for cookies. Setting this to `0` will mean the
     * cookie will not have the `Expires` attribute and will behave as a session
     * cookie.
     *
     * @var DateTimeInterface|int|string
     */
    public $expires = 0;

    /**
     * --------------------------------------------------------------------------
     * Cookie Path
     * --------------------------------------------------------------------------
     *
     * Typically will be a forward slash.
     */
    public string $path = '/';

    /**
     * --------------------------------------------------------------------------
     * Cookie Domain
     * --------------------------------------------------------------------------
     *
     * Set to `.your-domain.com` for site-wide cookies.
     */
    public string $domain = '';

    public bool $secure = true;

    public function __construct()
    {
        parent::__construct();

        // Dynamically set cookie secure flag based on base_url scheme.
        // If baseURL is http, secure cookies must be disabled to prevent session and CSRF loss.
        $isHttps      = (strpos(base_url(), 'https://') === 0);
        $this->secure = $isHttps;

        // $samesite harus ikut $secure, bukan konstanta lepas: Cookie::validateSameSite()
        // menolak SameSite=None tanpa Secure dan melempar CookieException di filter
        // `before`, sebelum controller mana pun jalan — jadi SATU request saja dengan
        // secure=false (baseURL http, mis. akses lokal http://localhost:8080) menjatuhkan
        // seluruh aplikasi. 'None' aslinya ditambahkan di 2477cdf supaya WebView kiosk
        // (origin silang, https://appassets.androidplatform.net) bisa menerima cookie ini;
        // itu cuma berlaku ketika koneksi memang HTTPS, jadi turunkan ke 'Lax' saat http —
        // fallback aman yang sama seperti default CodeIgniter sendiri.
        $this->samesite = $isHttps ? 'None' : 'Lax';
    }

    /**
     * --------------------------------------------------------------------------
     * Cookie HTTPOnly
     * --------------------------------------------------------------------------
     *
     * Cookie will only be accessible via HTTP(S) (no JavaScript).
     */
    public bool $httponly = true;

    /**
     * --------------------------------------------------------------------------
     * Cookie SameSite
     * --------------------------------------------------------------------------
     *
     * Configure cookie SameSite setting. Allowed values are:
     * - None
     * - Lax
     * - Strict
     * - ''
     *
     * Alternatively, you can use the constant names:
     * - `Cookie::SAMESITE_NONE`
     * - `Cookie::SAMESITE_LAX`
     * - `Cookie::SAMESITE_STRICT`
     *
     * Defaults to `Lax` for compatibility with modern browsers. Setting `''`
     * (empty string) means default SameSite attribute set by browsers (`Lax`)
     * will be set on cookies. If set to `None`, `$secure` must also be set.
     *
     * Nilai statis di sini hanya dipakai kalau constructor di bawah tidak
     * jalan (mis. instansiasi langsung tanpa lewat factory). Pada jalur
     * normal, __construct() menimpanya secara dinamis mengikuti $secure —
     * lihat komentar di sana.
     *
     * @var ''|'Lax'|'None'|'Strict'
     */
    public string $samesite = 'Lax';

    /**
     * --------------------------------------------------------------------------
     * Cookie Raw
     * --------------------------------------------------------------------------
     *
     * This flag allows setting a "raw" cookie, i.e., its name and value are
     * not URL encoded using `rawurlencode()`.
     *
     * If this is set to `true`, cookie names should be compliant of RFC 2616's
     * list of allowed characters.
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie#attributes
     * @see https://tools.ietf.org/html/rfc2616#section-2.2
     */
    public bool $raw = false;
}
