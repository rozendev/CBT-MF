# Cloudflare Setup untuk Static Exam Page

## Prasyarat
- Domain Anda sudah terhubung ke Cloudflare (DNS Proxy aktif / orange cloud)
- SSL/TLS mode: **Full (Strict)** direkomendasikan

## Langkah 1: Page Rules / Cache Rules

### Cache Static Exam Files
Buat **Cache Rule** di Cloudflare Dashboard:

1. Buka **Caching → Cache Rules**
2. Klik **Create Rule**
3. Konfigurasi:
   - **Rule name**: `Cache Static Exam Pages`
   - **When**: URI Path starts with `/static/`
   - **Cache eligibility**: Eligible for cache
   - **Edge TTL**: Override → **1 month** (2,592,000 detik)
   - **Browser TTL**: Override → **1 hour** (3,600 detik)

### Bypass Cache untuk API
Buat **Cache Rule** kedua:

1. **Rule name**: `Bypass API Cache`
2. **When**: URI Path starts with `/api/`
3. **Cache eligibility**: Bypass cache

## Langkah 2: Purge Cache Setelah Regenerate

Setiap kali admin klik "Generate Ulang" halaman statis, lakukan purge cache di Cloudflare:

### Via Dashboard:
1. Buka **Caching → Configuration**
2. Klik **Custom Purge** → masukkan URL file statis (contoh: `https://ujian.sekolah.id/static/2026-06-06_10-00/uts-matematika.html`)

### Via API (opsional, bisa diintegrasikan nanti):
```bash
curl -X POST "https://api.cloudflare.com/client/v4/zones/ZONE_ID/purge_cache" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  --data '{"files":["https://ujian.sekolah.id/static/2026-06-06_10-00/uts-matematika.html"]}'
```

## Langkah 3: Verifikasi

1. Buka file statis di browser: `https://ujian.sekolah.id/static/.../nama-ujian.html`
2. Buka **Developer Tools → Network → Headers**
3. Pastikan response header mengandung: `cf-cache-status: HIT` (setelah request kedua)
