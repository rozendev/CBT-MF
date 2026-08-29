---
title: Pengambilalihan Sesi di Perangkat yang Sama
date: 2026-08-28
status: approved
approach: Kunci pendamping user_login_device, dibaca hanya oleh gerbang login
target: server CBT-MF, aplikasi kiosk EXAMBRO (satu perubahan native)
tech: PHP CodeIgniter 4.7, Redis, Kotlin
---

# Pengambilalihan Sesi di Perangkat yang Sama

## 1. Masalah

Siswa yang aplikasinya mati di tengah ujian **tidak bisa masuk kembali** sampai
seorang admin mereset sesinya. Bukan sekadar harus mengetik ulang kredensial —
benar-benar ditolak.

`AuthController::login` menolak login kedua bila `user_login_token:{userId}`
masih ada:

```php
if ($existingToken && $existingToken !== 'BANNED') {
    return $fail('Akun Anda sedang digunakan di perangkat lain. ...');
}
```

Token itu ber-TTL 7200 detik dan **diperpanjang setiap permintaan** oleh
`MultiLoginFilter`. Jadi setelah aplikasi mati, token peninggalan sesi yang
sudah tidak ada itu tetap hidup sampai dua jam, dan selama itu siswanya
terkunci.

`prevent_multi_login` **aktif secara bawaan**: `getSettingValue('prevent_multi_login', 1)`,
dan baris setelan itu tidak ada di basis data instalasi ini — jadi bawaannya
yang berlaku. Ini bukan skenario hipotetis.

Akar persoalannya: pemeriksaan hanya menanyakan *"apakah ada token?"*, tidak
pernah *"dari perangkat mana?"*.

## 2. Sasaran

1. Siswa yang aplikasinya mati bisa masuk kembali **sendiri**, tanpa admin.
2. Perlindungan terhadap perangkat **lain** tidak berkurang sedikit pun.
3. Tidak menambah kredensial baru, TTL kedua, atau jalur pencabutan baru.

## 3. Bukan Sasaran

- **Bukan** bind perangkat penuh. Tidak ada token yang disimpan di perangkat,
  tidak ada auto-login. Siswa tetap mengetik password setiap kali.
- **Bukan** perbaikan `_doResetLogin()`. Fungsi itu menghapus `user_login_token`
  tetapi tidak pernah menyapu `ci_session:*`, sehingga sesi yang sudah berjalan
  tidak benar-benar terusir — berbeda dari `ProctorAction::lockAccount()` yang
  memang menyapu. Cacat itu nyata dan terpisah; dicatat di sini supaya tidak
  hilang, tidak diperbaiki oleh rancangan ini.

## 4. Rancangan

### 4.1 Kunci pendamping, bukan mengubah yang ada

Nilai `user_login_token:{userId}` **tidak diubah bentuknya**. Sentinel
`'BANNED'` ditulis di tiga tempat — `ProctorAction:166`, `ExamService:458`,
`ExamService:662` — dan dibaca `MultiLoginFilter:70`. Mengubahnya menjadi hash
akan merusak keempatnya.

Sebagai gantinya, kunci baru:

```
user_login_device:{userId}  →  device_id, TTL sama dengan token (7200)
```

Ditulis berbarengan dengan token, dihapus berbarengan dengan token.

### 4.2 Aturan gerbang login

| Keadaan | Putusan |
|---|---|
| Token tidak ada | Login normal |
| Token ada, `device_id` sama | **Ambil alih** — terbitkan token baru |
| Token ada, `device_id` berbeda | Tolak (perilaku sekarang) |
| Token ada, `device_id` tidak dikirim | Tolak (perilaku sekarang) |

Baris terakhir disengaja. Login dari browser biasa tidak mengirim `device_id`,
dan harus tetap ditolak — kalau tidak, siapa pun bisa melewati perlindungan
multi-login hanya dengan tidak mengirim field itu. Ketiadaan bukti bukan bukti.

### 4.3 Kenapa permukaannya sesempit itu

Token di-key per `userId`. Siswa B yang login di perangkat yang sama sekali
tidak menyentuh token siswa A — ia memeriksa `user_login_token:{B}`.

Maka "ambil alih" hanya pernah berarti satu hal: **pengguna yang sama merebut
kembali sesinya sendiri di perangkat yang sama.** Tidak ada jalur yang
memungkinkan siapa pun mengambil alih sesi orang lain, karena tidak ada
pembacaan silang antar-pengguna.

### 4.4 Atomisitas tetap dijaga

Jalur yang ada memakai `set nx` supaya dua login serentak tidak lolos bersama:

```php
$set = $redis->set($tokenKey, $loginToken, ['nx', 'ex' => 7200]);
```

Jalur pengambilalihan tidak boleh membuka celah balapan itu. Penulisan pada
jalur "perangkat sama" harus tetap tidak bisa dibalap — pemeriksaan
`device_id` lalu penulisan biasa akan menciptakan jendela antara keduanya.

### 4.5 Penghapusan harus ikut

Kunci pendamping wajib dihapus di setiap tempat token dihapus, kalau tidak akan
tertinggal `device_id` basi yang memberi hak ambil alih kepada perangkat yang
sudah tidak relevan:

- `SuspendController:115` (release)
- `SuspendController:140` (reset login)
- `AuthController:272` (logout)

Sentinel `'BANNED'` tidak menulis kunci pendamping. Akun yang di-ban memang
harus ditolak dari perangkat mana pun.

## 5. Perubahan Aplikasi

Login **belum menerima `device_id` sama sekali**, dan halaman login berjalan di
WebView. Supaya nilainya sampai ke server, `CommsBridge` harus mengekspornya ke
JS, dan halaman login bundle menyertakannya di POST.

Nilainya wajib datang dari native lewat `DeviceIdentityStore.resolve()` — sumber
yang sama dengan heartbeat dan `/api/kiosk/config`. Apa pun yang berasal dari JS
bisa dikarang, dan menambah sumber kedua akan mengulang cacat yang sudah pernah
terjadi: dua titik memeriksa identitas yang berbeda.

Ini berarti satu rilis APK.

## 6. Penanganan Galat

| Kondisi | Perilaku |
|---|---|
| Redis mati saat login | Tidak berubah — login sudah gagal dengan pesan yang ada |
| Kunci pendamping hilang tapi token ada | Diperlakukan seperti `device_id` berbeda: tolak |
| `device_id` dikirim tapi tidak sah bentuknya | Diperlakukan seperti tidak dikirim: tolak |
| Token `'BANNED'` | Tidak berubah — pengambilalihan tidak berlaku |

## 7. Pengujian

Bagian yang murni — memutuskan boleh-tidaknya mengambil alih dari tiga masukan
(token ada, device tersimpan, device yang mengirim) — dipisahkan agar bisa diuji
di suite `Resilience` yang berjalan tanpa framework.

Yang harus dijaga tes:

1. Tidak ada token → boleh login.
2. Token ada, device sama → boleh ambil alih.
3. Token ada, device berbeda → tolak.
4. Token ada, device kosong → tolak.
5. Token ada, kunci pendamping hilang → tolak.
6. Token `'BANNED'` → tolak, apa pun device-nya.

Verifikasi manual: matikan paksa aplikasi di tengah ujian, buka lagi, login —
harus masuk dan melanjutkan tanpa admin. Lalu coba akun yang sama di perangkat
lain selagi sesi pertama hidup — harus tetap ditolak.

## 8. Berkas Terdampak

**Diubah**
- `src/app/Controllers/Auth/AuthController.php` — gerbang login, dan logout
- `src/app/Controllers/Admin/SuspendController.php` — dua penghapusan
- `src/app/Views/bundle/login.php` — sertakan `device_id` di POST
- `cbt-kiosk-app/.../bridge/CommsBridge.kt` — ekspor `device_id` ke JS

**Baru**
- `src/app/Libraries/SessionTakeover.php` — putusan murni, dapat diuji
- `src/tests/Resilience/SessionTakeoverTest.php`

Bundle UI **berubah** (`login.php`), jadi `spark cbt:build-ui-bundle` wajib
dijalankan.
