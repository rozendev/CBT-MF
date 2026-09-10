package id.sch.cbt.kiosk

import id.sch.cbt.kiosk.security.OfflineExitCode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class OfflineExitCodeTest {

    /** Salt dipakai sebagai byte ASCII dari string hex ini, BUKAN hasil decode hex. */
    private val salt = "0123456789abcdef0123456789abcdef"

    // 2026-09-09 10:00 WIB
    private val siang = 1788922800000L
    // 2026-09-09 00:30 WIB — masih tanggal 9 di Jakarta, tapi 8 di UTC
    private val dinihari = 1788888600000L
    // 2026-09-09 23:45 WIB — masih tanggal 9 di Jakarta, tapi 10 di UTC+10
    private val larutMalam = 1788972300000L

    @Test
    fun `tanggal dihitung di zona Jakarta bukan UTC`() {
        assertEquals("2026-09-09", OfflineExitCode.dayOf(siang))
        assertEquals("2026-09-09", OfflineExitCode.dayOf(dinihari))
        assertEquals("2026-09-09", OfflineExitCode.dayOf(larutMalam))
    }

    @Test
    fun `hari kandidat mencakup kemarin hari ini dan besok`() {
        assertEquals(
            listOf("2026-09-08", "2026-09-09", "2026-09-10"),
            OfflineExitCode.candidateDays(siang, null)
        )
    }

    @Test
    fun `hari lebih tua dari jangkar dibuang`() {
        // Jangkar dari server = 2026-09-09, jadi memundurkan jam ke kemarin
        // tidak lagi membuka kode kemarin.
        assertEquals(
            listOf("2026-09-09", "2026-09-10"),
            OfflineExitCode.candidateDays(siang, "2026-09-09")
        )
    }

    @Test
    fun `jam yang dimundurkan jauh tidak menghasilkan kandidat sama sekali`() {
        assertTrue(OfflineExitCode.candidateDays(siang, "2026-10-01").isEmpty())
    }

    @Test
    fun `jangkar hanya boleh maju`() {
        assertEquals("2026-09-10", OfflineExitCode.advanceAnchor("2026-09-09", "2026-09-10"))
        assertEquals("2026-09-10", OfflineExitCode.advanceAnchor("2026-09-10", "2026-09-09"))
        assertEquals("2026-09-09", OfflineExitCode.advanceAnchor(null, "2026-09-09"))
        assertEquals("2026-09-09", OfflineExitCode.advanceAnchor("2026-09-09", null))
        assertNull(OfflineExitCode.advanceAnchor(null, null))
    }

    @Test
    fun `pbkdf2 cocok dengan vektor tetap dari sisi PHP`() {
        // kode 98255973 = password "R4hasia-Pengawas-2026" pada 2026-09-09
        assertEquals(
            "854d6d4d2fe57657e87673c0c4a084a16da67df0154b07844ccf84d6d3b8dd66",
            OfflineExitCode.pbkdf2Hex("98255973", salt, 1000)
        )
    }

    @Test
    fun `pbkdf2 cocok pada iterasi produksi`() {
        assertEquals(
            "68839ca6493f3ef3bfa077e6df34eaace3b468fec755729b01efc429d900e623",
            OfflineExitCode.pbkdf2Hex("98255973", salt, 120000)
        )
    }

    private fun envelope(enabled: Boolean = true, iterations: Int = 1000): OfflineExitCode.Envelope =
        OfflineExitCode.Envelope(
            enabled = enabled,
            iterations = iterations,
            entries = listOf(
                OfflineExitCode.Entry(
                    "2026-09-09", salt,
                    "854d6d4d2fe57657e87673c0c4a084a16da67df0154b07844ccf84d6d3b8dd66"
                ),
                OfflineExitCode.Entry(
                    "2026-09-10", salt,
                    "78b0f414f108d98ec1274ff1e6ed439b33b2d867cb9fba2409f2f97b51062806"
                )
            )
        )

    @Test
    fun `kode hari ini diterima`() {
        assertEquals(
            "2026-09-09",
            OfflineExitCode.verifyAgainst("98255973", envelope(), listOf("2026-09-09"))
        )
    }

    @Test
    fun `kode besok diterima lewat jendela kandidat`() {
        assertEquals(
            "2026-09-10",
            OfflineExitCode.verifyAgainst("11219294", envelope(), listOf("2026-09-09", "2026-09-10"))
        )
    }

    @Test
    fun `spasi di sekitar kode diabaikan`() {
        assertEquals(
            "2026-09-09",
            OfflineExitCode.verifyAgainst("  98255973 ", envelope(), listOf("2026-09-09"))
        )
    }

    @Test
    fun `kode salah ditolak`() {
        assertNull(OfflineExitCode.verifyAgainst("00000000", envelope(), listOf("2026-09-09")))
    }

    @Test
    fun `kode hari yang tidak ada di kandidat ditolak`() {
        assertNull(OfflineExitCode.verifyAgainst("11219294", envelope(), listOf("2026-09-09")))
    }

    @Test
    fun `amplop mati menolak apa pun`() {
        assertNull(OfflineExitCode.verifyAgainst("98255973", envelope(enabled = false), listOf("2026-09-09")))
    }

    @Test
    fun `masukan yang bukan delapan digit ditolak tanpa menghitung pbkdf2`() {
        // Ini juga yang menjaga percobaan password normal tetap murah.
        assertNull(OfflineExitCode.verifyAgainst("password-pengawas", envelope(), listOf("2026-09-09")))
        assertNull(OfflineExitCode.verifyAgainst("9825597", envelope(), listOf("2026-09-09")))
        assertNull(OfflineExitCode.verifyAgainst("982559730", envelope(), listOf("2026-09-09")))
        assertNull(OfflineExitCode.verifyAgainst("", envelope(), listOf("2026-09-09")))
    }

    @Test
    fun `amplop dari json server terbaca`() {
        val raw = """
            {"enabled":true,"iterations":1000,"days":[
              {"day":"2026-09-09","salt":"$salt","hash":"854d6d4d2fe57657e87673c0c4a084a16da67df0154b07844ccf84d6d3b8dd66"}
            ]}
        """.trimIndent()

        val parsed = OfflineExitCode.parseEnvelope(raw)

        assertTrue(parsed.enabled)
        assertEquals(1000, parsed.iterations)
        assertEquals(1, parsed.entries.size)
        assertEquals("2026-09-09", parsed.entries[0].day)
    }

    @Test
    fun `amplop mati atau rusak menghasilkan envelope tidak aktif`() {
        assertFalse(OfflineExitCode.parseEnvelope("""{"enabled":false,"iterations":0,"days":[]}""").enabled)
        assertFalse(OfflineExitCode.parseEnvelope("bukan json").enabled)
        assertFalse(OfflineExitCode.parseEnvelope(null).enabled)
        assertFalse(OfflineExitCode.parseEnvelope("").enabled)
    }

    @Test
    fun `empat kegagalan pertama menaikkan hitungan tanpa mengunci`() {
        for (fails in 0..3) {
            val next = OfflineExitCode.nextFailureState(fails, 1_000_000L)
            assertEquals(fails + 1, next.fails)
            assertEquals(0L, next.lockUntil)
        }
    }

    @Test
    fun `kegagalan kelima mengunci dan mereset hitungan`() {
        val next = OfflineExitCode.nextFailureState(OfflineExitCode.MAX_FAILS - 1, 1_000_000L)

        assertEquals(0, next.fails)
        assertEquals(1_000_000L + OfflineExitCode.LOCKOUT_MS, next.lockUntil)
    }

    @Test
    fun `entri amplop yang tidak lengkap dibuang`() {
        val raw = """
            {"enabled":true,"iterations":1000,"days":[
              {"day":"2026-09-09","salt":"","hash":"abc"},
              {"day":"","salt":"$salt","hash":"abc"},
              {"day":"2026-09-10","salt":"$salt","hash":"78b0f414f108d98ec1274ff1e6ed439b33b2d867cb9fba2409f2f97b51062806"}
            ]}
        """.trimIndent()

        val parsed = OfflineExitCode.parseEnvelope(raw)

        assertEquals(1, parsed.entries.size)
        assertEquals("2026-09-10", parsed.entries[0].day)
    }
}
