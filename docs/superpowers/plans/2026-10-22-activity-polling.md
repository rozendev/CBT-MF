# Activity-aware polling implementation plan

**Goal:** Allocate network requests per task and active page, avoiding uniform background traffic.

**Architecture:** A small shared browser scheduler manages page visibility, idle time, offline state, pagehide/pageshow, deterministic jitter, sequential jobs, cancellation, Retry-After, and a shared 500 ms request-start budget. Existing exam transports retain their failure handling and use the scheduler's lifecycle/pacing policy. No database, core model, or native kiosk heartbeat changes.

**Policy:** Dashboard presence 10 seconds; other-page presence 60 seconds; reports/live status 10–30 seconds; import running 3 seconds/queued 10 seconds; hidden tabs 180–210 seconds. Visible idle pages slow after two minutes. Closed pages send zero polling requests. Autosave remains event-driven; database sync occurs only after successful answer changes, at most once per minute, with bounded retries.

- [x] Write failing scheduler tests for task budgets, hidden/idle/offline, no overlaps, pagehide cancellation/BFCache restore, jitter, pacing and retry exhaustion.
- [x] Implement the scheduler and wire layouts, monitoring, import progress, and kiosk assets.
- [x] Write failing exam tests for lifecycle-aware fallback, change-driven sync, and pacing; implement both clients and fallback renderer.
- [x] Verify PHP/JS suites, syntax, bundle build and independent review. Document exact cadence and limits.
- [x] Prepare focused commit and update/create PR for user merge; preserve unrelated local work.

**Verification:** 89 JavaScript tests; PHP default 197 tests/644 assertions, import 5/35,
throttling 17/73, realtime 3/19. All pass (311 tests total). PHP and JavaScript
syntax checks pass; kiosk bundle builds at 98,336 bytes, below the 300 KB budget.
Independent review findings on dashboard aliases, network timeout placement,
Retry-After, BFCache queue cancellation, and autosave replay were fixed and covered
by regression tests. Load capacity was not benchmarked.
