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

    var enforceMaxVolume: Boolean
        get() = isSirenMaxVolume
        set(value) { isSirenMaxVolume = value }

    private var audioTrack: AudioTrack? = null
    private var alarmThread: Thread? = null

    fun startSiren(context: Context, enforceMaxVolume: Boolean = isSirenMaxVolume) {
        if (!isSirenEnabled) return
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

    fun stopSiren() {
        isPlaying = false
        try {
            alarmThread?.interrupt()
        } catch (e: Throwable) {}
        alarmThread = null
    }
}
