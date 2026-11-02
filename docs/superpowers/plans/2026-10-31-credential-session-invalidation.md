# Credential Session Invalidation & Active Exam Role Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Immediately invalidate sessions when credentials/role/active-status change for `admin`/`guru` (or role/deactivation for any user), enforce a 10-minute grace period with WebSocket/polling warning and pre-logout answer flush when `siswa` credentials (`username`/`password`) change, ignore profile-only edits, and block changing a `siswa`'s role while taking an exam (`test_attempts.status IN (1, 2)`).

**Architecture:** Add `auth_version`, `cred_version`, and `cred_grace_until` to `users` as the durable source of truth, backed by a 7200s Redis state cache (`user_auth_state:{id}`) with PK lazy fallback on cache miss. Pure decision rules live in `App\Libraries\SessionInvalidation`, I/O coordination in `App\Libraries\UserAuthState`, request enforcement in global `App\Filters\SessionGuardFilter`, and real-time student warnings + pre-expiry answer flush in WebSocket `cred_updated`, `ExamStatusController`, `exam-app.js`, `take.php`, and `static_exam_template.php`.

**Tech Stack:** PHP 8.x / CodeIgniter 4.7, MariaDB, Redis (`phpredis` + `clue/redis-react`), Ratchet WebSocket, Alpine.js / Vanilla JS, PHPUnit, Node `node:test`.

## Global Constraints

- CSRF token regeneration (`Config\Security::$regenerate = false`) MUST remain untouched.
- Changing non-credential profile fields (`firstname`, `lastname`, `email`, `birthdate`, `birthplace`, `registration_number`, `ssn`, `groups`) MUST NOT revoke sessions or emit warnings.
- Changing `siswa` role to any other role while `test_attempts.status IN (1, 2)` MUST be rejected before DB/Redis mutation.
- Kiosk bundle (`window.__KIOSK_BUNDLE__`) MUST redirect to `'login.html'` (never escaping the Kiosk WebViewAssetLoader bundle to remote `/login`).
- `WebSocketServerHandler` MUST NOT forward `'cred_updated'` to `broadcastToProctors()`.

---

### Task 1: Pure Decision Logic (`SessionInvalidation`) & Unit Tests

**Files:**
- Create: `src/app/Libraries/SessionInvalidation.php`
- Test: `src/tests/Resilience/SessionInvalidationTest.php`

**Interfaces:**
- Produces:
  - `SessionInvalidation::VALID`, `GRACE`, `REVOKED`, `STUDENT_GRACE_SECONDS = 600`, `STATE_TTL_SECONDS = 7200`
  - `SessionInvalidation::CHANGE_NONE`, `CHANGE_REFRESH_ONLY`, `CHANGE_IMMEDIATE_REVOKE`, `CHANGE_STUDENT_GRACE`
  - `SessionInvalidation::stateKey(int|string $userId): string`
  - `SessionInvalidation::normalizeVersion(mixed $version): int`
  - `SessionInvalidation::classifyUserUpdate(object $oldUser, array $newData): string`
  - `SessionInvalidation::evaluate(?int $sessionAuthVer, ?int $sessionCredVer, array $state, int $nowTs): array`
  - `SessionInvalidation::buildCredUpdatedPayload(int $userId, int $credVersion, int $graceUntilTs, int $nowTs): array`

- [x] **Step 1: Write unit tests in `src/tests/Resilience/SessionInvalidationTest.php`** covering legacy null versions, profile-only edits, admin/guru immediate revoke, role/deactivation immediate revoke, student 600s grace window, student re-login during grace, and pre-ban session non-rescue.
- [x] **Step 2: Implement `src/app/Libraries/SessionInvalidation.php`** with zero I/O dependencies.

---

### Task 2: Database Migration, `UserModel`, & `UserAuthState` Coordinator

**Files:**
- Create: `src/app/Database/Migrations/2026-10-31-100000_AddAuthVersioningToUsers.php`
- Modify: `src/app/Models/UserModel.php`
- Create: `src/app/Libraries/UserAuthState.php`

**Interfaces:**
- Consumes: `SessionInvalidation`, `RedisClient`, `SessionTakeover`
- Produces:
  - `UserAuthState::fromUserRow(object $user): array`
  - `UserAuthState::writeCache(int $userId, array $state): void`
  - `UserAuthState::invalidateCache(int $userId): void`
  - `UserAuthState::resolve(int $userId): ?array`
  - `UserAuthState::isStudentTakingExam(int $userId): bool`
  - `UserAuthState::clearSessionTrackingIfOwned(int $userId, ?string $sessionLoginToken): void`
  - `UserAuthState::revokeImmediatelyInRedis(int $userId, array $state, bool $preserveBannedSentinel = false, ?string $wsKickMessage = null): void`
  - `UserAuthState::notifyStudentGraceInRedis(int $userId, array $state, int $nowTs): void`

- [x] **Step 1: Create migration `2026-10-31-100000_AddAuthVersioningToUsers.php`** adding `auth_version`, `cred_version`, and `cred_grace_until` to `users`.
- [x] **Step 2: Update `src/app/Models/UserModel.php`** `$allowedFields` and `reuseDeletedUser()` to bump `auth_version`, clear `cred_grace_until`, and invalidate `user_auth_state:{id}`.
- [x] **Step 3: Implement `src/app/Libraries/UserAuthState.php`**.

---

### Task 3: Global `SessionGuardFilter`, Login Snapshot, Admin User Controller, & Cross-Feature Mutators

**Files:**
- Create: `src/app/Filters/SessionGuardFilter.php`
- Modify: `src/app/Config/Filters.php`
- Modify: `src/app/Controllers/Auth/AuthController.php`
- Modify: `src/app/Controllers/Admin/UserController.php`
- Modify: `src/app/Libraries/ProctorAction.php`
- Modify: `src/app/Libraries/ExamService.php`
- Modify: `src/app/Controllers/Admin/SuspendController.php`
- Modify: `src/app/Libraries/Import/StudentImporter.php`

- [x] **Step 1: Create `SessionGuardFilter.php` and register `'sessionguard'` in `Config\Filters`** (`$aliases` + `$globals['before']`).
- [x] **Step 2: Store `auth_version` and `cred_version` in session & warm Redis state in `AuthController::attemptLogin()`**.
- [x] **Step 3: Add `isStudentTakingExam` role guard, `classifyUserUpdate` handling, self-edit immediate destroy, and delete/bulkDelete revocation in `Admin\UserController`**.
- [x] **Step 4: Keep `user_auth_state:{id}` & `auth_version` synchronized in `ProctorAction::lockAccount()`, `ExamService` auto-bans, `SuspendController::_doRelease()`, and `StudentImporter::persist()`**.

---

### Task 4: WebSocket Isolation, Polling Fallback, & Exam Client Grace Handling (TDD)

**Files:**
- Modify: `src/app/Libraries/WebSocketServerHandler.php`
- Modify: `src/app/Controllers/Api/ExamStatusController.php`
- Create: `src/tests/Resilience/credential-grace.test.cjs`
- Modify: `src/public/assets/exam-app.js`
- Modify: `src/app/Views/student/exam/take.php`
- Modify: `src/app/Views/admin/static/static_exam_template.php`

- [x] **Step 1: Write failing Node tests in `src/tests/Resilience/credential-grace.test.cjs`** covering WS `cred_updated`, polling `cred_grace_until_ms` deduplication, server-clock offset handling, pre-expiry pending-save flush, Kiosk `'login.html'` vs web `/login` redirect, and `stopRealtime()` timer cleanup.
- [x] **Step 2: Run `node --test src/tests/Resilience/credential-grace.test.cjs` and verify RED failure.**
- [x] **Step 3: Update `WebSocketServerHandler.php`, `ExamStatusController.php`, `exam-app.js`, `take.php`, and `static_exam_template.php`.**
- [x] **Step 4: Run `node --test src/tests/Resilience/*.test.cjs src/tests/Import/*.test.cjs` and verify 100% GREEN.**
