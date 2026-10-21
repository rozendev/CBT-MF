# Admin import worker implementation plan

> **For agentic workers:** Use superpowers:executing-plans to implement inline; follow test-driven-development and verification-before-completion.

**Goal:** Isolate student/Word imports from exam requests without schema changes or changes to core models/exam components.

**Architecture:** Private file-backed jobs under writable/imports, atomic metadata writes, a single flock-protected PHP CLI worker, and bounded Docker resources. Web endpoints enqueue uploads and expose owner-scoped progress; browsers never execute batches.

**Tech stack:** Existing PHP/CodeIgniter, PhpSpreadsheet/PhpWord, filesystem flock/rename, Docker Compose. No new packages.

**Spec:** User-approved approach in this conversation, constrained to development/testing, no database schema/core changes.

## Global constraints

- No migrations, new tables, core model changes, or exam/WebSocket/Redis changes.
- Existing import semantics and password hashing remain intact.
- One worker globally; maximum 10 unfinished jobs; 10 MiB Excel / 5 MiB Word uploads.
- At most three retries after the initial attempt, deterministic exponential delays, bounded upload retention.
- At-least-once recovery: domain duplicate checks prevent duplicate records after a crash; progress checkpoints are file-backed.
- Preserve unrelated existing working-tree changes; development/testing only, no deployment. Commit/push and PR were authorized after implementation.

## Review focus

- Worker crash after a database commit but before a checkpoint: replay is idempotent; exact result counts across this window cannot be atomic without database changes.
- Multiple admins/tabs and simultaneous worker processes must not execute imports concurrently.
- Files, credentials, internal paths and another owner's job must not be exposed by polling.
- Dependency outage, corrupt uploads and permanent validation failures must not create infinite retries.
- Browser timeout/closure must not restart or abandon an accepted job.

### Task 1: Durable private queue and worker lifecycle

Files: Libraries/Import/{ImportQueue,ImportFailure,ImportWorker}.php; Commands/ImportWork.php; tests/Import/*.

Interfaces: enqueue(kind, owner, path, options): array; status(id, owner): ?array; runOne(processor): bool; checkpoint(id, progress): void.

- [x] Write failing tests for persistence, deduplication, ownership, capacity, global locking, interrupted-job recovery and retry exhaustion.
- [x] Implement atomic file metadata, private staged uploads, nonblocking worker lock, bounded deterministic retry and TTL cleanup.
- [x] Run queue tests and inspect failures; expected all pass.

### Task 2: Existing import behavior behind the queue

Files: Libraries/Import/{StudentImporter,WordImporter,ImportUpload}.php; Admin/{UserController,WordImportController,ImportController}.php; Config/Routes.php.

- [x] Write tests exercising spreadsheet parsing/checkpoints and unchanged Word triage/summary behavior with controlled database boundaries.
- [x] Move parsing and password work to worker processors; retain existing models, duplicate/reuse behavior, and atomic question import.
- [x] Make legacy batch endpoint status-only; add owner-scoped GET status, upload limits and stable submission identity.
- [x] Test parsing, rejected uploads, checkpoint resume, progress response and owner/role boundaries.

### Task 3: Polling UI, bounded dev service and verification

Files: assets/admin-import.js; admin users/questions import views; docker-compose.yml; docs/admin-import-worker.md.

- [x] Write failing JS tests for polling without batch POST, deterministic retry cap, pending persistence and permanent errors.
- [x] Add shared submit/poll helper, progress displays/resume, and one worker service with configurable 0.5 CPU / 256 MiB limits.
- [x] Run full PHP suites, JS regressions, syntax/Compose checks, and dev integration/restart/resource-isolation checks with temporary fixtures.
- [x] Review the final diff and report measured results and any limitations; no live patch/build of exam assets.

## Verification and review record

- Unit PHP in the development checkout: 244 tests / 1,341 assertions passed.
- PR isolated on latest `main` without the separate theme work: 197 unit tests / 644 assertions, 5 integration tests / 35 assertions, 17 throttling tests / 73 assertions, and 32 JavaScript tests passed (251 tests total).
- Import integration: 5 tests / 35 assertions passed; fixture rollback is asserted.
- Throttling: 17 tests / 73 assertions passed. JavaScript: 32 tests passed.
- Bounded-container integration: 5 tests passed at 0.5 CPU / 256 MiB / UID 33.
- Health smoke: baseline 20/20 healthy, p95 29.35 ms; during bounded worker tests 20/20 healthy, p95 48.32 ms. No full autosave load benchmark claimed.
- Fresh read-only review: five Important findings fixed with regression coverage (SQL transaction status, image-only Word replay, lost upload response, concurrent tabs, orphan files). Excel formatting compatibility also covered.
- Ruling: file-backed spool instead of database job metadata/Redis queue keeps the accepted no-schema/no-core constraint; it requires shared local storage and does not recover from losing that storage.
- Ruling: one fresh CLI process per job, supervised by Compose, avoids carrying stale DB connections/parser allocations across jobs. Idle checks every two seconds and suppress CLI banners.
- Ruling: student commit/checkpoint counters remain at-least-once; duplicate detection protects records, but the last row can count as duplicate after a crash. Word uses a pre-commit receipt, including image-only questions.
- A deliberate SQL-error fixture initially escaped its outer rollback due to CI debug handling. Three exact synthetic accounts and one test image were cleaned up; fixture isolation was corrected and teardown now asserts no fixture records remain.
- No migrations, core model edits, or new changes to exam/WebSocket/Redis components. Dev import-worker enabled separately; existing services were not restarted.
