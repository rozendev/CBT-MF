<?php
namespace Tests\Import;

use App\Libraries\Import\ImportUpload;
use App\Libraries\Import\ImportFailure;
use PHPUnit\Framework\TestCase;

final class ImportUploadTest extends TestCase
{
    public function testExpandedOfficeArchiveIsBoundedBeforeParsing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cbt-zip-') . '.docx';
        $zip = new \ZipArchive();
        $large = tmpfile();
        for ($i = 0; $i < 65; $i++) fwrite($large, str_repeat(' ', 1024 * 1024));
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFile(stream_get_meta_data($large)['uri'], 'word/document.xml');
        $zip->close();
        fclose($large);
        try {
            $this->expectException(ImportFailure::class);
            ImportUpload::checkArchive($path);
        } finally { unlink($path); unlink(substr($path, 0, -5)); }
    }
}
