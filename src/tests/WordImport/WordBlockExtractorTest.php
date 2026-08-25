<?php

namespace Tests\WordImport;

use App\Libraries\WordImport\WordBlockExtractor;
use PhpOffice\PhpWord\IOFactory;
use PHPUnit\Framework\TestCase;
use Tests\Support\WordFixtureBuilder;

class WordBlockExtractorTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir() . '/wordimport_uploads_' . uniqid() . '/';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadDir)) {
            array_map('unlink', glob($this->uploadDir . '*') ?: []);
            rmdir($this->uploadDir);
        }
    }

    public function testPlainParagraphBecomesLineBlock(): void
    {
        $path = WordFixtureBuilder::buildDocx(function ($section) {
            $section->addText('1. Siapa penemu bola lampu?');
            $section->addText('*B. Thomas Alva Edison');
        });

        $phpWord = IOFactory::load($path);
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord);
        unlink($path);

        $this->assertCount(2, $blocks);
        $this->assertSame('line', $blocks[0]['kind']);
        $this->assertSame('1. Siapa penemu bola lampu?', $blocks[0]['text']);
        $this->assertFalse($blocks[0]['is_list_item']);
        $this->assertSame(0, $blocks[0]['list_depth']);
        $this->assertSame('*B. Thomas Alva Edison', $blocks[1]['text']);
    }

    public function testNativeWordListItemsCarryDepthMetadata(): void
    {
        $path = WordFixtureBuilder::buildDocx(function ($section) {
            $section->addListItem('Ibukota Jepang adalah?', 0, null, 'listLevel0');
            $section->addListItem('Osaka', 1, null, 'listLevel1');
            $section->addListItem('*Tokyo', 1, null, 'listLevel1');
        });

        $phpWord = IOFactory::load($path);
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord);
        unlink($path);

        $this->assertCount(3, $blocks);

        $this->assertTrue($blocks[0]['is_list_item']);
        $this->assertSame(0, $blocks[0]['list_depth']);
        $this->assertSame('Ibukota Jepang adalah?', $blocks[0]['text']);

        $this->assertTrue($blocks[1]['is_list_item']);
        $this->assertSame(1, $blocks[1]['list_depth']);
        $this->assertSame('Osaka', $blocks[1]['text']);

        $this->assertTrue($blocks[2]['is_list_item']);
        $this->assertSame(1, $blocks[2]['list_depth']);
        $this->assertSame('*Tokyo', $blocks[2]['text']);
    }

    public function testTopLevelNativeListWithoutExplicitIlvlDefaultsToDepthZero(): void
    {
        // Word (aplikasi asli, bukan PhpWord) sering TIDAK menulis <w:ilvl>
        // untuk item list level teratas (0 adalah default kalau elemen itu
        // tidak ada) -- beda dari PhpWord\Writer yang selalu menulisnya
        // eksplisit. PhpWord\Reader::getAttribute() mengembalikan null kalau
        // elemen tidak ada, jadi ListItemRun::getDepth() bisa null untuk
        // dokumen yang benar-benar diketik manual di Word.
        $path = WordFixtureBuilder::buildDocx(function ($section) {
            $section->addListItem('Ibukota Jepang adalah?', 0, null, 'listLevel0');
        });
        $this->stripIlvlElement($path);

        $phpWord = IOFactory::load($path);
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord);
        unlink($path);

        $this->assertCount(1, $blocks);
        $this->assertTrue($blocks[0]['is_list_item']);
        $this->assertSame(0, $blocks[0]['list_depth']);
        $this->assertSame('Ibukota Jepang adalah?', $blocks[0]['text']);
    }

    public function testLetterNumberedListIsMarkedWithLetterFormat(): void
    {
        // Word/LibreOffice AutoCorrect mengubah ketikan "A. " jadi list
        // ber-huruf otomatis di level teratas (ilvl 0). Format penomorannya
        // yang membedakan daftar opsi dari daftar soal, bukan kedalamannya.
        $path = WordFixtureBuilder::buildDocx(function ($section, $phpWord) {
            $phpWord->addNumberingStyle('optionLetters', [
                'type'   => 'multilevel',
                'levels' => [
                    ['format' => 'upperLetter', 'text' => '%1.', 'left' => 360, 'hanging' => 360],
                ],
            ]);
            $section->addListItem('Osaka', 0, null, 'optionLetters');
        });

        $phpWord = IOFactory::load($path);
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord, $path);
        unlink($path);

        $this->assertCount(1, $blocks);
        $this->assertTrue($blocks[0]['is_list_item']);
        $this->assertSame(0, $blocks[0]['list_depth']);
        $this->assertSame('letter', $blocks[0]['list_format']);
    }

    public function testDecimalNumberedListIsMarkedWithNumberFormat(): void
    {
        $path = WordFixtureBuilder::buildDocx(function ($section, $phpWord) {
            $phpWord->addNumberingStyle('questionNumbers', [
                'type'   => 'multilevel',
                'levels' => [
                    ['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360],
                ],
            ]);
            $section->addListItem('Ibukota Jepang adalah?', 0, null, 'questionNumbers');
        });

        $phpWord = IOFactory::load($path);
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord, $path);
        unlink($path);

        $this->assertSame('number', $blocks[0]['list_format']);
    }

    public function testListFormatIsNullWhenNumberingDefinitionIsUnavailable(): void
    {
        $path = WordFixtureBuilder::buildDocx(function ($section) {
            $section->addListItem('Ibukota Jepang adalah?', 0, null, 'listLevel0');
        });

        $phpWord = IOFactory::load($path);
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord, $path);
        unlink($path);

        $this->assertNull($blocks[0]['list_format']);
    }

    private function stripIlvlElement(string $docxPath): void
    {
        $zip = new \ZipArchive();
        $zip->open($docxPath);
        $xml = $zip->getFromName('word/document.xml');
        $xml = preg_replace('/<w:ilvl[^>]*\/>/', '', $xml);
        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
    }

    public function testStandaloneImageIsSavedAndReferencedAsImgTag(): void
    {
        // 1x1 pixel PNG transparan, base64-encoded.
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $imgPath = tempnam(sys_get_temp_dir(), 'wordimport_img_') . '.png';
        file_put_contents($imgPath, $pngData);

        $path = WordFixtureBuilder::buildDocx(function ($section) use ($imgPath) {
            $section->addImage($imgPath, ['width' => 50, 'height' => 50]);
        });
        unlink($imgPath);

        $phpWord = IOFactory::load($path);
        $extractor = new WordBlockExtractor($this->uploadDir);
        $blocks = $extractor->extract($phpWord);
        unlink($path);

        $this->assertCount(1, $blocks);
        $this->assertSame('line', $blocks[0]['kind']);
        $this->assertStringContainsString('<img src="/uploads/questions/', $blocks[0]['text']);

        $extractor->flushImages();
        $savedFiles = glob($this->uploadDir . '*.png');
        $this->assertCount(1, $savedFiles);
    }

    public function testTableBecomesTableBlockWithHtmlAndRawRows(): void
    {
        $path = WordFixtureBuilder::buildDocx(function ($section) {
            $table = $section->addTable();
            $table->addRow();
            $table->addCell(2000)->addText('Negara');
            $table->addCell(2000)->addText('Ibukota');
            $table->addRow();
            $table->addCell(2000)->addText('Indonesia');
            $table->addCell(2000)->addText('Jakarta');
        });

        $phpWord = IOFactory::load($path);
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord);
        unlink($path);

        $this->assertCount(1, $blocks);
        $this->assertSame('table', $blocks[0]['kind']);
        $this->assertStringContainsString('<table', $blocks[0]['html']);
        $this->assertStringContainsString('Jakarta', $blocks[0]['html']);
        $this->assertSame(
            [['Negara', 'Ibukota'], ['Indonesia', 'Jakarta']],
            $blocks[0]['rows']
        );
    }

    public function testInlineImageWithinParagraphIsWrappedAndSaved(): void
    {
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $imgPath = tempnam(sys_get_temp_dir(), 'wordimport_img_') . '.png';
        file_put_contents($imgPath, $pngData);

        $path = WordFixtureBuilder::buildDocx(function ($section) use ($imgPath) {
            $textRun = $section->addTextRun();
            $textRun->addText('Lihat gambar: ');
            $textRun->addImage($imgPath, ['width' => 50, 'height' => 50]);
            $textRun->addText(' Apa itu?');
        });
        unlink($imgPath);

        $phpWord = IOFactory::load($path);
        $extractor = new WordBlockExtractor($this->uploadDir);
        $blocks = $extractor->extract($phpWord);
        unlink($path);

        $extractor->flushImages();
        $savedFiles = glob($this->uploadDir . '*.png');
        $this->assertCount(1, $savedFiles);

        $allText = implode(' ', array_column($blocks, 'text'));
        $this->assertStringContainsString('<img src="/uploads/questions/', $allText);
        $this->assertStringContainsString('Lihat gambar', $allText);
        $this->assertStringContainsString('Apa itu', $allText);
    }

    public function testImagesAreNotWrittenBeforeFlush(): void
    {
        $imgPath = $this->onePixelPng();
        $path = WordFixtureBuilder::buildDocx(function ($section) use ($imgPath) {
            $section->addImage($imgPath, ['width' => 50, 'height' => 50]);
        });
        unlink($imgPath);

        $phpWord = IOFactory::load($path);
        $extractor = new WordBlockExtractor($this->uploadDir);
        $extractor->extract($phpWord);
        unlink($path);

        // Dokumen yang nanti ditolak validator tidak boleh meninggalkan file.
        $this->assertSame([], glob($this->uploadDir . '*.png') ?: []);

        $written = $extractor->flushImages();

        $this->assertCount(1, $written);
        $this->assertFileExists($written[0]);
        $this->assertCount(1, glob($this->uploadDir . '*.png'));
    }

    public function testFlushIsIdempotentSoImagesAreWrittenOnce(): void
    {
        $imgPath = $this->onePixelPng();
        $path = WordFixtureBuilder::buildDocx(function ($section) use ($imgPath) {
            $section->addImage($imgPath, ['width' => 50, 'height' => 50]);
        });
        unlink($imgPath);

        $phpWord = IOFactory::load($path);
        $extractor = new WordBlockExtractor($this->uploadDir);
        $extractor->extract($phpWord);
        unlink($path);

        $extractor->flushImages();

        $this->assertSame([], $extractor->flushImages());
        $this->assertCount(1, glob($this->uploadDir . '*.png'));
    }

    public function testTwoImagesInOneDocumentGetDistinctFilenames(): void
    {
        $imgPath = $this->onePixelPng();
        $path = WordFixtureBuilder::buildDocx(function ($section) use ($imgPath) {
            $section->addImage($imgPath, ['width' => 50, 'height' => 50]);
            $section->addImage($imgPath, ['width' => 50, 'height' => 50]);
        });
        unlink($imgPath);

        $phpWord = IOFactory::load($path);
        $extractor = new WordBlockExtractor($this->uploadDir);
        $blocks = $extractor->extract($phpWord);
        unlink($path);

        $written = $extractor->flushImages();

        // Dua panggilan uniqid() dalam mikrodetik yang sama bisa bernilai sama;
        // kalau namanya bertabrakan, satu gambar menimpa gambar lainnya.
        $this->assertCount(2, $written);
        $this->assertCount(2, array_unique($written));
        $this->assertCount(2, glob($this->uploadDir . '*.png'));
        $this->assertNotSame($blocks[0]['text'], $blocks[1]['text']);
    }

    /** 1x1 pixel PNG transparan, ditulis ke file sementara. */
    private function onePixelPng(): string
    {
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $imgPath = tempnam(sys_get_temp_dir(), 'wordimport_img_') . '.png';
        file_put_contents($imgPath, $pngData);

        return $imgPath;
    }
}
