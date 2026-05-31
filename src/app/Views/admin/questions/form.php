<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?><?= $question ? 'Edit Soal' : 'Tambah Soal Baru' ?><?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
<style>
    .answer-row { transition: all 0.2s ease; }
    .answer-row:hover { background-color: #f8f9fa; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<form action="<?= base_url('/admin/questions/' . ($question ? 'update/'.$question->id : 'store')) ?>" method="POST">
    <?= csrf_field() ?>
    
    <div class="row g-4">
        <!-- Kolom Kiri: Editor Soal & Jawaban -->
        <div class="col-lg-8">
            <?php if (session()->has('errors')): ?>
                <div class="alert alert-danger rounded-3">
                    <ul class="mb-0 ps-3">
                    <?php foreach (session('errors') as $error): ?>
                        <li><?= esc($error) ?></li>
                    <?php endforeach ?>
                    </ul>
                </div>
            <?php endif ?>

            <!-- Editor Soal -->
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="m-0 fw-bold text-primary"><i class="bi bi-pencil-square me-2"></i>Teks Pertanyaan</h6>
                </div>
                <div class="card-body">
                    <textarea class="form-control summernote" name="description" required><?= old('description', $question->description ?? '') ?></textarea>
                </div>
            </div>

            <!-- Pilihan Jawaban -->
            <div class="card shadow-sm" id="answers-card">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold text-primary"><i class="bi bi-list-check me-2"></i>Pilihan Jawaban</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" onclick="addAnswerRow()">
                        <i class="bi bi-plus"></i> Tambah Opsi
                    </button>
                </div>
                <div class="card-body p-0" id="answers-container">
                    <!-- Placeholder Jawaban (Di-render via JS tergantung tipe) -->
                </div>
            </div>
            
            <!-- Penjelasan (Opsional) -->
            <div class="card shadow-sm mt-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="m-0 fw-bold text-secondary"><i class="bi bi-info-circle me-2"></i>Penjelasan Jawaban (Opsional)</h6>
                </div>
                <div class="card-body">
                    <textarea class="form-control summernote" name="explanation"><?= old('explanation', $question->explanation ?? '') ?></textarea>
                    <div class="form-text mt-2">Penjelasan ini dapat ditampilkan kepada siswa setelah ujian selesai.</div>
                </div>
            </div>
        </div>

        <!-- Kolom Kanan: Pengaturan Soal -->
        <div class="col-lg-4">
            <div class="card shadow-sm sticky-top" style="top: 80px; z-index: 1;">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="m-0 fw-bold"><i class="bi bi-gear me-2"></i>Pengaturan Soal</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Subjek / Topik <span class="text-danger">*</span></label>
                        <select class="form-select" name="subject_id" required>
                            <option value="">-- Pilih Subjek --</option>
                            <?php 
                                $selectedSubject = old('subject_id', $subjectId ?? '');
                            ?>
                            <?php foreach ($subjectsByModule as $moduleName => $subjects): ?>
                                <optgroup label="<?= esc($moduleName) ?>">
                                    <?php foreach ($subjects as $sub): ?>
                                        <option value="<?= $sub->id ?>" <?= $selectedSubject == $sub->id ? 'selected' : '' ?>>
                                            <?= esc($sub->name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Tipe Soal <span class="text-danger">*</span></label>
                        <select class="form-select" name="type" id="question_type" onchange="renderAnswerUI()" required>
                            <?php $selectedType = old('type', $question->type ?? 1); ?>
                            <option value="1" <?= $selectedType == 1 ? 'selected' : '' ?>>Pilihan Ganda (1 Benar)</option>
                            <option value="2" <?= $selectedType == 2 ? 'selected' : '' ?>>Pilihan Ganda (Banyak Benar)</option>
                            <option value="3" <?= $selectedType == 3 ? 'selected' : '' ?>>Esai / Teks</option>
                            <!-- <option value="4" <?= $selectedType == 4 ? 'selected' : '' ?>>Mengurutkan</option> -->
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-muted">Tingkat Kesulitan (1-10) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="difficulty" min="1" max="10" 
                               value="<?= old('difficulty', $question->difficulty ?? 1) ?>" required>
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1" 
                                   <?= old('is_enabled', $question->is_enabled ?? '1') == '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold ms-2" for="is_enabled">Soal Aktif</label>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-save me-1"></i> Simpan Soal
                        </button>
                        <a href="<?= base_url('/admin/questions'. ($subjectId ? '?subject_id='.$subjectId : '')) ?>" class="btn btn-light">Batal</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
<script>
    $(document).ready(function() {
        $('.summernote').summernote({
            height: 200,
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'underline', 'clear', 'superscript', 'subscript']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['table', ['table']],
                ['insert', ['link', 'picture', 'video']],
                ['view', ['fullscreen', 'codeview', 'help']]
            ]
        });

        // Initialize answers UI
        renderAnswerUI();
    });

    // Existing Answers from PHP to JS
    const existingAnswers = <?= json_encode($answers ?? []) ?>;
    let answerCount = existingAnswers.length > 0 ? existingAnswers.length : 4; 

    function renderAnswerUI() {
        const type = parseInt($('#question_type').val());
        const container = $('#answers-container');
        
        if (type === 3) {
            // Essay
            container.html(`
                <div class="p-4 text-center text-muted">
                    <i class="bi bi-text-paragraph fs-1 d-block mb-2"></i>
                    Soal tipe Esai tidak membutuhkan pilihan jawaban.<br>
                    Siswa akan diberikan kotak teks untuk menjawab.
                </div>
            `);
            $('#answers-card .btn-outline-primary').hide(); // Hide Add button
            return;
        }

        $('#answers-card .btn-outline-primary').show();
        
        let html = '';
        const inputType = (type === 2) ? 'checkbox' : 'radio';
        
        // Use existing if we have them and match logic
        const loops = Math.max(answerCount, existingAnswers.length > 0 ? existingAnswers.length : 4);
        
        for (let i = 0; i < loops; i++) {
            let desc = '';
            let isCorrect = false;
            let id = '';

            if (existingAnswers[i]) {
                desc = existingAnswers[i].description;
                isCorrect = existingAnswers[i].is_correct == 1;
                id = existingAnswers[i].id;
            }

            html += `
                <div class="answer-row d-flex align-items-center p-3 border-bottom" id="ans-row-${i}">
                    <div class="me-3 ps-2">
                        <input class="form-check-input fs-4" type="${inputType}" name="correct_answers[]" value="${i}" ${isCorrect ? 'checked' : ''}>
                    </div>
                    <div class="flex-grow-1">
                        <input type="text" class="form-control" name="answers[${i}]" value="${desc.replace(/"/g, '&quot;')}" placeholder="Ketik pilihan jawaban..." required>
                    </div>
                    <div class="ms-3 pe-2">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="$('#ans-row-${i}').remove()"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
            `;
        }
        
        container.html(html);
        answerCount = loops;
    }

    function addAnswerRow() {
        const type = parseInt($('#question_type').val());
        if (type === 3) return; // Not for essay

        const inputType = (type === 2) ? 'checkbox' : 'radio';
        const i = answerCount++;
        
        const html = `
            <div class="answer-row d-flex align-items-center p-3 border-bottom" id="ans-row-${i}">
                <div class="me-3 ps-2">
                    <input class="form-check-input fs-4" type="${inputType}" name="correct_answers[]" value="${i}">
                </div>
                <div class="flex-grow-1">
                    <input type="text" class="form-control" name="answers[${i}]" placeholder="Ketik pilihan jawaban..." required>
                </div>
                <div class="ms-3 pe-2">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="$('#ans-row-${i}').remove()"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>
        `;
        $('#answers-container').append(html);
    }
</script>
<?= $this->endSection() ?>
