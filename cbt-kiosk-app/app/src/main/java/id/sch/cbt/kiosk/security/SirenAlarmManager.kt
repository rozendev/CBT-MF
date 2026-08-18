package id.sch.cbt.kiosk.security

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioTrack
import android.util.Log
import kotlin.concurrent.thread
import kotlin.math.sin

object SirenAlarmManager {
    @Volatile
    var isPlaying = false
        private set

    @Volatile
    var isSirenEnabled: Boolean = true

    @Volatile
    var isSirenMaxVolume: Boolean = true

    /**
     * Bunyi peringatan pendek ("titung") untuk percobaan keluar yang GAGAL --
     * tekan back, tarik notification shade, dsb. Sirene panjang disimpan khusus
     * untuk kasus siswa benar-benar lolos dari layar yang di-pin.
     */
    @Volatile
    var isWarningBeepEnabled: Boolean = true

    /**
     * Selama transisi masuk kiosk, Android sempat mem-pause activity (dialog
     * "Layar dinyematkan", animasi lock task). Tanpa peredam ini alarm berbunyi
     * tepat saat kiosk baru menyala -- persis keluhan di lapangan.
     */
    @Volatile
    private var suppressUntilMs: Long = 0L

    private const val BEEP_DEBOUNCE_MS = 1200L

    @Volatile
    private var lastBeepAtMs: Long = 0L

    @Volatile
    private var beepTrack: AudioTrack? = null

    /** Redam semua alarm selama [durationMs] ke depan. */
    fun suppressFor(durationMs: Long) {
        suppressUntilMs = System.currentTimeMillis() + durationMs
    }

    fun clearSuppression() {
        suppressUntilMs = 0L
    }

    private fun isSuppressed(): Boolean = System.currentTimeMillis() < suppressUntilMs

    var enforceMaxVolume: Boolean
        get() = isSirenMaxVolume
        set(value) { isSirenMaxVolume = value }

    private var audioTrack: AudioTrack? = null
    private var alarmThread: Thread? = null

    fun startSiren(context: Context, enforceMaxVolume: Boolean = isSirenMaxVolume) {
        if (!isSirenEnabled) return
        if (isSuppressed()) return
        if (isPlaying) return
        isPlaying = true

        if (enforceMaxVolume) {
            try {
                val audioManager = context.getSystemService(Context.AUDIO_SERVICE) as? AudioManager
                audioManager?.let { am ->
                    // Maximizing all stream volumes for loud siren
                    val maxAlarmVol = am.getStreamMaxVolume(AudioManager.STREAM_ALARM)
                    am.setStreamVolume(AudioManager.STREAM_ALARM, maxAlarmVol, 0)

                    val maxMusicVol = am.getStreamMaxVolume(AudioManager.STREAM_MUSIC)
                    am.setStreamVolume(AudioManager.STREAM_MUSIC, maxMusicVol, 0)
                }
            } catch (e: Throwable) {
                Log.e("SirenAlarmManager", "Error setting max volume", e)
            }
        }

        alarmThread = thread(start = true, isDaemon = true, name = "SirenAudioThread") {
            val sampleRate = 44100
            val minBufSize = AudioTrack.getMinBufferSize(
                sampleRate,
                AudioFormat.CHANNEL_OUT_MONO,
                AudioFormat.ENCODING_PCM_16BIT
            )
            val bufferSize = if (minBufSize > 0) minBufSize * 2 else 4096

            try {
                audioTrack = if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.M) {
                    AudioTrack.Builder()
                        .setAudioAttributes(
                            AudioAttributes.Builder()
                                .setUsage(AudioAttributes.USAGE_ALARM)
                                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                                .build()
                        )
                        .setAudioFormat(
                            AudioFormat.Builder()
                                .setEncoding(AudioFormat.ENCODING_PCM_16BIT)
                                .setSampleRate(sampleRate)
                                .setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
                                .build()
                        )
                        .setBufferSizeInBytes(bufferSize)
                        .setTransferMode(AudioTrack.MODE_STREAM)
                        .build()
                } else {
                    @Suppress("DEPRECATION")
                    AudioTrack(
                        AudioManager.STREAM_ALARM,
                        sampleRate,
                        AudioFormat.CHANNEL_OUT_MONO,
                        AudioFormat.ENCODING_PCM_16BIT,
                        bufferSize,
                        AudioTrack.MODE_STREAM
                    )
                }

                audioTrack?.play()

                val buffer = ShortArray(bufferSize / 2)
                var angle = 0.0
                var freq = 600.0
                var increasing = true

                while (isPlaying) {
                    // Oscillate frequency between 600 Hz and 1400 Hz (Police Siren Effect)
                    if (increasing) {
                        freq += 25.0
                        if (freq >= 1400.0) increasing = false
                    } else {
                        freq -= 25.0
                        if (freq <= 600.0) increasing = true
                    }

                    for (i in buffer.indices) {
                        val angularFrequency = 2.0 * Math.PI * freq / sampleRate
                        angle += angularFrequency
                        if (angle > 2.0 * Math.PI) angle -= 2.0 * Math.PI
                        buffer[i] = (sin(angle) * Short.MAX_VALUE).toInt().toShort()
                    }

                    audioTrack?.write(buffer, 0, buffer.size)
                }
            } catch (e: Throwable) {
                Log.e("SirenAlarmManager", "AudioTrack play loop error", e)
            } finally {
                try {
                    audioTrack?.stop()
                    audioTrack?.release()
                } catch (e: Throwable) {}
                audioTrack = null
            }
        }
    }

    /**
     * "Titung" pendek sekali jalan: dua nada cepat, bukan sirene. Dipakai untuk
     * memberi tahu siswa bahwa tombol keluar memang tidak berfungsi -- tanpa
     * bikin satu ruangan panik. Sengaja TIDAK menaikkan volume sistem.
     */
    fun playWarningBeep(context: Context) {
        if (!isWarningBeepEnabled) return
        if (isSuppressed()) return
        // Sirene sedang mengaum: beep tidak perlu dan malah saling tumpuk.
        if (isPlaying) return

        val now = System.currentTimeMillis()
        if (now - lastBeepAtMs < BEEP_DEBOUNCE_MS) return
        lastBeepAtMs = now

        thread(start = true, isDaemon = true, name = "KioskWarningBeep") {
            try {
                val sampleRate = 44100
                val samples = buildBeepSamples(sampleRate)

                val track = if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.M) {
                    AudioTrack.Builder()
                        .setAudioAttributes(
                            AudioAttributes.Builder()
                                .setUsage(AudioAttributes.USAGE_ASSISTANCE_SONIFICATION)
                                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                                .build()
                        )
                        .setAudioFormat(
                            AudioFormat.Builder()
                                .setEncoding(AudioFormat.ENCODING_PCM_16BIT)
                                .setSampleRate(sampleRate)
                                .setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
                                .build()
                        )
                        .setBufferSizeInBytes(samples.size * 2)
                        .setTransferMode(AudioTrack.MODE_STATIC)
                        .build()
                } else {
                    @Suppress("DEPRECATION")
                    AudioTrack(
                        AudioManager.STREAM_NOTIFICATION,
                        sampleRate,
                        AudioFormat.CHANNEL_OUT_MONO,
                        AudioFormat.ENCODING_PCM_16BIT,
                        samples.size * 2,
                        AudioTrack.MODE_STATIC
                    )
                }

                beepTrack = track
                track.write(samples, 0, samples.size)
                track.play()

                // MODE_STATIC berhenti sendiri di akhir buffer; tunggu durasinya
                // lalu lepas resource-nya.
                Thread.sleep((samples.size * 1000L / sampleRate) + 120L)
                try { track.stop() } catch (e: Throwable) {}
                track.release()
                if (beepTrack === track) beepTrack = null
            } catch (e: Throwable) {
                Log.e("SirenAlarmManager", "Warning beep failed", e)
            }
        }
    }

    /**
     * Dua nada berurutan: 1180 Hz pendek lalu 820 Hz sedikit lebih panjang.
     * Tiap nada diberi fade in/out singkat supaya tidak "klik" di speaker HP.
     */
    private fun buildBeepSamples(sampleRate: Int): ShortArray {
        val tones = listOf(1180.0 to 70, 820.0 to 110)
        val gapMs = 25
        val total = tones.sumOf { it.second * sampleRate / 1000 } + (gapMs * sampleRate / 1000)
        val out = ShortArray(total)

        var idx = 0
        tones.forEachIndexed { toneIndex, (freq, durationMs) ->
            val count = durationMs * sampleRate / 1000
            val fade = (sampleRate * 6 / 1000).coerceAtMost(count / 2).coerceAtLeast(1)
            for (i in 0 until count) {
                val envelope = when {
                    i < fade -> i.toDouble() / fade
                    i > count - fade -> (count - i).toDouble() / fade
                    else -> 1.0
                }
                val angle = 2.0 * Math.PI * freq * i / sampleRate
                out[idx++] = (sin(angle) * envelope * Short.MAX_VALUE * 0.55).toInt().toShort()
            }
            if (toneIndex == 0) {
                idx += gapMs * sampleRate / 1000
            }
        }
        return out
    }

    fun stopSiren() {
        isPlaying = false
        try {
            alarmThread?.interrupt()
        } catch (e: Throwable) {}
        alarmThread = null
    }
}
