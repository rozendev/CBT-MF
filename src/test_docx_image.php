<?php
require 'vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

// Create dummy image
$imgData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents('test_img.png', $imgData);

// Create docx
$phpWord = new PhpWord();
$section = $phpWord->addSection();
$section->addText("Hello World");
$section->addImage('test_img.png');

$objWriter = IOFactory::createWriter($phpWord, 'Word2007');
$objWriter->save('test_doc.docx');

// Read docx
$reader = IOFactory::load('test_doc.docx');
foreach ($reader->getSections() as $section) {
    foreach ($section->getElements() as $element) {
        if ($element instanceof \PhpOffice\PhpWord\Element\Image) {
            echo "Source: " . $element->getSource() . "\n";
            echo "String Data length: " . strlen((string)$element->getImageStringData()) . "\n";
            echo "String Data Base64 Check: " . (base64_encode(base64_decode((string)$element->getImageStringData(), true)) === (string)$element->getImageStringData() ? 'Yes' : 'No') . "\n";
        }
    }
}
