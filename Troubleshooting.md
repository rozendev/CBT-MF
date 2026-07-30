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
