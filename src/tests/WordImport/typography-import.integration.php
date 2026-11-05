<?php

// Dari root src: php tests/WordImport/typography-import.integration.php [--database]
// --database memakai konfigurasi aplikasi; semua fixture di-rollback.
require __DIR__ . '/../../vendor/autoload.php';

use App\Libraries\WordImport\WordBlockExtractor;
use App\Libraries\WordImport\WordQuestionParser;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

Settings::setOutputEscapingEnabled(true);
$word = new PhpWord();
$section = $word->addSection();
foreach ([
    '1. Kutip "ganda", apostrof \'tunggal\', & tanda < >; “lengkung” ‘juga’.',
    '*A. "Ya", \'benar\' & <teks>',
    'B. Literal &quot; bukan tanda kutip',
    "\u{200F}٢\u{200E}. مَا مَعْنَى \"كِتَابٌ\"، وَقَلَمٌ؟",
    "*\u{200F}أ. \"بُكُو\"، كِتَابٌ",
    'ب. قَلَمٌ',
    '۳. Sebutkan tanda baca.',
    'Jawaban: "kata", \'kata\' & <kata>',
    '4. Pasangkan tanda baca.',
    'Tipe: Menjodohkan',
] as $line) {
    $section->addText($line, ['size' => 18]);
}
$table = $section->addTable();
foreach ([['Kiri', 'Kanan'], ['"kata", \'kata\'', '& <kata>']] as $row) {
    $table->addRow();
    foreach ($row as $cell) $table->addCell()->addText($cell);
}
$path = tempnam(sys_get_temp_dir(), 'cbt-typography-') . '.docx';
$failures = 0;
function checkImport(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    if (!$condition) $failures++;
}
try {
    IOFactory::createWriter($word, 'Word2007')->save($path);
    $blocks = (new WordBlockExtractor())->extract(IOFactory::load($path), $path);
    $questions = (new WordQuestionParser())->parse($blocks);
    checkImport(count($questions) === 4, 'Nomor Latin, Arab-Indic, dan Persia menjadi empat soal');
    checkImport(html_entity_decode($questions[0]['question'], ENT_QUOTES, 'UTF-8') === 'Kutip "ganda", apostrof \'tunggal\', & tanda < >; “lengkung” ‘juga’.', 'Tanda baca tampil sebagai karakter asli setelah satu render HTML');
    checkImport(html_entity_decode($questions[0]['options']['A'] ?? '', ENT_QUOTES, 'UTF-8') === '"Ya", \'benar\' & <teks>', 'Kutip dan simbol pada opsi tidak di-escape dua kali');
    checkImport(html_entity_decode($questions[0]['options']['B'] ?? '', ENT_QUOTES, 'UTF-8') === 'Literal &quot; bukan tanda kutip', 'Entity yang memang diketik literal tetap literal');
    checkImport(($questions[1]['correct'] ?? []) === ['A'] && count($questions[1]['options'] ?? []) === 2, 'Opsi huruf Arab dan tanda jawaban benar dikenali');
    checkImport(str_contains($questions[1]['question'] ?? '', 'مَا مَعْنَى'), 'Huruf Arab beserta harakat tetap utuh');
    checkImport(($questions[2]['answer_key'] ?? '') === '"kata", \'kata\' & <kata>', 'Kunci isian disimpan sebagai teks asli untuk pencocokan jawaban');
    checkImport(($questions[3]['matches'][0] ?? []) === ['left' => '"kata", \'kata\'', 'right' => '& <kata>'], 'Pasangan menjodohkan mempertahankan teks asli');
    checkImport((new \App\Libraries\WordImport\WordImportValidator())->snippet('&quot;kata&quot;, &#039;kata&#039; &amp; &lt;kata&gt;') === '"kata", \'kata\' & <kata>', 'Cuplikan ringkasan menggunakan karakter asli, termasuk simbol kurang dari');

    if (in_array('--database', $argv, true)) {
        define('FCPATH', dirname(__DIR__, 2) . '/public/');
        define('ENVIRONMENT', getenv('CI_ENVIRONMENT') ?: 'production');
        require FCPATH . '../app/Config/Paths.php';
        $paths = new \Config\Paths();
        require $paths->systemDirectory . '/Boot.php';
        \CodeIgniter\Boot::bootConsole($paths);
        $db = \Config\Database::connect();
        $db->transException(true);
        $db->transBegin();
        $prefix = 'typography_import_' . bin2hex(random_bytes(6));
        try {
            $db->table('users')->insert(['username' => $prefix, 'password' => password_hash('fixture-only', PASSWORD_BCRYPT, ['cost' => 4]), 'firstname' => 'Fixture', 'role' => 'admin', 'is_active' => 1]);
            $owner = (int) $db->insertID();
            $job = ['source' => $path, 'owner' => $owner, 'progress' => [], 'options' => ['module_id' => 'new', 'new_module_name' => $prefix, 'subject_name' => $prefix, 'font_size' => '24']];
            $importer = new \App\Libraries\Import\WordImporter();
            $result = $importer->process($job, static function () {});
            checkImport(($result['summary']['masuk'] ?? null) === 4, 'Importer worker menulis empat soal ke database aplikasi');
            $subject = $db->table('subjects')->where('name', $prefix)->get()->getRow();
            if (!$subject) throw new \RuntimeException('Subjek fixture gagal dibuat');
            $stored = $db->table('questions')->where('subject_id', $subject->id)->orderBy('id')->get()->getResult();
            checkImport(count($stored) === 4 && count(array_filter($stored, static fn ($q) => str_contains($q->description, 'font-size: 24px;') && !preg_match('/<h[1-6]\b/i', $q->description))) === 4, 'Font 24 px tersimpan sebagai style tanpa heading untuk semua soal');
            checkImport(str_contains($stored[1]->description, 'direction: rtl') && str_contains($stored[1]->description, 'dir="rtl"') && str_contains($stored[1]->description, 'مَا مَعْنَى'), 'Teks Arab dan style RTL tersimpan utuh');
            $options = $db->table('answers')->where('question_id', $stored[0]->id)->orderBy('position')->get()->getResult();
            checkImport(str_contains($options[0]->description ?? '', 'font-size: 24px;') && html_entity_decode(strip_tags($options[0]->description ?? ''), ENT_QUOTES, 'UTF-8') === '"Ya", \'benar\' & <teks>', 'Opsi PG mempertahankan ukuran dan tanda baca di database');
            $key = $db->table('answers')->where('question_id', $stored[2]->id)->get()->getRow();
            checkImport(($key->description ?? '') === '"kata", \'kata\' & <kata>', 'Kunci isian di database tetap teks biasa tanpa style');
            $pair = $db->table('answers')->where('question_id', $stored[3]->id)->get()->getRow();
            checkImport(($pair->description ?? '') === '"kata", \'kata\'|::|& <kata>', 'Pasangan menjodohkan di database tidak terkena markup ukuran');
            $job['options']['font_size'] = '48';
            $again = $importer->process($job, static function () {});
            checkImport(($again['summary']['masuk'] ?? null) === 0 && ($again['summary']['duplikat'] ?? null) === 4, 'Perubahan ukuran tidak melewati deteksi soal duplikat');
            $job['options']['font_size'] = '24px; color:red';
            $invalid = $importer->process($job, static function () {});
            checkImport(($invalid['status'] ?? '') === 'validation_error', 'Ukuran di luar pilihan ditolak oleh importer');
        } finally {
            $db->transRollback();
        }
        checkImport($db->table('users')->where('username', $prefix)->countAllResults() === 0 && $db->table('subjects')->where('name', $prefix)->countAllResults() === 0 && $db->table('modules')->where('name', $prefix)->countAllResults() === 0, 'Seluruh fixture database terhapus lewat rollback');
    }
} finally {
    @unlink($path);
    @unlink(substr($path, 0, -5));
}
exit($failures === 0 ? 0 : 1);
