<?= $this->extend('layouts/student') ?>

<?= $this->section('page_title') ?>Hasil Ujian: <?= esc($test->name) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center mb-5">
    <div class="col-lg-8 text-center">
        <h2 class="fw-bold text-dark mb-2">Hasil Ujian</h2>
        <p class="text-muted fs-5 mb-4"><?= esc($test->name) ?></p>

        <div class="card shadow-lg border-0 rounded-4 overflow-hidden">
            <div class="card-body p-5">
                <p class="text-uppercase fw-bold text-muted tracking-wide mb-2">Nilai Akhir Anda</p>
                
                <h1 class="display-1 fw-bold <?= $attempt->score >= $test->passing_score ? 'text-success' : 'text-danger' ?> mb-3">
                    <?= number_format($attempt->score, 2) ?>
                </h1>
                
                <?php if ($attempt->score >= $test->passing_score): ?>
                    <div class="alert alert-success border-0 d-inline-block px-4 py-2 fw-bold fs-5 rounded-pill mb-4">
                        <i class="bi bi-check-circle-fill me-2"></i> LULUS
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger border-0 d-inline-block px-4 py-2 fw-bold fs-5 rounded-pill mb-4">
                        <i class="bi bi-x-circle-fill me-2"></i> TIDAK LULUS
                    </div>
                    <p class="text-muted mt-2">Batas lulus untuk ujian ini adalah <strong><?= $test->passing_score ?></strong>.</p>
                <?php endif; ?>
                
                <hr class="my-4 opacity-10">
                
                <div class="d-flex justify-content-center gap-5 text-muted">
                    <div>
                        <div class="small fw-bold text-uppercase">Waktu Selesai</div>
                        <div class="fs-5 text-dark"><?= date('d M Y, H:i', strtotime($attempt->finished_at)) ?></div>
                    </div>
                    <div>
                        <div class="small fw-bold text-uppercase">Target Skala</div>
                        <div class="fs-5 text-dark"><?= $test->max_score ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($test->report_visible == 1 && !empty($logs)): ?>
<div class="row justify-content-center">
    <div class="col-lg-10">
        <h4 class="fw-bold mb-4"><i class="bi bi-card-checklist me-2 text-primary"></i>Rincian Jawaban</h4>
        
        <div class="accordion shadow-sm" id="accordionAnswers">
            <?php $no = 1; foreach ($logs as $log): ?>
                <div class="accordion-item border-0 mb-3 rounded-3 overflow-hidden">
                    <h2 class="accordion-header" id="heading<?= $log->id ?>">
                        <button class="accordion-button collapsed fw-bold <?= $log->score > 0 ? 'text-success' : 'text-danger' ?>" type="button" data-bs-toggle="collapse" data-bs-toggle="collapse" data-bs-target="#collapse<?= $log->id ?>">
                            Soal No. <?= $no++ ?> 
                            <span class="ms-auto badge <?= $log->score > 0 ? 'bg-success' : 'bg-danger' ?> rounded-pill px-3 me-3">
                                Skor: <?= $log->score ?>
                            </span>
                        </button>
                    </h2>
                    <div id="collapse<?= $log->id ?>" class="accordion-collapse collapse" data-bs-parent="#accordionAnswers">
                        <div class="accordion-body p-4 bg-light">
                            
                            <div class="mb-4 text-dark fs-5">
                                <?= $log->question_text ?>
                            </div>

                            <?php if ($log->question_type == 3): ?>
                                <div class="alert alert-secondary border-0">
                                    <strong>Jawaban Anda:</strong>
                                    <p class="mb-0 mt-2"><?= nl2br(esc($log->answer_text ?: 'Tidak dijawab')) ?></p>
                                </div>
                            <?php else: ?>
                                <ul class="list-group list-group-flush border-top border-bottom rounded-3">
                                    <?php 
                                    $logAnswers = $answers[$log->id] ?? [];
                                    foreach ($logAnswers as $ans): 
                                        $isSelected = $ans->is_selected == 1;
                                        $isCorrect = $ans->is_correct == 1;
                                        
                                        $bgClass = '';
                                        $icon = '<i class="bi bi-circle text-muted me-2"></i>';
                                        
                                        if ($isSelected && $isCorrect) {
                                            $bgClass = 'list-group-item-success';
                                            $icon = '<i class="bi bi-check-circle-fill text-success me-2"></i>';
                                        } elseif ($isSelected && !$isCorrect) {
                                            $bgClass = 'list-group-item-danger';
                                            $icon = '<i class="bi bi-x-circle-fill text-danger me-2"></i>';
                                        } elseif (!$isSelected && $isCorrect) {
                                            $bgClass = 'list-group-item-warning'; // Correct answer that user missed
                                            $icon = '<i class="bi bi-exclamation-circle-fill text-warning me-2"></i>';
                                        }
                                    ?>
                                        <li class="list-group-item <?= $bgClass ?> py-3">
                                            <div class="d-flex align-items-center">
                                                <?= $icon ?>
                                                <div class="flex-grow-1">
                                                    <?= $ans->answer_text ?>
                                                </div>
                                                <?php if ($isSelected): ?>
                                                    <span class="badge bg-dark ms-3">Pilihan Anda</span>
                                                <?php endif; ?>
                                                <?php if ($isCorrect): ?>
                                                    <span class="badge bg-success ms-2">Jawaban Benar</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="text-center mt-5">
    <a href="<?= base_url('/student/dashboard') ?>" class="btn btn-outline-primary fw-bold px-4">
        <i class="bi bi-house-door-fill me-2"></i> Kembali ke Dasbor
    </a>
</div>
<?= $this->endSection() ?>
