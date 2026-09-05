<?php

namespace App\Libraries\WordImport;

use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\PhpWord;

/**
 * Membaca objek PhpWord hasil IOFactory::load() dan mengubahnya jadi array
 * blok terstruktur (paragraf/tabel) yang siap dibaca WordQuestionParser.
 *
 * Lihat docs/superpowers/specs/2026-08-17-humane-word-import-format-design.md
 * untuk aturan format lengkapnya.
 */
class WordBlockExtractor
{
    private const ALLOWED_IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'];

    private const NUMBERING_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private string $uploadDir;
    private string $uploadUrlPrefix;

    /**
     * Peta numId => [ilvl => 'letter'|'number'|'bullet'], dibaca dari
     * word/numbering.xml. Kosong kalau file .docx-nya tidak diberikan.
     *
     * @var array<int, array<int, string>>
     */
    private array $listFormats = [];

    /**
     * Gambar yang sudah ditandai di HTML tapi belum ditulis ke disk:
     * nama file => data biner.
     *
     * @var array<string, string>
     */
    private array $pendingImages = [];

    public function __construct(?string $uploadDir = null, string $uploadUrlPrefix = '/uploads/questions/')
    {
        $this->uploadDir = $uploadDir ?? (defined('FCPATH') ? FCPATH . 'uploads/questions/' : sys_get_temp_dir() . '/uploads/questions/');
        $this->uploadUrlPrefix = $uploadUrlPrefix;
    }

    /**
     * @param ?string $docxPath Path file .docx sumbernya. Opsional, tapi tanpa
     *                          ini format penomoran list (huruf vs angka) tidak
     *                          bisa dibaca -- PhpWord tidak mem-parse
     *                          word/numbering.xml.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extract(PhpWord $phpWord, ?string $docxPath = null): array
    {
        $this->listFormats = $docxPath === null ? [] : $this->readListFormats($docxPath);
        $this->pendingImages = [];

        $blocks = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $blocks = array_merge($blocks, $this->processElement($element));
            }
        }
        return $blocks;
    }

    /** @return array<int, array<string, mixed>> */
    private function processElement($element): array
    {
        if ($element instanceof Table) {
            return [$this->processTable($element)];
        }

        if ($element instanceof Image) {
            $tag = $this->stageImageAndBuildTag($element);
            return $tag === null ? [] : [$this->line($tag, false, 0)];
        }

        if (method_exists($element, 'getElements')) {
            return $this->processRun($element);
        }

        if (method_exists($element, 'getText')) {
            $text = trim($element->getText());
            return $text === '' ? [] : [$this->line(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false, 0)];
        }

        return [];
    }

    /** @return array<int, array<string, mixed>> */
    private function processRun($element): array
    {
        $isListItem = $element instanceof ListItemRun || $element instanceof ListItem;
        // Word asli sering tidak menulis <w:ilvl> untuk level teratas (0
        // adalah default kalau elemen itu tidak ada di XML), jadi PhpWord\Reader
        // mengembalikan null, bukan 0 -- beda dari dokumen yang ditulis lewat
        // PhpWord\Writer sendiri yang selalu eksplisit.
        $depth = $isListItem ? ($element->getDepth() ?? 0) : 0;
        $format = $isListItem ? $this->listFormatOf($element, $depth) : null;

        if ($element instanceof ListItem) {
            // ListItem (beda dari ListItemRun) membungkus satu Text tunggal.
            $paragraphText = htmlspecialchars($element->getText(), ENT_QUOTES, 'UTF-8');
        } else {
            $paragraphText = '';
            foreach ($element->getElements() as $child) {
                if ($child instanceof TextBreak) {
                    $paragraphText .= "\n";
                } elseif ($child instanceof Image) {
                    $tag = $this->stageImageAndBuildTag($child);
                    if ($tag !== null) {
                        $paragraphText .= '<br>' . $tag . '<br>';
                    }
                } elseif (method_exists($child, 'getText')) {
                    $paragraphText .= htmlspecialchars($child->getText(), ENT_QUOTES, 'UTF-8');
                }
            }
        }

        $lines = explode("\n", $paragraphText);
        $blocks = [];
        $isFirstLine = true;
        foreach ($lines as $rawLine) {
            $lineText = trim($rawLine);
            if ($lineText === '') {
                continue;
            }
            $blocks[] = $this->line(
                $lineText,
                $isListItem && $isFirstLine,
                $isFirstLine ? $depth : 0,
                $isFirstLine ? $format : null
            );
            $isFirstLine = false;
        }
        return $blocks;
    }

    private function line(string $text, bool $isListItem, int $depth, ?string $format = null): array
    {
        return [
            'kind'         => 'line',
            'text'         => $text,
            'is_list_item' => $isListItem,
            'list_depth'   => $depth,
            'list_format'  => $format,
        ];
    }

    /**
     * PhpWord\Reader menamai style list dengan "PHPWordList{numId}", jadi numId
     * asli dari dokumen masih bisa dipulihkan dari situ.
     */
    private function listFormatOf($element, int $depth): ?string
    {
        if (!method_exists($element, 'getStyle')) {
            return null;
        }
        $style = $element->getStyle();
        if ($style === null || !method_exists($style, 'getNumStyle')) {
            return null;
        }
        if (!preg_match('/^PHPWordList(\d+)$/', (string) $style->getNumStyle(), $m)) {
            return null;
        }

        return $this->listFormats[(int) $m[1]][$depth] ?? null;
    }

    /**
     * Baca word/numbering.xml dan petakan numId => ilvl => jenis penomoran.
     *
     * @return array<int, array<int, string>>
     */
    private function readListFormats(string $docxPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($docxPath) !== true) {
            return [];
        }
        $xml = $zip->getFromName('word/numbering.xml');
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            return [];
        }

        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::NUMBERING_NS);

        $abstract = [];
        foreach ($xpath->query('//w:abstractNum') as $abstractNode) {
            $abstractId = $abstractNode->getAttributeNS(self::NUMBERING_NS, 'abstractNumId');
            foreach ($xpath->query('./w:lvl', $abstractNode) as $levelNode) {
                $ilvl = (int) $levelNode->getAttributeNS(self::NUMBERING_NS, 'ilvl');
                $fmtNode = $xpath->query('./w:numFmt', $levelNode)->item(0);
                if ($fmtNode === null) {
                    continue;
                }
                $kind = $this->numFmtToKind($fmtNode->getAttributeNS(self::NUMBERING_NS, 'val'));
                if ($kind !== null) {
                    $abstract[$abstractId][$ilvl] = $kind;
                }
            }
        }

        $formats = [];
        foreach ($xpath->query('//w:num') as $numNode) {
            $numId = (int) $numNode->getAttributeNS(self::NUMBERING_NS, 'numId');
            $refNode = $xpath->query('./w:abstractNumId', $numNode)->item(0);
            if ($refNode === null) {
                continue;
            }
            $abstractId = $refNode->getAttributeNS(self::NUMBERING_NS, 'val');
            if (isset($abstract[$abstractId])) {
                $formats[$numId] = $abstract[$abstractId];
            }
        }

        return $formats;
    }

    private function numFmtToKind(string $numFmt): ?string
    {
        return match ($numFmt) {
            'upperLetter', 'lowerLetter' => 'letter',
            'decimal', 'decimalZero', 'ordinal' => 'number',
            'bullet' => 'bullet',
            default => null,
        };
    }

    /**
     * Gambar hanya ditandai di sini, belum ditulis ke disk: dokumen yang
     * ternyata gagal divalidasi atau gagal disimpan tidak boleh meninggalkan
     * file di folder upload. Penulisannya dilakukan flushImages().
     */
    private function stageImageAndBuildTag(Image $image): ?string
    {
        $raw = $image->getImageStringData();
        if (!$raw) {
            return null;
        }
        $ext = strtolower($image->getImageExtension());
        if (!in_array($ext, self::ALLOWED_IMAGE_EXTS, true)) {
            return null;
        }

        // Hitungan gambar ikut masuk nama file: uniqid() sendiri bisa
        // mengembalikan nilai sama untuk dua panggilan dalam mikrodetik yang
        // sama, dan tabrakan nama berarti satu gambar menimpa gambar lain.
        $filename = uniqid('img_') . '_' . count($this->pendingImages) . '.' . $ext;
        $this->pendingImages[$filename] = $raw;

        $src = rtrim($this->uploadUrlPrefix, '/') . '/' . $filename;
        return '<img src="' . $src . '" style="max-width:100%; height:auto; margin:10px 0;" class="img-fluid rounded shadow-sm">';
    }

    /**
     * Menulis gambar hasil extract() ke folder upload.
     *
     * @param string[]|null $onlyFilenames Kalau diisi, hanya nama file inilah
     *                                     yang ditulis -- dipakai supaya gambar
     *                                     milik soal yang ditolak atau kembar
     *                                     tidak ikut mendarat di folder upload.
     *
     * @return string[] Path file yang berhasil ditulis, supaya pemanggilnya
     *                  bisa menghapusnya lagi kalau langkah berikutnya gagal.
     *
     * @throws WordImportException kalau ada gambar yang gagal ditulis -- lebih
     *                             baik impornya dibatalkan daripada soal
     *                             tersimpan menunjuk gambar yang tidak ada.
     */
    public function flushImages(?array $onlyFilenames = null): array
    {
        if ($onlyFilenames !== null) {
            $this->pendingImages = array_intersect_key($this->pendingImages, array_flip($onlyFilenames));
        }

        if ($this->pendingImages === []) {
            return [];
        }

        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
            throw new WordImportException('Folder penyimpanan gambar tidak bisa dibuat. Hubungi administrator.');
        }

        $written = [];
        foreach ($this->pendingImages as $filename => $raw) {
            $path = $this->uploadDir . $filename;
            if (file_put_contents($path, $raw) === false) {
                throw new WordImportException('Gagal menyimpan gambar dari dokumen ke folder upload.');
            }
            $written[] = $path;
        }
        $this->pendingImages = [];

        return $written;
    }

    private function processTable(Table $table): array
    {
        $htmlRows = [];
        $rawRows = [];

        foreach ($table->getRows() as $row) {
            $htmlCells = [];
            $rawCells = [];
            foreach ($row->getCells() as $cell) {
                $cellBlocks = [];
                foreach ($cell->getElements() as $cellElement) {
                    $cellBlocks = array_merge($cellBlocks, $this->processElement($cellElement));
                }
                $htmlCells[] = implode('<br>', array_map(
                    fn (array $b) => $b['kind'] === 'table' ? $b['html'] : $b['text'],
                    $cellBlocks
                ));
                $rawCells[] = trim(implode(' ', array_map(
                    fn (array $b) => trim(strip_tags($b['kind'] === 'table' ? $b['html'] : $b['text'])),
                    $cellBlocks
                )));
            }
            $htmlRows[] = $htmlCells;
            $rawRows[] = $rawCells;
        }

        $html = '<div class="table-responsive my-3"><table class="table table-bordered table-sm" style="border-collapse: collapse; width: 100%;" border="1">';
        foreach ($htmlRows as $htmlCells) {
            $html .= '<tr>';
            foreach ($htmlCells as $cellHtml) {
                $html .= '<td style="padding: 8px; border: 1px solid #dee2e6;">' . $cellHtml . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table></div>';

        return [
            'kind' => 'table',
            'html' => $html,
            'rows' => $rawRows,
        ];
    }
}
