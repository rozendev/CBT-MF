# Build APK Android melalui cbt.sh

- Perintah `android doctor`, `android setup`, dan `android build` tersedia pada CLI/menu; build tidak memerlukan instalasi server atau Docker.
- Target awal Linux x86_64. Periksa Python 3.9+, JDK 21, Android SDK/platform/build-tools, dan Gradle; setup/build memasang komponen yang kurang. Python memakai standard library untuk unduhan/archive, tanpa paket pip/curl/unzip tambahan. Sudo hanya bila Python perlu dipasang; doctor hanya melaporkan.
- Nama dan icon diterima melalui `--name`/`--icon` atau prompt interaktif; icon PNG/JPEG/WebP. Nilai tanpa override tetap memakai branding bawaan.
- Default yang dipilih user: APK rilis bertanda tangan; buat kunci lokal sekali dan gunakan ulang, tanpa menimpa kunci yang sudah ada atau mencetak password. Kunci/password berada di area runtime privat yang tidak masuk Git; dokumentasikan backup untuk pembaruan APK berikutnya.
- Branding dan build memakai workspace privat `.android-build/` yang diabaikan Git, agar resource sumber, local.properties, dan konfigurasi signing pengembang tidak ditulis ulang. Package ID bawaan tetap.
- SDK/JDK yang sudah sesuai dipakai ulang; SDK lokal proyek menjadi fallback bila SDK sistem tidak dapat dilengkapi. Unduhan tool Android berasal dari Google dengan checksum; Gradle memakai versi wrapper proyek.
- Selaraskan APK sebelum signing, verifikasi tanda tangan dan metadata hasil; tampilkan lokasi APK final. Kegagalan langkah apa pun menghasilkan status nonzero dan tidak melaporkan hasil lama sebagai sukses.
- Periksa penerimaan lisensi SDK bila diperlukan; instalasi otomatis tidak mengubah konfigurasi server, dependensi Composer, maupun Railway.
- Trade-off: setup awal memerlukan internet, ruang disk, dan kadang hak instalasi paket OS; cache mempercepat build berikutnya. Nama/icon tidak mengubah package ID atau menggantikan kunci signing APK lama.
- Uji dispatch/prasyarat/branding/signing dengan fixture, lalu build APK rilis nyata, verifikasi label/icon/signature dan regresi CLI. Pemeriksaan perangkat fisik tetap terpisah.
