<?php

namespace Modules\Channel\Support;

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

class DescriptionFormatter
{
    public static function toHtml(string $markdown, ?int $maxLength = null): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        // Cap panjang input (guard) supaya output HTML tetap wajar & tidak
        // memotong tag di tengah. Cap kanal berlaku pada string input Markdown.
        if ($maxLength !== null && mb_strlen($markdown) > $maxLength) {
            $markdown = mb_substr($markdown, 0, $maxLength);
        }

        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return trim($converter->convert($markdown)->getContent());
    }

    public static function toPlainText(string $markdown, ?int $maxLength = null): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        $html = self::toHtml($markdown);

        $text = str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>'], "\n", $html);
        $text = str_replace(['</tr>', '</table>'], "\n", $text);
        $text = str_replace(['<li>', '<li '], ['• ', '• '], $text);

        $text = strip_tags($text);

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        $text = trim($text);

        if ($maxLength !== null && mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, $maxLength));
        }

        return $text;
    }
}
