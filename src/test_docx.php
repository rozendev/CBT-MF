<?php
function extractTextFromDocx($filepath)
{
    $zip = new \ZipArchive();
    if ($zip->open($filepath) === true) {
        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xmlContent === false) {
            echo "Failed to read word/document.xml\n";
            return [];
        }

        $xmlContent = str_replace('</w:p>', "\n", $xmlContent);
        $xmlContent = preg_replace('/<w:br[^>]*>/i', "\n", $xmlContent);
        $xmlContent = html_entity_decode($xmlContent, ENT_QUOTES, 'UTF-8');
        $text = strip_tags($xmlContent);

        $lines = explode("\n", $text);
        $blocks = [];
        foreach ($lines as $line) {
            $line = trim(str_replace("\xC2\xA0", ' ', $line));
            if (!empty($line)) {
                $blocks[] = $line;
            }
        }
        return $blocks;
    }
    echo "ZipArchive failed to open\n";
    return [];
}

require 'vendor/autoload.php';

$phpWord = new \PhpOffice\PhpWord\PhpWord();
$section = $phpWord->addSection();
$fontStyleNormal = ['size' => 12];
$section->addText('Q:1) Siapa penemu bola lampu?', $fontStyleNormal);
$section->addText('A:) Albert Einstein', $fontStyleNormal);
$section->addText('B:) Thomas Alva Edison', $fontStyleNormal);
$section->addText('RIGHT:B', $fontStyleNormal);

$tempFile = 'test.docx';
$objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$objWriter->save($tempFile);

$blocks = extractTextFromDocx($tempFile);
print_r($blocks);

$questions = [];
$currentQuestion = null;
foreach ($blocks as $text) {
    if (preg_match('/^Q:\d+\)(.*)/i', $text, $matches)) {
        if ($currentQuestion && !empty($currentQuestion['question'])) {
            $questions[] = $currentQuestion;
        }
        $currentQuestion = [
            'question' => trim($matches[1]),
            'options' => [],
            'answer' => ''
        ];
    } elseif (preg_match('/^([A-Z]):\)(.*)/i', $text, $matches)) {
        if ($currentQuestion) {
            $letter = strtoupper($matches[1]);
            $currentQuestion['options'][$letter] = trim($matches[2]);
        }
    } elseif (preg_match('/^RIGHT:\s*([A-Z, ]+)/i', $text, $matches)) {
        if ($currentQuestion) {
            $currentQuestion['answer'] = strtoupper(str_replace(' ', '', trim($matches[1])));
        }
    } else {
        if ($currentQuestion) {
            if (empty($currentQuestion['options'])) {
                if (!empty($currentQuestion['question'])) {
                    $currentQuestion['question'] .= '<br>' . $text;
                } else {
                    $currentQuestion['question'] = $text;
                }
            } else {
                $lastOptionKey = array_key_last($currentQuestion['options']);
                if ($lastOptionKey) {
                    $currentQuestion['options'][$lastOptionKey] .= '<br>' . $text;
                }
            }
        }
    }
}
if ($currentQuestion && !empty($currentQuestion['question'])) {
    $questions[] = $currentQuestion;
}

print_r($questions);
