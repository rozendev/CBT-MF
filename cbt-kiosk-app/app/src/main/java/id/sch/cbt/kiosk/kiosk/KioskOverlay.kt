package id.sch.cbt.kiosk.kiosk

import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.graphics.PixelFormat
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.util.Log
import android.view.LayoutInflater
import android.view.View
import android.view.WindowManager
import id.sch.cbt.kiosk.MainActivity
import id.sch.cbt.kiosk.R

/**
 * Penutup layar penuh yang digambar di atas aplikasi apa pun ketika siswa
 * keluar dari layar ujian.
 *
 * Kenapa jendela overlay dan bukan meluncurkan ulang activity: terbukti di
 * perangkat (logcat 2026-09-07) bahwa `startActivity()` dari
 * [KioskGuardService] SELALU dibatalkan Android —
 * `Background activity launch blocked ... (BAL_BLOCK) result code=3`. Foreground
 * service bukan pengecualian BAL, dan panggilan itu tidak melempar exception
 * apa pun, jadi kegagalannya senyap sempurna. Jendela overlay tidak lewat jalur
 * itu sama sekali: ia digambar langsung oleh WindowManager.
 *
 * Efek sampingnya menguntungkan: begitu overlay ini tampil, aplikasi PUNYA
 * jendela terlihat, sehingga pengecualian BAL "the app has a visible window"
 * berlaku dan `startActivity()` milik guard service mulai berhasil. Overlay
 * inilah yang membuka kembali jalur yang tadinya buntu.
 *
 * Batasnya jujur: Android sengaja menyembunyikan overlay aplikasi ketika
 * Settings berada di depan (anti-tapjacking), jadi siswa yang masuk Settings
 * tidak akan melihat penutup ini. Deteksi dan sirene tetap berjalan.
 */
object KioskOverlay {

    private const val TAG = "KioskOverlay"

    private val main = Handler(Looper.getMainLooper())

    @Volatile
    private var view: View? = null

    val isShowing: Boolean get() = view != null

    /**
     * Izin diberikan pengguna dan bisa DICABUT kapan saja, termasuk di tengah
     * ujian. Karena itu selalu ditanyakan ulang, tidak pernah di-cache.
     */
    fun isGranted(context: Context): Boolean = try {
        Settings.canDrawOverlays(context)
    } catch (e: Throwable) {
        Log.w(TAG, "Gagal membaca status izin overlay", e)
        false
    }

    @SuppressLint("InflateParams")
    fun show(context: Context) {
        val app = context.applicationContext
        main.post {
            if (view != null) return@post
            if (!isGranted(app)) {
                // Bukan kejutan: izin bisa dicabut siswa saat ujian berjalan.
                Log.w(TAG, "Izin overlay tidak ada — penutup layar tidak dapat ditampilkan")
                return@post
            }
            try {
                val wm = app.getSystemService(Context.WINDOW_SERVICE) as? WindowManager
                    ?: return@post
                val root = LayoutInflater.from(app).inflate(R.layout.overlay_kiosk_block, null)

                root.findViewById<View>(R.id.btnOverlayReturn)?.setOnClickListener {
                    try {
                        app.startActivity(
                            Intent(app, MainActivity::class.java).apply {
                                addFlags(
                                    Intent.FLAG_ACTIVITY_NEW_TASK or
                                        Intent.FLAG_ACTIVITY_CLEAR_TOP or
                                        Intent.FLAG_ACTIVITY_SINGLE_TOP
                                )
                            }
                        )
                    } catch (e: Throwable) {
                        Log.e(TAG, "Gagal kembali ke ujian dari overlay", e)
                    }
                    // Sengaja TIDAK menyembunyikan penutup di sini. Guard service
                    // yang menutupnya begitu activity benar-benar terlihat; kalau
                    // peluncurannya gagal, penutup tetap ada alih-alih meninggalkan
                    // siswa di layar bebas.
                }

                val params = WindowManager.LayoutParams(
                    WindowManager.LayoutParams.MATCH_PARENT,
                    WindowManager.LayoutParams.MATCH_PARENT,
                    WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
                    // NOT_FOCUSABLE: sentuhan tetap diterima (fokus bukan sentuh),
                    // tapi kita tidak merebut fokus tombol/IME dari sistem.
                    WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or
                        WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN or
                        WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS or
                        WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON,
                    PixelFormat.TRANSLUCENT
                )

                wm.addView(root, params)
                view = root
                Log.w(TAG, "Penutup layar ditampilkan")
            } catch (e: Throwable) {
                Log.e(TAG, "Gagal menampilkan penutup layar", e)
                view = null
            }
        }
    }

    fun hide() {
        main.post {
            val current = view ?: return@post
            view = null
            try {
                val wm = current.context.applicationContext
                    .getSystemService(Context.WINDOW_SERVICE) as? WindowManager
                wm?.removeView(current)
                Log.w(TAG, "Penutup layar disembunyikan")
            } catch (e: Throwable) {
                Log.e(TAG, "Gagal menyembunyikan penutup layar", e)
            }
        }
    }
}
