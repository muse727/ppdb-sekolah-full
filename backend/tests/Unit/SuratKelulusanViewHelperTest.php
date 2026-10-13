<?php

namespace Tests\Unit;

use App\Support\SuratKelulusanViewHelper;
use PHPUnit\Framework\TestCase;

/**
 * Blade-side helpers for the graduation-letter templates.
 *
 * Pure functions — no framework boot needed, so this stays a true unit test.
 */
class SuratKelulusanViewHelperTest extends TestCase
{
    public function test_split_tanggal_surat_separates_place_and_date(): void
    {
        [$place, $date] = SuratKelulusanViewHelper::splitTanggalSurat('Jakarta, 10 Januari 2026');

        $this->assertSame('Jakarta', $place);
        $this->assertSame('10 Januari 2026', $date);
    }

    public function test_split_tanggal_surat_uses_last_comma(): void
    {
        // Multiple commas: only the final one separates place from date.
        [$place, $date] = SuratKelulusanViewHelper::splitTanggalSurat('Jakarta Pusat, DKI, 10 Januari 2026');

        $this->assertSame('Jakarta Pusat, DKI', $place);
        $this->assertSame('10 Januari 2026', $date);
    }

    public function test_split_tanggal_surat_without_comma_returns_date_only(): void
    {
        [$place, $date] = SuratKelulusanViewHelper::splitTanggalSurat('10 Januari 2026');

        $this->assertSame('', $place);
        $this->assertSame('10 Januari 2026', $date);
    }

    public function test_split_tanggal_surat_handles_null_and_blank(): void
    {
        $this->assertSame(['', ''], SuratKelulusanViewHelper::splitTanggalSurat(null));
        $this->assertSame(['', ''], SuratKelulusanViewHelper::splitTanggalSurat('   '));
    }

    public function test_svg_data_uri_uses_base64_form(): void
    {
        // Dompdf's data-URI parser only understands `base64,`; the `;utf8,`
        // form yields PHP warnings and an empty image.
        $uri = SuratKelulusanViewHelper::svgDataUri('<svg><rect width="10" height="10"/></svg>');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $this->assertStringNotContainsString(';utf8,', $uri);
    }

    public function test_svg_data_uri_round_trips_to_original_markup(): void
    {
        $svg = '<svg width="220" height="220" viewBox="0 0 220 220" xmlns="http://www.w3.org/2000/svg">'
            . '<polygon fill="#16305e" points="110,20 126,82"/></svg>';

        $uri = SuratKelulusanViewHelper::svgDataUri($svg);
        $payload = substr($uri, strlen('data:image/svg+xml;base64,'));

        $this->assertSame($svg, base64_decode($payload, true));
    }

    public function test_svg_data_uri_injects_namespace_when_absent(): void
    {
        $uri = SuratKelulusanViewHelper::svgDataUri('<svg viewBox="0 0 10 10"><rect width="4" height="4"/></svg>');
        $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);

        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $decoded);
        // Injected once, on the root element only.
        $this->assertSame(1, substr_count($decoded, 'xmlns'));
    }

    public function test_svg_data_uri_keeps_existing_namespace_untouched(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="4" height="4"/></svg>';
        $uri = SuratKelulusanViewHelper::svgDataUri($svg);
        $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);

        $this->assertSame($svg, $decoded);
        $this->assertSame(1, substr_count($decoded, 'xmlns'));
    }

    public function test_svg_data_uri_trims_surrounding_whitespace(): void
    {
        // Blade heredocs capture leading/trailing newlines; they must not leak
        // into the encoded document.
        $uri = SuratKelulusanViewHelper::svgDataUri("\n  <svg xmlns=\"http://www.w3.org/2000/svg\"></svg>  \n");
        $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);

        $this->assertSame('<svg xmlns="http://www.w3.org/2000/svg"></svg>', $decoded);
    }

    public function test_svg_data_uri_preserves_multiline_markup(): void
    {
        // The real templates embed multi-line markup with comments and
        // strokes; encoding must be lossless.
        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\">\n"
            . "    <!-- wave -->\n"
            . "    <path d=\"M0,60 L1440,60\" fill=\"none\" stroke=\"#f59e0b\" stroke-width=\"8\"/>\n"
            . "</svg>";

        $uri = SuratKelulusanViewHelper::svgDataUri($svg);
        $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);

        $this->assertSame($svg, $decoded);
    }

    public function test_svg_data_uri_does_not_mangle_non_svg_input(): void
    {
        // Defensive: a value that is not an SVG document must still encode
        // safely rather than producing a corrupt prefix.
        $uri = SuratKelulusanViewHelper::svgDataUri('not-svg');
        $decoded = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);

        $this->assertSame('not-svg', $decoded);
    }
}
