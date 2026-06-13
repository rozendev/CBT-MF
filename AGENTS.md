# AGENTS.md

This file provides guidance to the AI agent when working with code in this repository.

## Project Overview

Sistem Ujian (CBT) — aplikasi ujian berbasis komputer menggunakan **CodeIgniter 4** (PHP 8.5) dengan Docker (Nginx + PHP-FPM), MariaDB, dan Redis.

## Setup & Environment

1. Copy `src/env` ke `src/.env` dan sesuaikan konfigurasi database serta `app.baseURL`.
2. Set environment variable `CF_TUNNEL_TOKEN` di shell sebelum `docker compose up` agar Cloudflare Tunnel berjalan.
3. Jalankan semua service: `./scripts/cmd.sh up`
4. Akses app di `http://localhost:8080`, phpMyAdmin di `http://localhost:8081`.

## Perintah Penting

Semua perintah PHP/Composer dijalankan via Docker container:

```bash
./scripts/cmd.sh php spark migrate          # jalankan migrasi database
./scripts/cmd.sh php spark migrate:rollback # rollback migrasi terakhir
./scripts/cmd.sh composer test              # jalankan PHPUnit
./scripts/cmd.sh shell                      # masuk bash container PHP
```

## Arsitektur

- **Roles**: `admin`, `guru`, `siswa` — dicek via `RoleFilter` di routes.
- **Session**: disimpan di Redis (`RedisHandler`), bukan file.
- **API exam**: route `api/exam/*` menggunakan `CorsApiFilter` dan SSE (Server-Sent Events) untuk streaming real-time.
- **Migrations**: di `src/app/Database/Migrations/`, dinomori sequential. Jangan edit migration yang sudah dijalankan — buat baru.
- **Views**: server-rendered di `src/app/Views/`, dikelompokkan per role (admin/, student/, auth/, exam/).

## Konvensi CodeIgniter 4

- Controller extends `BaseController`, gunakan `$this->request` dan `$this->response`.
- Model extends `CodeIgniter\Model`, definisikan `$table`, `$primaryKey`, `$allowedFields`.
- Gunakan `spark` CLI untuk generate migration, seeder, dan controller baru.
- Route didefinisikan di `app/Config/Routes.php` dengan filter group per role.
- CSRF aktif secara global kecuali untuk SSE stream dan queue ping.

## Struktur Kode

- `src/app/Controllers/` — dikelompokkan: Admin/, Api/, Auth/, Exam/, Student/
- `src/app/Models/` — satu model per tabel
- `src/app/Filters/` — middleware: AuthFilter, RoleFilter, MultiLoginFilter, CorsApiFilter
- `src/app/Config/` — konfigurasi framework (Routes, Filters, Database, dll)
- `src/public/` — static assets, uploads, installer
- `src/tests/` — PHPUnit tests
