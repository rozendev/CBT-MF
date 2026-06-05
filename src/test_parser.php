<?php
require 'vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;

function processPhpWordElement($element)
{
    $blocks = [];
    
    if ($element instanceof \PhpOffice\PhpWord\Element\TextRun || method_exists($element, 'getElements')) {
        $paragraphText = '';
        foreach ($element->getElements() as $textElement) {
            if (method_exists($textElement, 'getText')) {
                $paragraphText .= htmlspecialchars($textElement->getText(), ENT_QUOTES, 'UTF-8');
            } elseif (get_class($textElement) === 'PhpOffice\PhpWord\Element\TextBreak') {
                $paragraphText .= "\n";
            } elseif ($textElement instanceof \PhpOffice\PhpWord\Element\Image) {
                $raw = $textElement->getImageStringData();
                if ($raw) {
                    $ext = $textElement->getImageExtension();
                    $filename = uniqid('img_') . '.' . $ext;
                    $paragraphText .= "<br><img src=\"/uploads/questions/{$filename}\"><br>";
                }
            }
        }
        $lines = explode("\n", $paragraphText);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $blocks[] = $line;
            }
        }
    } elseif ($element instanceof \PhpOffice\PhpWord\Element\Image) {
        $raw = $element->getImageStringData();
        if ($raw) {
            $ext = $element->getImageExtension();
            $filename = uniqid('img_') . '.' . $ext;
            $blocks[] = "<img src=\"/uploads/questions/{$filename}\">";
        }
    } elseif (method_exists($element, 'getText')) {
        $text = trim($element->getText());
        if (!empty($text)) {
            $blocks[] = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }
    
    return $blocks;
}

$phpWord = IOFactory::load('test_doc.docx');
$blocks = [];
foreach ($phpWord->getSections() as $section) {
    foreach ($section->getElements() as $element) {
        $blocks = array_merge($blocks, processPhpWordElement($element));
    }
}
print_r($blocks);
