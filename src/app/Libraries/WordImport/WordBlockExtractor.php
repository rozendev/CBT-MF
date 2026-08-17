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

    private string $uploadDir;
    private string $uploadUrlPrefix;

    public function __construct(?string $uploadDir = null, string $uploadUrlPrefix = '/uploads/questions/')
    {
        $this->uploadDir = $uploadDir ?? (defined('FCPATH') ? FCPATH . 'uploads/questions/' : sys_get_temp_dir() . '/uploads/questions/');
        $this->uploadUrlPrefix = $uploadUrlPrefix;
    }

    /** @return array<int, array<string, mixed>> */
    public function extract(PhpWord $phpWord): array
    {
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
        if ($element instanceof Image) {
            $tag = $this->saveImageAndBuildTag($element);
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
        $depth = $isListItem ? $element->getDepth() : 0;

        if ($element instanceof ListItem) {
            // ListItem (beda dari ListItemRun) membungkus satu Text tunggal.
            $paragraphText = htmlspecialchars($element->getText(), ENT_QUOTES, 'UTF-8');
        } else {
            $paragraphText = '';
            foreach ($element->getElements() as $child) {
                if ($child instanceof TextBreak) {
                    $paragraphText .= "\n";
                } elseif ($child instanceof Image) {
                    $tag = $this->saveImageAndBuildTag($child);
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
            $blocks[] = $this->line($lineText, $isListItem && $isFirstLine, $isFirstLine ? $depth : 0);
            $isFirstLine = false;
        }
        return $blocks;
    }

    private function line(string $text, bool $isListItem, int $depth): array
    {
        return [
            'kind'         => 'line',
            'text'         => $text,
            'is_list_item' => $isListItem,
            'list_depth'   => $depth,
        ];
    }

    private function saveImageAndBuildTag(Image $image): ?string
    {
        $raw = $image->getImageStringData();
        if (!$raw) {
            return null;
        }
        $ext = strtolower($image->getImageExtension());
        if (!in_array($ext, self::ALLOWED_IMAGE_EXTS, true)) {
            return null;
        }
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }
        $filename = uniqid('img_') . '.' . $ext;
        @file_put_contents($this->uploadDir . $filename, $raw);
        $src = rtrim($this->uploadUrlPrefix, '/') . '/' . $filename;
        return '<img src="' . $src . '" style="max-width:100%; height:auto; margin:10px 0;" class="img-fluid rounded shadow-sm">';
    }
}
