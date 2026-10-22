# WebSocket polling fallback implementation plan

**Goal:** Automatically keep exam status updates working when WebSocket fails.

**Architecture:** Add an authenticated, owner-scoped GET snapshot that reads existing rows without flushing answers. Both exam clients switch to sequential polling on socket errors, missing configuration, connection timeout, or missed heartbeats. Polling stops when WebSocket opens again.

**Constraints:** Development/testing only; no migrations or core model changes. Retain three WebSocket reconnects. First poll staggered within one second, subsequent polls every 10–15 seconds with deterministic jitter. HTTP errors use at most three retries and respect Retry-After. No overlapping poll requests or reconnect timers.

**Review focus:** ownership, ejection without leaving kiosk protection, accurate extended deadlines, stale socket callbacks, and HTTP outage without retry storms.

- [x] Add failing JavaScript regressions for failover, recovery, timeout, cadence, status handling, and bounded HTTP failures.
- [x] Implement fallback lifecycle in `exam-app.js` and dynamic `take.php`.
- [x] Add failing integration tests for owner checks, disabled accounts, locked/finished attempts, and deadline updates; implement `Api/ExamStatusController.php` with a GET route.
- [x] Run the PHP suites, JavaScript regressions, syntax checks, and review the diff. Document fallback behavior.
- [x] Prepare a focused follow-up branch for commit/push and PR review, preserving unrelated local work.

## Verification

- PR branch on main: 197 unit tests / 644 assertions, 5 import integration tests / 35 assertions, 17 throttling tests / 73 assertions, 3 status integration tests / 19 assertions, and 61 JavaScript tests passed (283 total).
- PHP/JavaScript syntax, three inline dynamic scripts, and diff whitespace checks passed. Kiosk bundle built successfully at 95 KB, below the 300 KB budget.
- Independent review fixes verified: server-clock deadline coordinates, stale poll generations/single timer ownership, and authenticated connected acknowledgement before resetting retry budgets.
- No schema changes, core model edits, or service deployment. Browser transports are simulated in regression tests; no full concurrent exam load benchmark was run.
