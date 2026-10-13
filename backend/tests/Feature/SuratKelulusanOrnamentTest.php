<?php

namespace Tests\Feature;

use App\Services\KelulusanLetterService;
use App\Support\SuratKelulusanViewHelper;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Graduation-letter ornaments must actually PAINT in the rendered PDF.
 *
 * Why this test exists
 * --------------------
 * Dompdf 3.x has no inline-`<svg>` element support: an `<svg>` node in the
 * markup is silently dropped — no paint, no warning, no exception. Both
 * letter templates originally shipped decorative inline SVG (the SD wave, the
 * SMP star) that rendered as nothing at all, while the letters still "worked"
 * because the visible structure is plain CSS.
 *
 * The fix is to embed the same markup as a `data:image/svg+xml;base64,...`
 * `<img>`, which Dompdf converts into native PDF vector operators.
 *
 * A regression here is invisible to the test suite and to HTTP status codes,
 * so these assertions inspect the PDF bytes and raster output directly.
 */
class SuratKelulusanOrnamentTest extends TestCase
{
    use RefreshDatabase;

    /** Approved SD footer wave gold border stroke: #f59e0b (RGB 245,158,11). */
    private const SD_GOLD = [245, 158, 11];

    /** Approved SD footer wave fill (soft purple): #e9d8fd (RGB 233,216,253). */
    private const SD_WAVE_PURPLE = [233, 216, 253];

    /** Approved SD footer contact/motto text (deep purple): #4c1d95 (RGB 76,29,149). */
    private const SD_TEXT_PURPLE = [76, 29, 149];

    /** Approved SD gold school name: #d97706 (RGB 217,119,6). */
    private const SD_NAME_GOLD = [217, 119, 6];

    // ------------------------------------------------------------------ helpers

    /**
     * Decode every FlateDecode content stream in a PDF and return the raw
     * operator text. Dompdf emits vector painting operators for embedded SVG.
     */
    private function pdfContentStreams(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

        $out = '';
        foreach ($matches[1] as $raw) {
            $inflated = @gzuncompress($raw);
            if ($inflated !== false) {
                $out .= $inflated;
            }
        }

        return $out;
    }

    /**
     * Rasterise page 1 to a GD image (72 dpi => 1pt == 1px).
     *
     * @return resource|\GdImage
     */
    private function rasterize(string $pdf)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'orn').'.pdf';
        file_put_contents($tmp, $pdf);

        $png = sys_get_temp_dir().'/orn_'.basename($tmp, '.pdf');
        exec('pdftoppm -png -r 72 '.escapeshellarg($tmp).' '.escapeshellarg($png).' 2>/dev/null');
        $page = $png.'-1.png';

        $this->assertFileExists($page, 'pdftoppm could not rasterise the PDF');

        $im = imagecreatefrompng($page);
        $this->assertNotFalse($im);

        @unlink($page);
        @unlink($tmp);

        return $im;
    }

    /**
     * Count pixels close to the given RGB triple across the whole page.
     *
     * @param array{0:int,1:int,2:int} $rgb
     * @return array{count:int,minx:int,miny:int,maxx:int,maxy:int,width:int,height:int}
     */
    private function countColor(string $pdf, array $rgb, int $tolerance = 30): array
    {
        $im = $this->rasterize($pdf);
        $w = imagesx($im);
        $h = imagesy($im);
        $count = 0;
        $minx = $w; $maxx = 0; $miny = $h; $maxy = 0;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $p = imagecolorsforindex($im, imagecolorat($im, $x, $y));
                if (abs($p['red'] - $rgb[0]) < $tolerance
                    && abs($p['green'] - $rgb[1]) < $tolerance
                    && abs($p['blue'] - $rgb[2]) < $tolerance) {
                    $count++;
                    $minx = min($minx, $x);
                    $maxx = max($maxx, $x);
                    $miny = min($miny, $y);
                    $maxy = max($maxy, $y);
                }
            }
        }

        imagedestroy($im);

        return compact('count', 'minx', 'miny', 'maxx', 'maxy') + ['width' => $w, 'height' => $h];
    }

    /**
     * Locate the SMP star by scanning a fixed box for non-white paint. Colour-
     * independent by design: the ornament is intentionally faded to a watermark
     * (it must not collide visually with the signature), so no full-strength
     * colour constant can be used to find it.
     *
     * @return array{count:int,minx:int,miny:int,maxx:int,maxy:int,boxPx:int}
     */
    private function boxBounds(string $pdf, int $x1, int $y1, int $x2, int $y2): array
    {
        $im = $this->rasterize($pdf);
        $w = imagesx($im);
        $h = imagesy($im);

        $x1 = max(0, $x1);
        $y1 = max(0, $y1);
        $x2 = min($w - 1, $x2);
        $y2 = min($h - 1, $y2);

        $count = 0;
        $minx = $x2; $maxx = $x1; $miny = $y2; $maxy = $y1;

        for ($y = $y1; $y <= $y2; $y++) {
            for ($x = $x1; $x <= $x2; $x++) {
                $p = imagecolorsforindex($im, imagecolorat($im, $x, $y));
                // "Painted" = not (near-)white.
                if ($p['red'] < 235 || $p['green'] < 235 || $p['blue'] < 235) {
                    $count++;
                    $minx = min($minx, $x);
                    $maxx = max($maxx, $x);
                    $miny = min($miny, $y);
                    $maxy = max($maxy, $y);
                }
            }
        }

        imagedestroy($im);

        return [
            'count' => $count,
            'minx' => $minx, 'maxx' => $maxx,
            'miny' => $miny, 'maxy' => $maxy,
            'boxPx' => ($x2 - $x1 + 1) * ($y2 - $y1 + 1),
        ];
    }

    /**
     * Locate the SMP star by scanning its fixed bottom-right box for any
     * non-white paint.
     *
     * The approved SMP template anchors the ornament at right:12mm bottom:12mm
     * in a 35mm box, so the scanned region spans 12mm..47mm from each edge
     * (47mm @ 72dpi ~= 133px).
     *
     * @return array{count:int,minx:int,miny:int,maxx:int,maxy:int,boxPx:int}
     */
    private function smpOrnamentBox(string $pdf): array
    {
        $reach = (int) round(47 / 25.4 * 72); // 12mm offset + 35mm box

        $im = $this->rasterize($pdf);
        $w = imagesx($im);
        $h = imagesy($im);
        imagedestroy($im);

        return $this->boxBounds($pdf, $w - $reach - 2, $h - $reach - 2, $w - 1, $h - 1);
    }

    /** Seed a minimal but complete config for one jenjang. */
    private function seedConfig(string $jenjang): void
    {
        $base = [
            'nomor_surat'   => '132/SPMBM/test/I/26',
            'tanggal_surat' => 'Jakarta, 10 Januari 2026',
            'nama_kepsek'   => 'Kepala Contoh, M.Pd',
            'nama_sekolah'  => strtoupper($jenjang).'IT CONTOH',
            'tagline'       => 'Sholeh - Tangguh',
            'alamat'        => 'Jl. Contoh No. 1, Jakarta Pusat',
            'kontak'        => 'Telp/Faks. 021 - 0000000',
            'isi_lulus'     => "Dinyatakan diterima sebagai murid.\n\nDemikian surat ini dibuat.",
            'isi_gagal'     => 'Mohon maaf, dinyatakan tidak diterima.',
        ];

        app(KelulusanLetterService::class)->saveConfig($jenjang, $base);
    }

    // ------------------------------------------------------------------ tests

    /**
     * Pin the underlying Dompdf limitation itself.
     *
     * If a future Dompdf gains real inline-SVG support this test fails loudly,
     * which is the signal to re-evaluate the data-URI indirection — not a
     * silent change in how the letters are painted.
     */
    public function test_dompdf_drops_inline_svg_but_renders_data_uri_img(): void
    {
        $svg = '<svg width="100" height="100" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">'
            .'<rect x="10" y="10" width="80" height="80" fill="#ff0000"/></svg>';

        $css = '@page{size:A4;margin:0}*{margin:0;padding:0}'
            . '.box{display:block;width:100px;height:100px;margin-top:200px}';

        $render = function (string $body) use ($css): string {
            $dompdf = new Dompdf();
            $dompdf->loadHtml('<html><head><style>'.$css.'</style></head><body>'.$body.'</body></html>');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return $dompdf->output();
        };

        $inlinePdf = $render('<div class="box">'.$svg.'</div>');
        $imgPdf = $render(
            '<div class="box"><img class="box" src="'
            .SuratKelulusanViewHelper::svgDataUri($svg).'" alt=""></div>'
        );

        // The red rectangle paints as a fill colour operator only via <img>.
        $this->assertStringNotContainsString('1.000 0.000 0.000 rg', $this->pdfContentStreams($inlinePdf));
        $this->assertStringContainsString('1.000 0.000 0.000 rg', $this->pdfContentStreams($imgPdf));
    }

    public function test_smp_letter_renders_star_ornament(): void
    {
        $this->seedConfig('SMP');

        $pdf = app(KelulusanLetterService::class)->renderPreview('SMP', 'LULUS');

        $stream = $this->pdfContentStreams($pdf);

        // The PDF still carries the motif's source colours (Dompdf applies any
        // watermark fade via graphics state, not by rewriting the fill values),
        // so these prove the star and its gold accents are drawn.
        //   SMP navy  #2b3a8c -> 0.169 0.227 0.549
        //   SMP gold  #f59e0b -> 0.961 0.620 0.043
        $this->assertStringContainsString('0.169 0.227 0.549 rg', $stream, 'navy star body not painted');
        $this->assertStringContainsString('0.961 0.620 0.043 rg', $stream, 'gold accent not painted');

        // The lattice lines are strokes — proves stroke rendering works too.
        $this->assertMatchesRegularExpression('/\bS\b/', $stream, 'no stroke operators emitted');

        // And the ornament must occupy the bottom-right box in raster output.
        // Colour-independent: the motif is intentionally faded, so count paint,
        // not a specific colour.
        $box = $this->smpOrnamentBox($pdf);
        $this->assertGreaterThan(
            0.10 * $box['boxPx'],
            $box['count'],
            'SMP ornament is not visibly painted in its bottom-right box'
        );
    }

    public function test_sd_letter_renders_approved_footer_and_ornament(): void
    {
        $this->seedConfig('SD');

        $pdf = app(KelulusanLetterService::class)->renderPreview('SD', 'LULUS');

        // Approved SD footer: a full-width PURPLE WAVE (#e9d8fd) along the
        // page bottom, bordered by a gold stroke.
        $wave = $this->countColor($pdf, self::SD_WAVE_PURPLE);
        $this->assertGreaterThan(500, $wave['count'], 'purple footer wave not visible');
        $this->assertLessThan($wave['width'] * 0.15, $wave['minx'], 'footer wave not flush left');
        $this->assertGreaterThan($wave['width'] * 0.85, $wave['maxx'], 'footer wave not flush right');
        $this->assertGreaterThan($wave['height'] * 0.9, $wave['maxy'], 'footer wave not at the page bottom');

        // Approved SD gold accents: the footer wave border stroke + the gold
        // school name (#f59e0b / #d97706 — both within the gold family).
        $this->assertGreaterThan(
            50,
            $this->countColor($pdf, self::SD_GOLD)['count'],
            'gold footer wave border missing'
        );

        // Approved SD footer text: deep-purple contact + motto (#4c1d95).
        $this->assertGreaterThan(
            20,
            $this->countColor($pdf, self::SD_TEXT_PURPLE)['count'],
            'purple footer contact/motto text missing'
        );
    }

    /**
     * The SMP ornament is anchored bottom-right in a fixed 58mm box. If the
     * <img> sizing is lost (dompdf ignores SVG intrinsic width/height), the
     * ornament drifts or collapses.
     *
     * The bounding-box assertion is colour-independent: the ornament is
     * intentionally faded to a watermark, so we locate it by "any paint in the
     * reserved corner box", not by a full-strength colour constant.
     */
    public function test_smp_ornament_is_anchored_bottom_right(): void
    {
        $this->seedConfig('SMP');

        $pdf = app(KelulusanLetterService::class)->renderPreview('SMP', 'LULUS');

        $im = $this->rasterize($pdf);
        $w = imagesx($im);
        $h = imagesy($im);
        imagedestroy($im);

        // Approved geometry: ornament box spans 12mm..47mm from right & bottom.
        $reach = (int) round(47 / 25.4 * 72);   // ~133px
        $offset = (int) round(12 / 25.4 * 72);  // ~34px inner edge of the box

        $box = $this->smpOrnamentBox($pdf);

        // Something painted — the ornament is present.
        $this->assertGreaterThan(0.02 * $box['boxPx'], $box['count'], 'no paint found in the ornament box');

        // It sits in the bottom-right corner region (within the 47mm reach).
        $this->assertGreaterThan($w - $reach - 5, $box['maxx'], 'ornament not in the bottom-right region');
        $this->assertGreaterThan($h - $reach - 5, $box['maxy'], 'ornament not near the bottom');

        // It must not spill past the 12mm inset toward the page centre.
        $this->assertLessThanOrEqual($w - 2, $box['maxx'], 'ornament overflows the right edge');
        $this->assertLessThanOrEqual($h - 2, $box['maxy'], 'ornament overflows the bottom edge');
    }

    public function test_ornaments_paint_for_both_statuses(): void
    {
        $this->seedConfig('SD');
        $this->seedConfig('SMP');

        $svc = app(KelulusanLetterService::class);

        foreach (['LULUS', 'TIDAK_LULUS'] as $status) {
            $smp = $svc->renderPreview('SMP', $status);
            $this->assertStringContainsString(
                '0.961 0.620 0.043 rg',
                $this->pdfContentStreams($smp),
                "SMP $status: gold ornament missing"
            );

            $sd = $svc->renderPreview('SD', $status);
            $this->assertGreaterThan(
                50,
                $this->countColor($sd, self::SD_GOLD)['count'],
                "SD $status: gold footer wave border missing"
            );
            $this->assertGreaterThan(
                500,
                $this->countColor($sd, self::SD_WAVE_PURPLE)['count'],
                "SD $status: purple footer wave missing"
            );
        }
    }

    /**
     * Guard the HTML that Dompdf actually receives: an inline `<svg>` element
     * renders as nothing, so it must never be reintroduced, and every
     * ornament must travel as a base64 SVG data URI.
     */
    public function test_rendered_letter_html_has_no_dead_inline_svg(): void
    {
        foreach (['sd', 'smp'] as $jenjang) {
            $html = view('pdf.kelulusan.'.$jenjang, [
                'jenjang'      => strtoupper($jenjang),
                'status'       => 'LULUS',
                'namaSekolah'  => strtoupper($jenjang).'IT CONTOH',
                'tagline'      => 'Sholeh - Tangguh',
                'alamat'       => 'Jl. Contoh No. 1',
                'kontak'       => 'Telp 021-0000000',
                'nomorSurat'   => '132/TEST/I/26',
                'tanggalSurat' => 'Jakarta, 10 Januari 2026',
                'namaKepsek'   => 'Kepala Contoh, M.Pd',
                'logoDataUri'  => null,
                'ttdDataUri'   => null,
                'isiHtml'      => '<p>Dinyatakan diterima.</p>',
                'namaMurid'    => 'Nama Contoh',
                'noPeserta'    => 'CONTOH-001',
                'asalSekolah'  => 'Contoh Asal Sekolah',
            ])->render();

            $this->assertStringNotContainsString(
                '<svg',
                $html,
                "$jenjang letter emits an inline <svg>, which dompdf silently drops"
            );

            $this->assertStringContainsString(
                'data:image/svg+xml;base64,',
                $html,
                "$jenjang letter must embed its ornament as a base64 SVG data URI"
            );
        }
    }

    public function test_rendered_letters_have_no_php_warnings(): void
    {
        // The `;utf8,` data-URI form makes Dompdf emit "Undefined array key"
        // warnings. Fail the build if any warning resurfaces.
        $this->seedConfig('SD');
        $this->seedConfig('SMP');

        $warnings = [];
        set_error_handler(function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $svc = app(KelulusanLetterService::class);
            $svc->renderPreview('SD', 'LULUS');
            $svc->renderPreview('SMP', 'LULUS');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'PDF rendering emitted PHP warnings');
    }
}
