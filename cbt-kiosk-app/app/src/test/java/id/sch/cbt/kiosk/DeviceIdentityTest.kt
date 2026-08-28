package id.sch.cbt.kiosk

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class DeviceIdentityTest {

    @Test
    fun `android id yang sah diterima`() {
        assertTrue(DeviceIdentity.isUsableAndroidId("a1b2c3d4e5f60718"))
        assertTrue(DeviceIdentity.isUsableAndroidId("0123456789ABCDEF"))
    }

    @Test
    fun `android id kosong atau null ditolak`() {
        assertFalse(DeviceIdentity.isUsableAndroidId(null))
        assertFalse(DeviceIdentity.isUsableAndroidId(""))
        assertFalse(DeviceIdentity.isUsableAndroidId("   "))
    }

    @Test
    fun `nilai kembar terkenal ditolak`() {
        // Sejumlah perangkat lama mengembalikan nilai yang sama persis ini,
        // sehingga memakainya akan menyamakan perangkat yang berbeda.
        assertFalse(DeviceIdentity.isUsableAndroidId("9774d56d682e549c"))
        assertFalse(DeviceIdentity.isUsableAndroidId("9774D56D682E549C"))
    }

    @Test
    fun `panjang atau format salah ditolak`() {
        assertFalse(DeviceIdentity.isUsableAndroidId("abc"))
        assertFalse(DeviceIdentity.isUsableAndroidId("a1b2c3d4e5f6071"))
        assertFalse(DeviceIdentity.isUsableAndroidId("a1b2c3d4e5f607189"))
        assertFalse(DeviceIdentity.isUsableAndroidId("z1b2c3d4e5f60718"))
    }

    @Test
    fun `derivasi menghasilkan 64 heksadesimal huruf kecil`() {
        val id = DeviceIdentity.derive("a1b2c3d4e5f60718")

        assertEquals(64, id.length)
        assertTrue(id.matches(Regex("^[0-9a-f]{64}$")))
    }

    @Test
    fun `derivasi stabil untuk masukan yang sama`() {
        assertEquals(
            DeviceIdentity.derive("a1b2c3d4e5f60718"),
            DeviceIdentity.derive("a1b2c3d4e5f60718")
        )
    }

    @Test
    fun `perangkat berbeda menghasilkan penanda berbeda`() {
        assertNotEquals(
            DeviceIdentity.derive("a1b2c3d4e5f60718"),
            DeviceIdentity.derive("00112233445566aa")
        )
    }

    @Test
    fun `penanda tidak sama dengan hash polos nilai aslinya`() {
        // Prefiks namespace mengunci nilai ke aplikasi ini, sehingga penanda
        // yang sama tidak bisa dihasilkan pihak lain dari ANDROID_ID yang sama.
        assertNotEquals(
            DeviceIdentity.sha256Hex("a1b2c3d4e5f60718"),
            DeviceIdentity.derive("a1b2c3d4e5f60718")
        )
    }

    @Test
    fun `penanda lolos saringan device_id sisi server`() {
        // Server menerima [A-Za-z0-9_-] maksimal 64 karakter
        // (DeviceBan::isValidDeviceId dan kiosk-heartbeat.php).
        val id = DeviceIdentity.derive("a1b2c3d4e5f60718")

        assertTrue(id.matches(Regex("\\A[A-Za-z0-9_-]+\\z")))
        assertTrue(id.length <= 64)
    }
}
