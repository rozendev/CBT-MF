<?php

namespace Tests\Support;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Helper untuk bikin file .docx sementara di test, lewat PhpWord writer,
 * lalu dibaca ulang lewat IOFactory::load() — supaya test benar-benar
 * memverifikasi jalur baca (Reader), bukan cuma struktur objek PhpWord.
 */
class WordFixtureBuilder
{
    /**
     * @param callable(\PhpOffice\PhpWord\Element\Section): void $build
     */
    public static function buildDocx(callable $build): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $build($section);

        $path = sys_get_temp_dir() . '/wordimport_fixture_' . uniqid('', true) . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }
}
