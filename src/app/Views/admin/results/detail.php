<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Detail Jawaban: <?= esc($user->firstname) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card shadow-sm mb-4">
    <div class="card-body p-4 bg-light rounded-3">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h5 class="fw-bold text-dark mb-1">Lembar Jawaban: <?= esc($user->firstname . ' ' . $user->lastname) ?></h5>
                <p class="text-muted mb-0">Ujian: <?= esc($test->name) ?> | Waktu Selesai: <?= date('d/m/Y H:i', strtotime($attempt->finished_at)) ?></p>
            </div>
            <div class="col-md-4 text-end">
                <?php $belumDinilai = 0; foreach ($logs as $l) { if ($l->score === null) $belumDinilai++; } ?>
                <div class="display-6 fw-bold <?= $belumDinilai > 0 ? 'text-primary' : ($attempt->score >= $test->passing_score ? 'text-success' : 'text-danger') ?>">
                    <?= number_format($attempt->score, 2) ?>
                </div>
                <?php if ($belumDinilai > 0): ?>
                    <div class="small text-primary fw-semibold">Skor Sementara</div>
                    <div class="small text-muted"><?= $belumDinilai ?> soal esai belum dikoreksi</div>
                <?php else: ?>
                    <div class="small text-muted">Skor Akhir</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Option to recount score here in future -->

<div class="row g-4">
    <?php $no = 1; foreach ($logs as $log): ?>
        <div class="col-12">
            <div class="card shadow-sm border-0 mb-2">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold">Soal No. <?= $no++ ?> <span class="badge bg-secondary ms-2">Level <?= $log->difficulty ?></span></h6>
                    <div>
                        <?php if ($log->score === null): ?>
                            <span class="badge bg-primary">Menunggu koreksi Anda</span>
                        <?php else: ?>
                            <span class="badge <?= $log->score > 0 ? 'bg-success' : ($log->score < 0 ? 'bg-danger' : 'bg-warning text-dark') ?>">
                                Poin Didapat: <?= $log->score ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-4 fs-5" style="line-height: 1.6;">
                    
                    <div class="mb-4 text-dark">
                        <?= $log->question_text ?>
                    </div>

                    <?php if ($log->question_type == 3): ?>
                        <div class="alert alert-secondary border-0">
                            <strong>Jawaban Esai Siswa:</strong>
                            <p class="mb-0 mt-2"><?= nl2br(esc($log->answer_text ?: 'Tidak diisi')) ?></p>
                        </div>
                    <?php elseif ($log->question_type == 4 || $log->question_type == 5): ?>
                        <?php 
                            $studentAnswers = json_decode($log->answer_text, true) ?: [];
                            $logAnswers = $answers[$log->id] ?? [];
                        ?>
                        <ul class="list-group list-group-flush border-top border-bottom">
                            <?php foreach ($logAnswers as $ans): 
                                $parts = explode('|::|', $ans->answer_text);
                                $left = $parts[0] ?? '';
                                $right = $parts[1] ?? '';
                                $selectedRight = $studentAnswers[$left] ?? '';
                                $isCorrect = ($selectedRight === $right);
                                
                                $bgClass = $isCorrect ? 'list-group-item-success' : 'list-group-item-danger';
                                $icon = $isCorrect ? '<i class="bi bi-check-circle-fill text-success me-2"></i>' : '<i class="bi bi-x-circle-fill text-danger me-2"></i>';
                            ?>
                                <li class="list-group-item <?= $bgClass ?> py-3">
                                    <div class="d-flex flex-column">
                                        <div class="fw-bold mb-1"><?= esc($left) ?></div>
                                        <div class="d-flex align-items-center mb-1">
                                            <?= $icon ?> <span class="text-muted me-2">Jawaban Siswa:</span> <strong><?= esc($selectedRight ?: 'Tidak Dijawab') ?></strong>
                                        </div>
                                        <?php if (!$isCorrect): ?>
                                            <div class="d-flex align-items-center text-success small">
                                                <i class="bi bi-arrow-return-right me-2"></i> Kunci Jawaban: <strong><?= esc($right) ?></strong>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <ul class="list-group list-group-flush border-top border-bottom">
                            <?php 
                            $logAnswers = $answers[$log->id] ?? [];
                            foreach ($logAnswers as $ans): 
                                $isSelected = $ans->is_selected == 1;
                                $isCorrect = $ans->is_correct == 1;
                                
                                $bgClass = '';
                                $icon = '';
                                
                                if ($isSelected && $isCorrect) {
                                    $bgClass = 'list-group-item-success';
                                    $icon = '<i class="bi bi-check-circle-fill text-success me-2"></i>';
                                } elseif ($isSelected && !$isCorrect) {
                                    $bgClass = 'list-group-item-danger';
                                    $icon = '<i class="bi bi-x-circle-fill text-danger me-2"></i>';
                                } elseif (!$isSelected && $isCorrect) {
                                    $bgClass = 'list-group-item-warning'; // Missed correct answer
                                    $icon = '<i class="bi bi-exclamation-circle-fill text-warning me-2"></i>';
                                } else {
                                    $icon = '<i class="bi bi-circle text-muted me-2"></i>';
                                }
                            ?>
                                <li class="list-group-item <?= $bgClass ?> d-flex align-items-center py-3">
                                    <?= $icon ?>
                                    <div class="flex-grow-1">
                                        <?= $ans->answer_text ?>
                                    </div>
                                    <?php if ($isSelected): ?>
                                        <span class="badge bg-dark ms-3">Dipilih Siswa</span>
                                    <?php endif; ?>
                                    <?php if ($isCorrect): ?>
                                        <span class="badge bg-success ms-2">Kunci Benar</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <!-- Manual Grading Form (For All Question Types) -->
                    <form action="<?= base_url('/admin/results/update-score') ?>" method="POST" class="mt-4 p-3 bg-light rounded border">
                        <?= csrf_field() ?>
                        <input type="hidden" name="log_id" value="<?= $log->id ?>">
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <label class="fw-bold text-nowrap">Intervensi Nilai Soal Ini:</label>
                            <div class="input-group" style="max-width: 250px;">
                                <input type="number" step="0.01" class="form-control" name="score" value="<?= $log->score ?>">
                                <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Simpan</button>
                            </div>
                        </div>
                        <div class="form-text mt-2 mb-0">Disimpan ke database. Akan mengkalkulasi ulang Skor Akhir ujian siswa otomatis.</div>
                    </form>

                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="mt-4 text-center">
    <a href="<?= base_url('/admin/results/view/' . $test->id) ?>" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Nilai</a>
</div>
<?= $this->endSection() ?>
