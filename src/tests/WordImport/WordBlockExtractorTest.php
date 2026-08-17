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
}
