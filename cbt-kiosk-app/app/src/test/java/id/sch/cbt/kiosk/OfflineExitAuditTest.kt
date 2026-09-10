package id.sch.cbt.kiosk

import id.sch.cbt.kiosk.security.OfflineExitAudit
import org.json.JSONArray
import org.junit.Assert.assertEquals
import org.junit.Test

class OfflineExitAuditTest {

    @Test
    fun `antrian kosong menerima entri pertama`() {
        val q = OfflineExitAudit.appendToQueue(null, 1757400000L, "2026-09-09", "1.0.0")
        val arr = JSONArray(q)

        assertEquals(1, arr.length())
        assertEquals(1757400000L, arr.getJSONObject(0).getLong("at"))
        assertEquals("2026-09-09", arr.getJSONObject(0).getString("code_day"))
        assertEquals("1.0.0", arr.getJSONObject(0).getString("app_version"))
    }

    @Test
    fun `entri baru ditambahkan di belakang`() {
        var q = OfflineExitAudit.appendToQueue(null, 1L, "2026-09-09", "1.0.0")
        q = OfflineExitAudit.appendToQueue(q, 2L, "2026-09-10", "1.0.0")

        val arr = JSONArray(q)
        assertEquals(2, arr.length())
        assertEquals(1L, arr.getJSONObject(0).getLong("at"))
        assertEquals(2L, arr.getJSONObject(1).getLong("at"))
    }

    @Test
    fun `antrian dibatasi dan membuang yang terlama`() {
        var q: String? = null
        for (i in 1..(OfflineExitAudit.MAX_QUEUE + 5)) {
            q = OfflineExitAudit.appendToQueue(q, i.toLong(), "2026-09-09", "1.0.0")
        }

        val arr = JSONArray(q)
        assertEquals(OfflineExitAudit.MAX_QUEUE, arr.length())
        // Lima terlama terbuang, jadi entri pertama sekarang bernilai 6.
        assertEquals(6L, arr.getJSONObject(0).getLong("at"))
    }

    @Test
    fun `antrian rusak tidak menghilangkan entri baru`() {
        val q = OfflineExitAudit.appendToQueue("bukan json", 42L, "2026-09-09", "1.0.0")
        val arr = JSONArray(q)

        assertEquals(1, arr.length())
        assertEquals(42L, arr.getJSONObject(0).getLong("at"))
    }

    @Test
    fun `ukuran antrian terbaca`() {
        assertEquals(0, OfflineExitAudit.queueSize(null))
        assertEquals(0, OfflineExitAudit.queueSize("bukan json"))
        assertEquals(1, OfflineExitAudit.queueSize(OfflineExitAudit.appendToQueue(null, 1L, "2026-09-09", "1.0.0")))
    }
}
