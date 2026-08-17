<?php

namespace Tests\Support;

use PhpOffice\PhpWord\IOFactory;
use PHPUnit\Framework\TestCase;

class WordFixtureBuilderTest extends TestCase
{
    public function testBuildDocxReturnsLoadablePath(): void
    {
        $path = WordFixtureBuilder::buildDocx(function ($section) {
            $section->addText('Halo dunia');
        });

        $this->assertFileExists($path);

        $phpWord = IOFactory::load($path);
        $sections = $phpWord->getSections();
        $this->assertCount(1, $sections);

        unlink($path);
    }
}
