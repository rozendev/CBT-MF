document.addEventListener("DOMContentLoaded", function() {
    // If native CommsBridge is available
    if (window.CommsBridge) {
        // If student is taking an exam, trigger startKiosk
        if (window.CBT_EXAM_CONFIG && !window.CBT_EXAM_FINISHED) {
            if (window.CBT_EXAM_CONFIG.exitPassword) {
                window.CommsBridge.setExitPassword(window.CBT_EXAM_CONFIG.exitPassword);
            }
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
            // Unlock kiosk mode when exam is finished, or student lands on results/dashboard/login page
            console.log("Exam finished or student on non-exam page. Unlocking kiosk mode...");
            window.CommsBridge.stopKiosk();
        }
    }

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

    window.addEventListener("security_alert", function(e) {
        console.error("Security alert received", e.detail);
        sendKioskWsEvent("kiosk_event", "security_alert", e.detail);
    });

    window.addEventListener("kiosk_stop", function() {
        if (window.CommsBridge) {
            window.CommsBridge.stopKiosk();
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
