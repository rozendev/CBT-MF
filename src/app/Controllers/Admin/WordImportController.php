<?php
namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ModuleModel;
use App\Libraries\Import\ImportUpload;
use PhpOffice\PhpWord\IOFactory;

class WordImportController extends BaseController
{
    public function index()
    {
        return view('admin/questions/word_import', [
            'title' => 'Import Soal dari Word',
            'modules' => (new ModuleModel())->findAll(),
        ]);
    }

    public function process()
    {
        try {
            $job = ImportUpload::enqueue(
                'word', (int) session('user_id'), $this->request->getFile('word_file'), [
                    'module_id' => (string) $this->request->getPost('module_id'),
                    'new_module_name' => trim((string) $this->request->getPost('new_module_name')),
                    'subject_name' => trim((string) $this->request->getPost('subject_name')),
                ], (string) $this->request->getPost('import_request_id')
            );
            return $this->response->setStatusCode(202)->setHeader('Cache-Control', 'no-store')->setJSON($job);
        } catch (\Throwable $error) { return ImportController::error($error); }
    }

    public function downloadTemplate()
    {
        $phpWord = new \PhpOffice\PhpWord\PhpWord();

        // Numbering style-nya wajib didaftarkan: kalau tidak, addListItem()
        // menulis <w:numId w:val=""/> yang tidak menunjuk ke definisi apa pun,
        // jadi contoh "list bawaan Word"-nya bukan list beneran di Word.
        // Level 0 bernomor angka (soal), level 1 berhuruf (opsi) -- sama persis
        // dengan yang dihasilkan AutoCorrect Word saat user mengetik sendiri.
        $phpWord->addNumberingStyle('templateList', [
            'type'   => 'multilevel',
            'levels' => [
                ['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360, 'tabPos' => 360],
                ['format' => 'upperLetter', 'text' => '%2.', 'left' => 720, 'hanging' => 360, 'tabPos' => 720],
            ],
        ]);

        $section = $phpWord->addSection();

        $fontTitle  = ['bold' => true, 'size' => 14];
        $fontNormal = ['size' => 12];
        $tableStyle = ['borderSize' => 6, 'borderColor' => '999999'];

        $section->addText('TEMPLATE IMPORT SOAL (FORMAT BARU)', $fontTitle);
        $section->addTextBreak(1);

        // 1) PG Tunggal - angka & huruf polos, jawaban ditandai *
        $section->addText('1. Siapa penemu bola lampu?', $fontNormal);
        $section->addText('A. Albert Einstein', $fontNormal);
        $section->addText('*B. Thomas Alva Edison', $fontNormal);
        $section->addText('C. Isaac Newton', $fontNormal);
        $section->addText('D. Nikola Tesla', $fontNormal);
        $section->addTextBreak(1);

        // 2) PG Kompleks - lebih dari satu opsi ber-bintang
        $section->addText('2. Pilihlah semua jawaban yang merupakan nama benua:', $fontNormal);
        $section->addText('*A. Asia', $fontNormal);
        $section->addText('B. Pasifik', $fontNormal);
        $section->addText('*C. Eropa', $fontNormal);
        $section->addText('D. Hindia', $fontNormal);
        $section->addText('*E. Afrika', $fontNormal);
        $section->addTextBreak(1);

        // 3) Soal & opsi lewat fitur List/Numbering bawaan Word
        // Catatan penjelas TIDAK ditulis sebagai paragraf terpisah: baris bebas
        // di antara opsi soal sebelumnya dan soal berikutnya akan ikut nyambung
        // ke opsi terakhir yang sedang berjalan (lihat "Multi line question and
        // option text is joined with br" di WordQuestionParser). Makanya
        // penjelasannya digabung ke teks soal lewat list item ini sendiri.
        $section->addListItem('Ibukota Jepang adalah? (soal dan opsi ini ditulis lewat fitur List/Numbering bawaan Word, tanpa mengetik angka/huruf)', 0, $fontNormal, 'templateList');
        $section->addListItem('Osaka', 1, $fontNormal, 'templateList');
        $section->addListItem('*Tokyo', 1, $fontNormal, 'templateList');
        $section->addListItem('Kyoto', 1, $fontNormal, 'templateList');
        $section->addTextBreak(1);

        // 4) Esai dengan kunci jawaban opsional
        $section->addText('4. Siapa nama presiden pertama Republik Indonesia?', $fontNormal);
        $section->addText('Jawaban: Ir. Soekarno', $fontNormal);
        $section->addTextBreak(1);

        // 5) Esai tanpa kunci sama sekali - tetap valid
        $section->addText('5. Jelaskan pendapatmu tentang pentingnya menjaga lingkungan.', $fontNormal);
        $section->addTextBreak(1);

        // 6) Menjodohkan lewat tabel
        $section->addText('6. Pasangkan negara berikut dengan ibukotanya!', $fontNormal);
        $section->addText('Tipe: Menjodohkan', $fontNormal);
        $table1 = $section->addTable($tableStyle);
        $table1->addRow();
        $table1->addCell(2500)->addText('Negara', $fontTitle);
        $table1->addCell(2500)->addText('Ibukota', $fontTitle);
        $table1->addRow();
        $table1->addCell(2500)->addText('Indonesia', $fontNormal);
        $table1->addCell(2500)->addText('Jakarta', $fontNormal);
        $table1->addRow();
        $table1->addCell(2500)->addText('Jepang', $fontNormal);
        $table1->addCell(2500)->addText('Tokyo', $fontNormal);
        $table1->addRow();
        $table1->addCell(2500)->addText('Korea Selatan', $fontNormal);
        $table1->addCell(2500)->addText('Seoul', $fontNormal);
        $section->addTextBreak(1);

        // 7) Benar/Salah lewat tabel
        $section->addText('7. Tentukan benar atau salah untuk pernyataan berikut!', $fontNormal);
        $section->addText('Tipe: Benar/Salah', $fontNormal);
        $table2 = $section->addTable($tableStyle);
        $table2->addRow();
        $table2->addCell(4000)->addText('Pernyataan', $fontTitle);
        $table2->addCell(2000)->addText('Jawaban', $fontTitle);
        $table2->addRow();
        $table2->addCell(4000)->addText('Matahari terbit dari timur', $fontNormal);
        $table2->addCell(2000)->addText('Benar', $fontNormal);
        $table2->addRow();
        $table2->addCell(4000)->addText('Bumi itu berbentuk datar', $fontNormal);
        $table2->addCell(2000)->addText('Salah', $fontNormal);
        $section->addTextBreak(1);

        // 8) Soal dengan tabel data referensi biasa (BUKAN tabel pasangan, tanpa "Tipe:")
        $section->addText('8. Soal dengan tabel data:', $fontNormal);
        $table3 = $section->addTable($tableStyle);
        $table3->addRow();
        $table3->addCell(2000)->addText('Nama', $fontTitle);
        $table3->addCell(2000)->addText('Usia', $fontTitle);
        $table3->addRow();
        $table3->addCell(2000)->addText('Andi', $fontNormal);
        $table3->addCell(2000)->addText('15 Tahun', $fontNormal);
        $section->addText('Berdasarkan tabel di atas, berapakah usia Andi?', $fontNormal);
        $section->addText('A. 10 Tahun', $fontNormal);
        $section->addText('*B. 15 Tahun', $fontNormal);
        $section->addText('C. 20 Tahun', $fontNormal);

        $fileName = 'Template_Import_Soal_CBT.docx';

        // Ditulis ke buffer, bukan ke tempnam(): file sementaranya tidak pernah
        // dihapus dan menumpuk di direktori temp setiap kali template diunduh.
        // PhpWord tetap memakai temp file internal untuk zip-nya, tapi yang itu
        // dibersihkan sendiri olehnya.
        ob_start();
        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        } catch (\Throwable $e) {
            ob_end_clean();
            log_message('critical', 'WordImportController::downloadTemplate gagal: {exception}', ['exception' => $e]);
            return redirect()->back()->with('error', 'Gagal membuat file template. Silakan coba lagi.');
        }
        $content = (string) ob_get_clean();

        // download() mengembalikan null kalau datanya string kosong, dan
        // controller yang balas null bikin browser menerima halaman kosong
        // tanpa penjelasan apa pun.
        if ($content === '') {
            log_message('critical', 'WordImportController::downloadTemplate menghasilkan dokumen kosong.');
            return redirect()->back()->with('error', 'Gagal membuat file template. Silakan coba lagi.');
        }

        return $this->response->download($fileName, $content);
    }
}
