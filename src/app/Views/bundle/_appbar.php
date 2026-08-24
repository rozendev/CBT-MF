<?php
/**
 * Bar identitas sekolah. Dipakai di SEMUA halaman bundle supaya siswa selalu
 * melihat ini lembar ujian sekolahnya, bukan halaman uji coba.
 *
 * Variabel: $school (name/tagline/logo). Logo boleh kosong — barnya tetap rapi
 * tanpa gambar, jadi sekolah yang belum mengunggah logo tidak dapat kotak rusak.
 */
$school = ($school ?? []) + ['name' => 'CBT', 'tagline' => '', 'logo' => ''];
?>
<header class="k-appbar">
    <?php if ($school['logo'] !== ''): ?>
        <img class="k-appbar__logo" src="<?= esc($school['logo']) ?>" alt="">
    <?php endif; ?>
    <div class="k-appbar__id">
        <div class="k-appbar__school"><?= esc($school['name']) ?></div>
        <?php if ($school['tagline'] !== ''): ?>
            <div class="k-appbar__tagline"><?= esc($school['tagline']) ?></div>
        <?php endif; ?>
    </div>
</header>
