document.addEventListener("DOMContentLoaded", function() {
    // If native CommsBridge is available and student is taking an exam, trigger startKiosk
    if (window.CommsBridge && window.CBT_EXAM_CONFIG) {
        window.CommsBridge.startKiosk(
            window.CBT_EXAM_CONFIG.examId || "0",
            window.CBT_EXAM_CONFIG.token || ""
        );
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
