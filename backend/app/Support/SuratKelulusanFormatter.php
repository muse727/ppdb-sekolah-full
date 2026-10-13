<?php

namespace App\Support;

/**
 * Server-authoritative rich-text renderer for the graduation-letter bodies.
 *
 * Admins compose the "isi_lulus" / "isi_gagal" paragraphs in a plain-text
 * field where **double asterisks** mark bold spans. The PDF templates must
 * print that emphasis without ever trusting admin input as raw HTML, so this
 * class is the single choke point: EVERY segment is HTML-escaped first, and
 * only the whitelisted <strong>/<p> tags are emitted.
 *
 * Design rules:
 *  - No HTML passthrough. `<b>`, `<script>`, `javascript:` URLs etc. in the
 *    stored config survive only as visible escaped text — never as markup.
 *  - Output is fragment-free: a well-formed sequence of <p>…</p> blocks, so
 *    Dompdf can break long bodies across pages without corrupt flow.
 *  - An unmatched `**` does not leak asterisks into the letter; the rest of
 *    the line degrades to plain text.
 */
class SuratKelulusanFormatter
{
    /**
     * Render a stored paragraph block to safe HTML paragraphs.
     *
     * @param  string $text  Raw admin-composed text (may contain **bold**).
     * @return string        Escaped HTML: zero or more <p> blocks.
     */
    public static function toHtml(string $text): string
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", trim($text));

        if ($normalised === '') {
            return '';
        }

        $html = '';

        // Blank-line separated blocks become separate paragraphs; single
        // newlines inside a block become <br/> so numbered lists keep their
        // one-item-per-line visual structure.
        foreach (preg_split("/\n{2,}/", $normalised) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $lines = array_map(
                static fn (string $line): string => self::renderLine($line),
                explode("\n", $block)
            );

            $html .= '<p>' . implode('<br/>', $lines) . '</p>';
        }

        return $html;
    }

    /**
     * Escape one line and wrap **bold** spans in <strong>.
     */
    private static function renderLine(string $line): string
    {
        $escaped = e(trim($line));

        return (string) preg_replace(
            '/\*\*([^*\n]+)\*\*/',
            '<strong>$1</strong>',
            $escaped
        );
    }
}
