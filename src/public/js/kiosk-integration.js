document.addEventListener("DOMContentLoaded", function() {
    function getKioskToken() {
        return sessionStorage.getItem("cbt_kiosk_ws_token") || "";
    }

    // Persist the ws_token across pages so finish/results pages can verify exit.
    if (window.CBT_EXAM_CONFIG && window.CBT_EXAM_CONFIG.token) {
        sessionStorage.setItem("cbt_kiosk_ws_token", window.CBT_EXAM_CONFIG.token);
    }

    function hasFinishedExam() {
        return sessionStorage.getItem("cbt_kiosk_exam_finished") === "1" && getKioskToken() !== "";
    }

    /**
     * Minta native melepas kiosk. Hanya dipanggil dari aksi eksplisit siswa
     * (tombol "Keluar dari Ujian") atau saat ujian memang sudah selesai.
     *
     * Dulu fungsi ini dipanggil otomatis di halaman login/dashboard hanya
     * berdasarkan nama path. Akibatnya saat aplikasi BARU masuk kiosk, login.html
     * langsung meminta exit tanpa token, server menolak, dan native membunyikan
     * alarm -- sirene meraung tepat ketika ujian belum dimulai.
     */
    window.CBTKioskRequestExit = function () {
        if (!window.CommsBridge) return false;
        window.CommsBridge.requestExit(getKioskToken());
        return true;
    };

    window.CBTKioskHasFinishedExam = hasFinishedExam;

    // If native CommsBridge is available
    if (window.CommsBridge) {
        // If student is taking an exam, trigger startKiosk
        if (window.CBT_EXAM_CONFIG && !window.CBT_EXAM_FINISHED) {
            window.CommsBridge.startKiosk(
                window.CBT_EXAM_CONFIG.examId || "0",
                window.CBT_EXAM_CONFIG.token || ""
            );
        } else if (window.CBT_EXAM_FINISHED && hasFinishedExam()) {
            // Halaman lama (non-bundle) yang menandai ujian selesai sendiri.
            window.CBTKioskRequestExit();
        }
    }

    // Kiosk bundle mode: config tersedia setelah exam/init resolve.
    window.addEventListener("kiosk_config_ready", function() {
        if (window.CommsBridge && window.CBT_EXAM_CONFIG && !window.CBT_EXAM_FINISHED) {
            window.CommsBridge.startKiosk(
                window.CBT_EXAM_CONFIG.examId || "0",
                window.CBT_EXAM_CONFIG.token || ""
            );
        }
    });

    window.addEventListener("kiosk_started", function(e) {
        console.log("Kiosk mode activated", e.detail);
        sendKioskWsEvent("kiosk_status", "started", e.detail);
    });

    window.addEventListener("kiosk_failed", function(e) {
        console.error("Kiosk mode failed", e.detail);
        sendKioskWsEvent("kiosk_event", "kiosk_failed", e.detail);
    });

    window.addEventListener("exit_attempt", function(e) {
        console.warn("User attempted to exit kiosk", e.detail);
        sendKioskWsEvent("kiosk_event", "exit_attempt", e.detail);
    });

    window.addEventListener("exit_denied", function(e) {
        console.warn("Kiosk exit request was denied", e.detail);
        sendKioskWsEvent("kiosk_event", "exit_denied", e.detail);
    });

    window.addEventListener("security_alert", function(e) {
        console.error("Security alert received", e.detail);
        sendKioskWsEvent("kiosk_event", "security_alert", e.detail);
    });

    window.addEventListener("kiosk_stop", function() {
        window.CBTKioskRequestExit();
    });

    window.addEventListener("kiosk_close", function() {
        if (window.CommsBridge) {
            window.CommsBridge.closeApp();
        }
    });

    function sendKioskWsEvent(action, eventType, detail) {
        const ws = window.examWebSocket || window.ws;
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({
                action: action,
                type: eventType,
                data: detail || {}
            }));
        }
    }
});