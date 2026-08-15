document.addEventListener("DOMContentLoaded", function() {
    function getKioskToken() {
        return sessionStorage.getItem("cbt_kiosk_ws_token") || "";
    }

    // Persist the ws_token across pages so finish/results pages can verify exit.
    if (window.CBT_EXAM_CONFIG && window.CBT_EXAM_CONFIG.token) {
        sessionStorage.setItem("cbt_kiosk_ws_token", window.CBT_EXAM_CONFIG.token);
    }

    // If native CommsBridge is available
    if (window.CommsBridge) {
        // If student is taking an exam, trigger startKiosk
        if (window.CBT_EXAM_CONFIG && !window.CBT_EXAM_FINISHED) {
            window.CommsBridge.startKiosk(
                window.CBT_EXAM_CONFIG.examId || "0",
                window.CBT_EXAM_CONFIG.token || ""
            );
        } else if (
            window.CBT_EXAM_FINISHED ||
            window.location.pathname.includes('/results/') ||
            window.location.pathname.includes('/dashboard') ||
            window.location.pathname.includes('/login') ||
            window.location.pathname.includes('/logout')
        ) {
            // Request verified exit: the native app only unlocks after the
            // server confirms this exam session is genuinely finished.
            console.log("Exam finished or student on non-exam page. Requesting kiosk exit...");
            window.CommsBridge.requestExit(getKioskToken());
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
        if (window.CommsBridge) {
            window.CommsBridge.requestExit(getKioskToken());
        }
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