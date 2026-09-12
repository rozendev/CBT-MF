package id.sch.cbt.kiosk

import id.sch.cbt.kiosk.security.DndGuard
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class DndGuardTest {

    // --- Kapan dialog izin harus muncul di alur setup ---

    @Test
    fun `kebijakan aktif tanpa izin dan tanpa waiver memunculkan dialog`() {
        assertTrue(DndGuard.needsPermissionPrompt(policyEnabled = true, waived = false, granted = false))
    }

    @Test
    fun `izin sudah diberikan tidak memunculkan dialog`() {
        assertFalse(DndGuard.needsPermissionPrompt(policyEnabled = true, waived = false, granted = true))
    }

    @Test
    fun `waiver sesi ini tidak memunculkan dialog lagi`() {
        // Tanpa ini dialognya muncul lagi tiap kali pengawas menekan Mulai Ujian
        // setelah memilih "Lewati" — persis jebakan yang sudah ada di overlay.
        assertFalse(DndGuard.needsPermissionPrompt(policyEnabled = true, waived = true, granted = false))
    }

    @Test
    fun `kebijakan dimatikan server tidak memunculkan dialog`() {
        assertFalse(DndGuard.needsPermissionPrompt(policyEnabled = false, waived = false, granted = false))
    }

    // --- Kapan filter benar-benar boleh diubah ---

    @Test
    fun `filter diterapkan saat kebijakan aktif dan izin ada`() {
        assertTrue(DndGuard.shouldApply(policyEnabled = true, granted = true))
    }

    @Test
    fun `tanpa izin filter tidak boleh disentuh`() {
        // setInterruptionFilter tanpa Notification Policy Access melempar
        // SecurityException; memanggilnya tetap hanya menghasilkan log sampah.
        assertFalse(DndGuard.shouldApply(policyEnabled = true, granted = false))
    }

    @Test
    fun `kebijakan dimatikan berarti DND perangkat dibiarkan apa adanya`() {
        // Sekolah yang mematikan kebijakan ini tidak boleh mendapati DND
        // perangkatnya diubah diam-diam oleh aplikasi ujian.
        assertFalse(DndGuard.shouldApply(policyEnabled = false, granted = true))
    }

    // --- Menyimpan filter sebelumnya: HANYA sekali per sesi ---

    @Test
    fun `simpan pertama merekam filter asli perangkat`() {
        assertEquals(
            DndGuard.FILTER_ALL,
            DndGuard.filterToSave(existingSaved = DndGuard.NO_SAVED_FILTER, currentFilter = DndGuard.FILTER_ALL)
        )
    }

    @Test
    fun `penegasan ulang tidak menimpa filter asli yang sudah tersimpan`() {
        // onResume menegaskan ulang DND di tengah ujian. Bila penegasan itu ikut
        // menyimpan, yang tersimpan adalah ALARMS — filter yang kita pasang
        // sendiri — dan perangkat tidak pernah kembali ke keadaan semula.
        assertEquals(
            DndGuard.FILTER_ALL,
            DndGuard.filterToSave(existingSaved = DndGuard.FILTER_ALL, currentFilter = DndGuard.FILTER_ALARMS)
        )
    }

    @Test
    fun `perangkat yang memang sudah DND tetap tersimpan apa adanya`() {
        assertEquals(
            DndGuard.FILTER_PRIORITY,
            DndGuard.filterToSave(existingSaved = DndGuard.NO_SAVED_FILTER, currentFilter = DndGuard.FILTER_PRIORITY)
        )
    }

    @Test
    fun `filter yang tidak terbaca disimpan sebagai FILTER_ALL`() {
        // Kalau currentInterruptionFilter melempar tapi setInterruptionFilter
        // berhasil, tanpa ini tidak ada apa pun yang tersimpan dan perangkat
        // tertinggal senyap SELAMANYA. FILTER_ALL adalah keadaan bawaan Android
        // (tanpa DND): memulihkan ke sana jauh lebih murah daripada tidak punya
        // jalan pulang sama sekali.
        assertEquals(
            DndGuard.FILTER_ALL,
            DndGuard.filterToSave(existingSaved = DndGuard.NO_SAVED_FILTER, currentFilter = DndGuard.NO_SAVED_FILTER)
        )
    }

    // --- Pemulihan setelah aplikasi mati mendadak ---

    @Test
    fun `filter tersimpan dipulihkan saat aplikasi dibuka kembali`() {
        // Aplikasi dibunuh di tengah ujian: restore tidak pernah jalan dan
        // perangkat tertinggal senyap selamanya. Ini jaring pengamannya.
        assertEquals(DndGuard.FILTER_ALL, DndGuard.staleFilterToRestore(DndGuard.FILTER_ALL))
    }

    @Test
    fun `tanpa filter tersimpan tidak ada yang dipulihkan`() {
        assertNull(DndGuard.staleFilterToRestore(DndGuard.NO_SAVED_FILTER))
    }

    // --- Status untuk heartbeat pengawas ---

    @Test
    fun `filter alarms aktif dilaporkan on`() {
        assertEquals("on", DndGuard.statusFor(policyEnabled = true, currentFilter = DndGuard.FILTER_ALARMS))
    }

    @Test
    fun `kebijakan aktif tapi filter tidak terpasang dilaporkan waived`() {
        // Inilah sinyal yang dicari pengawas: kebijakan menuntut senyap, tapi
        // perangkat ini tidak senyap — entah karena dilewati saat setup atau
        // izinnya dicabut di tengah ujian. Keduanya sama gentingnya.
        assertEquals("waived", DndGuard.statusFor(policyEnabled = true, currentFilter = DndGuard.FILTER_ALL))
    }

    @Test
    fun `kebijakan dimatikan dilaporkan off`() {
        assertEquals("off", DndGuard.statusFor(policyEnabled = false, currentFilter = DndGuard.FILTER_ALL))
    }

    @Test
    fun `kebijakan dimatikan tetap off walau perangkat kebetulan senyap`() {
        assertEquals("off", DndGuard.statusFor(policyEnabled = false, currentFilter = DndGuard.FILTER_ALARMS))
    }

    @Test
    fun `filter tak terbaca saat kebijakan aktif dilaporkan waived`() {
        assertEquals("waived", DndGuard.statusFor(policyEnabled = true, currentFilter = DndGuard.NO_SAVED_FILTER))
    }
}
