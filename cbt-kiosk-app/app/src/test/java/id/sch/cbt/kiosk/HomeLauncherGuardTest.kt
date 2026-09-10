package id.sch.cbt.kiosk

import id.sch.cbt.kiosk.kiosk.HomeLauncherGuard
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class HomeLauncherGuardTest {

    private val own = "id.sch.cbt.kiosk"

    @Test
    fun `paket yang sama berarti aplikasi memegang peran home`() {
        assertTrue(HomeLauncherGuard.isSelfTheHomeApp("id.sch.cbt.kiosk", own))
    }

    @Test
    fun `launcher lain berarti tidak memegang peran home`() {
        assertFalse(HomeLauncherGuard.isSelfTheHomeApp("com.google.android.apps.nexuslauncher", own))
    }

    @Test
    fun `resolved null berarti tidak memegang peran home`() {
        assertFalse(HomeLauncherGuard.isSelfTheHomeApp(null, own))
    }

    @Test
    fun `resolved kosong berarti tidak memegang peran home`() {
        assertFalse(HomeLauncherGuard.isSelfTheHomeApp("   ", own))
    }

    @Test
    fun `resolver bawaan sistem bukan berarti memegang peran home`() {
        // Saat ada beberapa launcher dan pengguna belum menetapkan default,
        // sistem mengembalikan resolver-nya sendiri. Itu justru keadaan di mana
        // tidak ada yang perlu dikembalikan.
        assertFalse(HomeLauncherGuard.isSelfTheHomeApp("android", own))
    }
}
