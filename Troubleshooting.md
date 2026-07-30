# Troubleshooting Log

## [2026-07-30] HTTPS detection failure behind Cloudflare Tunnel
- **Symptom**: "too many redirects" loop when `forceGlobalSecureRequests` is true, and `SecurityException: Attempted to send a secure cookie over a non-secure connection`.
- **Root cause**: PHP-FPM and CodeIgniter 4 could not detect the secure connection because Nginx did not forward any HTTPS signal when receiving plain HTTP from the Cloudflare Tunnel.
- **Fix**: Added `fastcgi_param HTTPS on;` to `docker/nginx/default.conf` and reverted `forceGlobalSecureRequests` to `true` in `src/app/Config/App.php`.
- **Status**: [ ] Unverified

## [2026-07-30] 500 Internal Server Error / Unable to write to cache
- **Symptom**: Application returns HTTP 500 error, sometimes perceived as 502 Bad Gateway by the proxy, with underlying errors stating "Unable to write to cache".
- **Root cause**: The `src/writable` directories lacked the proper ownership and permissions for the `www-data` PHP-FPM user to write files (cache, logs, sessions).
- **Fix**: Set ownership to `www-data:www-data` and permissions to `775` on `writable`, `public/static`, and `public/uploads` inside the PHP container via `docker compose exec php` as per `README.md` guidelines.
- **Status**: [ ] Unverified

## [2026-07-30] Installer script (cbt.sh) permission setup fails
- **Symptom**: `cbt.sh` attempts to run `mkdir`, `chown`, and `chmod` inside the container using `docker exec` but can encounter permission errors or fail to properly apply host-level permissions.
- **Root cause**: Executing permission changes inside the container is less robust than setting them on the host, especially since `cbt.sh` is already required to be run as root (`sudo`).
- **Fix**: Modified `scripts/cbt.sh` to execute `mkdir`, `chown -R :33`, and `chmod -R 775` directly on the host targeting `$PROJECT_DIR/src/...` instead of using `docker exec`.
- **Status**: [ ] Unverified

## [2026-07-30] Bootstrap CSS fails to load with strict MIME checking
- **Symptom**: Requesting `/vendor/bootstrap/css/bootstrap.min.css` returns `Content-Type: text/html` causing the browser to reject it, resulting in an unstyled dashboard UI.
- **Root cause**: The `src/public/vendor/` directory (which contains Bootstrap and other frontend static assets) was accidentally excluded from version control by a `vendor/` rule in the root `.gitignore`. As a result, the files were missing in fresh deployments. When Nginx encounters a missing static file, it returns its default 404 error page, which is HTML format (`text/html`), causing the MIME type mismatch in the browser.
- **Fix**: Replaced the overly broad `vendor/` rule in the root `.gitignore` with `src/vendor/` to correctly ignore only Composer dependencies. This allowed the missing `src/public/vendor/` assets to be tracked by Git and deployed.
- **Status**: [ ] Unverified

## [2026-07-30] PHP Fatal Error in WordImportController
- **Symptom**: `ErrorException: 'continue' not in the 'loop' or 'switch' context` occurs at `APPPATH/Controllers/Admin/WordImportController.php` on line 324 when importing Word documents.
- **Root cause**: A `continue` statement was incorrectly placed inside an `if` block within a standalone function (`processPhpWordElement`), instead of being inside a loop (`foreach`, `for`, `while`). PHP strictly enforces that `continue` can only be used inside loops or switch statements.
- **Fix**: Replaced `continue;` with `return $blocks;` to safely exit the function early and skip the invalid image embedded object without triggering a PHP parsing error.
- **Status**: [ ] Unverified

## [2026-07-30] Maximum execution time exceeded during Bulk Import
- **Symptom**: Importing a large number of students (e.g. 100 rows) causes a `Maximum execution time of 30 seconds exceeded` error at `UserModel.php` because Argon2id password hashing is slow and synchronous.
- **Root cause**: The previous implementation looped over all imported rows and inserted them sequentially within a single HTTP request, causing the script to exceed the default 30s timeout on larger files.
- **Fix**: Migrated to a Redis-backed batch processing architecture. The initial file upload now merely parses rows into a Redis List (`RPUSH`) with a 1-hour TTL, and the frontend sequentially issues AJAX requests to a new `/admin/users/import-batch` endpoint to process users in chunks of 5. Added 3-strike frontend timeout abort logic to handle network or execution delays without race conditions.
- **Status**: [ ] Unverified

## [2026-07-30] Batch Import Frontend Timeout (403 Forbidden CSRF)
- **Symptom**: The new batch import logic fails abruptly with a "timeout" message even on fast systems. The backend Nginx logs show `403` responses for `POST /index.php`.
- **Root cause**: The frontend `fetch` request was sending the CSRF token using the input name (`csrf_test_name`) as the header key, but CodeIgniter's Security component strictly requires the header name to be `X-CSRF-TOKEN` (defined in `Config\Security.php`). This caused CI4 to reject the request with a 403 Forbidden, which triggered the `catch` block in JS, eventually accumulating 3 strikes and aborting as a "timeout".
- **Fix**: Updated `index.php` to use `<?= csrf_header() ?>` (which outputs `X-CSRF-TOKEN`) for the header key in the `fetch` request. Additionally, lowered the batch size from 10 to 5 in `UserController.php` to provide an extra safety buffer against PHP 30s timeouts on slower hardware.
- **Status**: [ ] Unverified
