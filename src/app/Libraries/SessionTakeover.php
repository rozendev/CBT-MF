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
     * Umur token sesi sekaligus umur kunci pendampingnya. Setiap tempat yang
     * menulis atau memperpanjang salah satu dari keduanya wajib memakai angka
     * ini, karena seluruh fitur ini bersandar pada satu invarian: umur
     * pendamping tidak boleh menyimpang dari umur tokennya.
     *
     * Kedua arah penyimpangan merugikan. Pendamping yang mati lebih dulu
     * membuat perangkat pemegang sesi terlihat asing, lalu decide() menolaknya
     * — siswa terkunci dari ujiannya sendiri, persis keadaan yang fitur ini
     * ada untuk menghapusnya. Pendamping yang hidup lebih lama memberi hak
     * ambil alih kepada perangkat yang sudah tidak memegang sesi.
     *
     * Sebelum konstanta ini ada, invarian tersebut hanya berupa angka 7200
     * yang kebetulan sama di banyak tempat. Satu di antaranya — perpanjangan
     * di MultiLoginFilter — memang sempat menyimpang, dan tidak ada tes yang
     * bisa menangkapnya karena tidak ada yang menyatakan aturannya.
     */
    public const TTL_SECONDS = 7200;

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

        // hash_equals(), bukan ===, dan ini disengaja.
        //
        // Sempat diganti === dengan alasan "device_id bukan rahasia karena
        // dikirim terbuka di tiap permintaan". Alasan itu menilai dari sudut
        // pandang yang salah. Keputusan ini berjalan SETELAH password
        // terverifikasi, jadi pihak yang sampai ke sini sudah memegang
        // kredensial korban — yang belum ia punya justru device_id korban.
        // Bagi dia, $storedDevice adalah nilai rahasia yang ingin ditebak, dan
        // perbandingan byte-per-byte yang keluar lebih cepat saat byte pertama
        // sudah beda persis memberi sinyal itu.
        //
        // Eksploitasinya lewat jaringan memang sulit. Tapi ini jalur otorisasi,
        // hash_equals tidak berbiaya, dan beban pembuktian ada pada yang ingin
        // MENGHAPUS kendali, bukan yang mempertahankannya.
        return hash_equals($storedDevice, $incomingDevice) ? self::TAKEOVER : self::BUSY;
    }

    /**
     * Nama kunci pendamping untuk satu pengguna.
     *
     * Dirakit di sini, bukan diketik ulang di tiap pemanggil, karena kelas ini
     * yang memegang aturan pendamping. Nama yang salah ketik tidak akan gagal
     * dengan berisik: Redis akan menulis dan membaca kunci lain dengan patuh,
     * decide() lalu melihat pendamping yang hilang, dan fitur ini mati
     * diam-diam di jalur tersebut.
     *
     * Kunci tokennya sendiri sengaja tidak ikut pindah ke sini. `user_login_token`
     * lebih tua dari fitur ini dan punya konsumen lain di luar berkas-berkas
     * yang mengurus pengambilalihan.
     */
    public static function deviceKey(int|string $userId): string
    {
        return "user_login_device:{$userId}";
    }
}
