package id.sch.cbt.kiosk

import java.security.MessageDigest

/**
 * Penanda perangkat untuk keperluan blokir.
 *
 * Diturunkan dari ANDROID_ID, yang sejak Android 8 disekat per kunci tanda
 * tangan aplikasi — nilai yang sama TIDAK terlihat oleh aplikasi lain mana pun,
 * dan minSdk proyek ini 28 sehingga penyekatan itu berlaku di seluruh perangkat
 * yang didukung. Berbeda dari UUID per-pemasangan yang dipakai sebelumnya,
 * nilai ini bertahan melewati hapus data aplikasi dan pasang ulang.
 *
 * Yang meninggalkan perangkat adalah hash-nya, bukan nilainya. Batas "hanya
 * penanda, tidak lebih" jadi sifat konstruksi alih-alih janji: server hanya bisa
 * membandingkan sama atau tidak sama, nilainya tidak bisa dibalik menjadi
 * identitas perangkat, dan bocornya basis data tidak membocorkan identifier apa
 * pun.
 *
 * Batasnya jujur: perangkat yang di-root masih bisa mengubah ANDROID_ID, dan
 * factory reset mengembalikannya. Yang dibeli adalah lompatan friction dari
 * "hapus data aplikasi" menjadi "root atau factory reset" — bukan tembok.
 *
 * Sengaja terpisah dari MainActivity supaya bisa diuji dengan JUnit biasa tanpa
 * framework Android: Settings.Secure.getString() butuh ContentResolver, tapi
 * validasi dan hashing-nya tidak.
 */
object DeviceIdentity {

    /**
     * Sejumlah perangkat lama mengembalikan nilai ini secara identik, sehingga
     * memakainya akan menyamakan perangkat-perangkat yang sebenarnya berbeda.
     */
    private const val KNOWN_DUPLICATE = "9774d56d682e549c"

    private const val NAMESPACE = "cbt-mf|"

    /**
     * ^...$ di sini AMAN dan sengaja tidak diubah jadi \A...\z seperti di sisi
     * PHP. Regex.matches() Kotlin memanggil Matcher.matches(), yang menuntut
     * seluruh masukan cocok, jadi newline di ujung tetap ditolak. Yang rentan
     * adalah API bergaya find() seperti preg_match PHP.
     */
    private val HEX16 = Regex("^[0-9a-fA-F]{16}$")

    fun isUsableAndroidId(raw: String?): Boolean {
        val value = raw?.trim() ?: return false

        return value.isNotEmpty()
            && HEX16.matches(value)
            && !value.equals(KNOWN_DUPLICATE, ignoreCase = true)
    }

    /**
     * Penanda yang dikirim ke server: 64 heksadesimal huruf kecil. Panjang dan
     * himpunan karakternya sengaja muat di saringan sisi server
     * (DeviceBan::isValidDeviceId dan kiosk-heartbeat.php: [A-Za-z0-9_-], <= 64).
     */
    fun derive(androidId: String): String = sha256Hex(NAMESPACE + androidId.trim())

    fun sha256Hex(input: String): String {
        val bytes = MessageDigest.getInstance("SHA-256").digest(input.toByteArray(Charsets.UTF_8))

        return bytes.joinToString("") { "%02x".format(it) }
    }
}
