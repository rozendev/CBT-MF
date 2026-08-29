<?php

namespace App\Libraries;

/**
 * Boleh atau tidak sebuah login mengambil alih sesi yang sedang tercatat.
 *
 * Masalah yang dipecahkan: gerbang login lama hanya menanyakan "apakah ada
 * token?", tidak pernah "dari perangkat mana?". Token ber-TTL dua jam yang
 * diperpanjang tiap permintaan, jadi setelah aplikasi siswa mati, token
 * peninggalan sesi yang sudah tidak ada itu mengunci pemiliknya sendiri sampai
 * dua jam — dan hanya admin yang bisa melepaskannya.
 *
 * Dengan device_id pemegang sesi ikut dicatat, perangkat yang sama boleh
 * merebut kembali sesinya sendiri, sementara perangkat lain tetap ditolak
 * persis seperti sebelumnya.
 *
 * Murni dan tanpa I/O dengan sengaja: inilah bagian yang salah menyimpulkannya
 * berarti siswa terkunci dari ujiannya sendiri, atau sebaliknya, perlindungan
 * multi-login terlewati. Bagian seperti itu harus bisa diuji tanpa Redis.
 */
final class SessionTakeover
{
    /** Tidak ada sesi tercatat — login biasa. */
    public const FRESH = 'fresh';

    /** Perangkat yang sama merebut kembali sesinya sendiri. */
    public const TAKEOVER = 'takeover';

    /** Ada sesi milik perangkat lain, atau tidak diketahui milik siapa. */
    public const BUSY = 'busy';

    /** Penanda 'BANNED' ditimpa, bukan diperlakukan sebagai sesi. */
    public const CLEAR_BANNED = 'clear_banned';

    /**
     * @param string|null $existingToken Isi user_login_token, null bila tidak ada.
     * @param string|null $storedDevice  Isi user_login_device, null bila tidak ada.
     * @param string      $incomingDevice device_id yang dikirim klien, '' bila tidak ada.
     */
    public static function decide(?string $existingToken, ?string $storedDevice, string $incomingDevice): string
    {
        if ($existingToken === null || $existingToken === '') {
            return self::FRESH;
        }

        // 'BANNED' bukan sesi aktif. Perilaku lama dipertahankan: login yang
        // sudah lolos pemeriksaan kredensial menimpanya. Penegakan ban yang
        // sesungguhnya ada di pemeriksaan is_active, bukan di sini.
        if ($existingToken === 'BANNED') {
            return self::CLEAR_BANNED;
        }

        // Tidak tahu siapa pemegangnya berarti tidak boleh merebut. Kunci
        // pendamping bisa hilang lebih dulu karena TTL, flush, atau sesi yang
        // dibuat versi lama sebelum fitur ini ada.
        if ($storedDevice === null || $storedDevice === '') {
            return self::BUSY;
        }

        // Klien yang tidak mengirim device_id — misalnya browser biasa — tidak
        // pernah boleh merebut. Kalau diloloskan, perlindungan multi-login bisa
        // dilewati hanya dengan tidak mengirim field itu.
        if ($incomingDevice === '') {
            return self::BUSY;
        }

        // === dan bukan hash_equals(): device_id bukan rahasia. Ia identifier
        // yang dikirim klien terbuka di setiap permintaan, jadi tidak ada yang
        // bisa bocor lewat perbedaan waktu perbandingan. Memakai hash_equals di
        // sini akan menyesatkan pembaca berikutnya untuk mengira device_id
        // dijaga kerahasiaannya, lalu membangun di atas asumsi yang keliru itu.
        return $storedDevice === $incomingDevice ? self::TAKEOVER : self::BUSY;
    }
}
