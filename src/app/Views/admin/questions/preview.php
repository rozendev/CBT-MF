<?php
$types = [
    1 => 'Pilihan Ganda (1 Jawaban)',
    2 => 'Pilihan Ganda (Banyak Jawaban)',
    3 => 'Esai / Teks Singkat',
    4 => 'Mengurutkan'
];
$typeBadge = isset($types[$question->type]) ? $types[$question->type] : 'Unknown';

$diffColors = [
    1 => 'success',
    2 => 'warning',
    3 => 'danger'
];
$difficultyBadgeColor = $diffColors[$question->difficulty] ?? 'secondary';
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <span class="badge bg-light text-dark border me-1"><?= esc($question->subject_name) ?></span>
            <span class="badge bg-info-subtle text-info border border-info-subtle me-1"><?= esc($typeBadge) ?></span>
            <span class="badge bg-<?= $difficultyBadgeColor ?>-subtle text-<?= $difficultyBadgeColor ?> border border-<?= $difficultyBadgeColor ?>-subtle">Level <?= $question->difficulty ?></span>
        </div>
        <div>
            <?php if ($question->is_enabled): ?>
                <span class="badge bg-success-subtle text-success rounded-pill px-2">Aktif</span>
            <?php else: ?>
                <span class="badge bg-danger-subtle text-danger rounded-pill px-2">Nonaktif</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card bg-light border-0 shadow-sm">
        <div class="card-body">
            <h6 class="text-muted fw-bold mb-3"><i class="bi bi-question-circle me-1"></i> Pertanyaan</h6>
            <div class="fs-6 text-dark" style="word-wrap: break-word;">
                <?= $question->description ?>
            </div>
        </div>
    </div>
</div>

<div class="mb-4">
    <h6 class="text-muted fw-bold mb-3"><i class="bi bi-list-check me-1"></i> Pilihan Jawaban</h6>
    <div class="list-group list-group-flush border rounded shadow-sm">
        <?php if (empty($answers)): ?>
            <div class="list-group-item text-center text-muted py-4">
                <i class="bi bi-exclamation-circle fs-3 d-block mb-2"></i>
                Belum ada pilihan jawaban untuk soal ini.
            </div>
        <?php else: ?>
            <?php foreach ($answers as $ans): ?>
                <div class="list-group-item list-group-item-action <?= $ans->is_correct ? 'list-group-item-success' : '' ?>">
                    <div class="d-flex w-100 justify-content-between align-items-center">
                        <div class="mb-0 fs-6 text-dark" style="word-wrap: break-word; overflow: hidden; max-width: 90%;">
                            <?= $ans->description ?>
                        </div>
                        <?php if ($ans->is_correct): ?>
                            <span class="badge bg-success rounded-pill px-2 py-1"><i class="bi bi-check2"></i> Benar</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty(trim($question->explanation))): ?>
    <div class="alert alert-info border-0 mb-0 shadow-sm">
        <h6 class="alert-heading text-info-emphasis mb-2 fw-bold"><i class="bi bi-info-circle me-1"></i> Penjelasan Jawaban</h6>
        <div class="text-info-emphasis mb-0">
            <?= $question->explanation ?>
        </div>
    </div>
<?php endif; ?>
