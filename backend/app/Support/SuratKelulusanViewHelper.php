<?php

namespace App\Support;

/**
 * Blade-side helpers for the graduation-letter templates.
 *
 * Kept in a dedicated class (not inline PHP in the templates) so the
 * rendering decisions are unit-testable and identical for both jenjang.
 */
class SuratKelulusanViewHelper
{
    /**
     * Split the configured `tanggal_surat` ("Jakarta, 10 Januari 2026") into
     * the place part and the date part. Only the date portion is rendered
     * bold, mirroring the reference letters.
     *
     * @return array{0: string, 1: string} [place, date]
     */
    public static function splitTanggalSurat(?string $tanggalSurat): array
    {
        $value = trim((string) $tanggalSurat);

        if ($value === '') {
            return ['', ''];
        }

        $pos = strrpos($value, ',');

        if ($pos === false) {
            // No comma: treat the whole value as the date part.
            return ['', $value];
        }

        return [
            rtrim(substr($value, 0, $pos)),
            trim(substr($value, $pos + 1)),
        ];
    }

    /**
     * Wrap an inline SVG as a base64 data URI so it can be used as an
     * `<img src="...">`.
     *
     * Dompdf 3.x does NOT render inline `<svg>` elements — its SVG support
     * only reaches the image pipeline, so a `<svg>` in the markup is silently
     * dropped (nothing paints, no error). Embedding the same markup as a
     * `data:image/svg+xml;base64,...` `<img>` does render, including strokes
     * and non-uniform viewBox scaling.
     *
     * Base64 (rather than `;utf8,`) is mandatory: Dompdf's data-URI parser
     * only understands `base64,` payloads and emits PHP warnings plus an
     * empty image for the utf8 form.
     *
     * @return string Safe to drop straight into an `src` attribute.
     */
    public static function svgDataUri(string $svg): string
    {
        $svg = trim($svg);

        // Add the XML namespace when absent. Dompdf renders the data URI
        // either way, but the result is a standalone document that may be
        // opened directly (browser, preview pane), where a namespace-less
        // root is treated as unknown markup.
        if (! str_contains($svg, 'xmlns')
            && preg_match('/^<svg\b/i', $svg) === 1) {
            $svg = preg_replace(
                '/^<svg\b/i',
                '<svg xmlns="http://www.w3.org/2000/svg"',
                $svg,
                1
            ) ?? $svg;
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
