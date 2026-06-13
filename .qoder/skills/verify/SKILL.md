---
name: verify
description: Jalankan PHPUnit test suite di dalam Docker container untuk memverifikasi perubahan kode. Gunakan setelah membuat atau mengubah kode untuk memastikan tidak ada regresi.
---

Jalankan test suite menggunakan Docker container:

```bash
./scripts/cmd.sh composer test
```

Jika container belum berjalan, jalankan `./scripts/cmd.sh up` terlebih dahulu.

Untuk menjalankan test tertentu saja:

```bash
./scripts/cmd.sh php vendor/bin/phpunit --filter NamaTest
```

Setelah test selesai, laporkan hasilnya ke user: jumlah pass/fail, dan jika ada failure, analisis penyebabnya dan tawarkan fix.
