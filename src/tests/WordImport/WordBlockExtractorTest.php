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
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord);
        unlink($path);

        $this->assertCount(1, $blocks);
        $this->assertSame('line', $blocks[0]['kind']);
        $this->assertStringContainsString('<img src="/uploads/questions/', $blocks[0]['text']);

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
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord);
        unlink($path);

        $savedFiles = glob($this->uploadDir . '*.png');
        $this->assertCount(1, $savedFiles);

        $allText = implode(' ', array_column($blocks, 'text'));
        $this->assertStringContainsString('<img src="/uploads/questions/', $allText);
        $this->assertStringContainsString('Lihat gambar', $allText);
        $this->assertStringContainsString('Apa itu', $allText);
    }
}
