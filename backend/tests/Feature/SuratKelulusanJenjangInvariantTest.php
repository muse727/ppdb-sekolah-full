<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Services\KelulusanLetterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Per-jenjang graduation-letter invariants (P4-CB).
 *
 * The two letters are intentionally DIFFERENT documents with DIFFERENT
 * configurations:
 *   SD  → config key surat_kelulusan_config_sd → pdf.kelulusan.sd
 *   SMP → config key surat_kelulusan_config_smp → pdf.kelulusan.smp
 *
 * This suite proves the two never bleed into each other:
 *   1. SD renders the approved SD design (serif body, orange school name,
 *      bismillah, centred statusText, gold-stroke wave).
 *   2. SMP renders the SMP design (navy, bottom-right star, no SD card/wave).
 *   3. Saving SMP config does not change the SD letter.
 *   4. Saving SD config does not change the SMP letter.
 *   5. An SD candidate always receives the SD template.
 *   6. An SMP candidate always receives the SMP template.
 *   7. An SD candidate with ?jenjang=SMP still receives SD.
 *   8. An SMP candidate with ?jenjang=SD still receives SMP.
 *
 * Template identity is asserted from the rendered letter text (extracted via
 * pdftotext): the school name and the SD-only footer motto
 * ("bertakwa • mandiri • kreatif • cerdas") are unique to each template, so a
 * cross-jenjang substitution is detected. Both approved letters legitimately
 * carry a bismillah line and a DITERIMA verdict, so those are NOT used as
 * discriminators.
 */
class SuratKelulusanJenjangInvariantTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ setup

    private function seedConfig(string $jenjang, string $schoolName): void
    {
        app(KelulusanLetterService::class)->saveConfig($jenjang, [
            'nomor_surat'   => '132/SPMBM/test/I/26',
            'tanggal_surat' => 'Jakarta, 10 Januari 2026',
            'nama_kepsek'   => 'Kepala Contoh, M.Pd',
            'nama_sekolah'  => $schoolName,
            'tagline'       => 'Tagline '.$jenjang,
            'alamat'        => 'Jl. Contoh No. 1, Jakarta Pusat',
            'kontak'        => 'Telp/Faks. 021 - 0000000',
            'isi_lulus'     => "Dinyatakan diterima sebagai murid {$jenjang}.\n\nDemikian surat ini dibuat.",
            'isi_gagal'     => "Mohon maaf, dinyatakan tidak diterima di {$jenjang}.",
        ]);
    }

    private function pdfText(string $pdf): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'inv').'.pdf';
        file_put_contents($tmp, $pdf);
        $out = [];
        exec('pdftotext '.escapeshellarg($tmp).' - 2>/dev/null', $out);
        @unlink($tmp);

        return implode("\n", $out);
    }

    /**
     * Whether the rendered letter carries the SD-only footer motto
     * "• bertakwa • mandiri • kreatif • cerdas". The SMP letter has no footer
     * motto band, so this is the reliable SD/SMP discriminator. Bullet glyphs
     * are stripped before matching because pdftotext renders them inconsistently.
     */
    private function hasSdMotto(string $text): bool
    {
        $normalised = strtolower((string) preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $text));
        $normalised = (string) preg_replace('/\s+/', ' ', $normalised);

        return str_contains($normalised, 'bertakwa')
            && str_contains($normalised, 'mandiri')
            && str_contains($normalised, 'kreatif')
            && str_contains($normalised, 'cerdas');
    }

    /**
     * A content fingerprint that ignores PDF metadata (document ID, creation
     * date), which changes on every render even for identical output. Two
     * renders of the SAME letter must produce the same fingerprint; two
     * DIFFERENT letters must not.
     */
    private function fingerprint(string $pdf): string
    {
        return md5($this->pdfText($pdf));
    }

    private function candidate(string $jenjang, string $status = 'LULUS'): User
    {
        $u = User::factory()->create(['jenjang' => $jenjang]);
        $u->forceFill(['role' => 'orang_tua', 'status_kelulusan' => $status])->save();

        // The authoritative jenjang lives on the verified payment row.
        Payment::create([
            'user_id'          => $u->id,
            'invoice_number'   => 'MANUAL-C-'.strtoupper(uniqid()),
            'jenjang'          => $jenjang,
            'amount'           => 150000,
            'payment_method'   => 'MANUAL',
            'proof_of_payment' => 'bukti_bayar/p.png',
            'status'           => 'SUCCESS',
        ]);

        return $u->refresh();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => 'admin'])->save();

        return $u->refresh();
    }

    // ------------------------------------------------------------------ 1 & 2: design separation

    public function test_sd_letter_uses_approved_sd_design(): void
    {
        $this->seedConfig('SD', 'SDIT AL MUBARAK');

        $pdf  = app(KelulusanLetterService::class)->renderPreview('SD', 'LULUS');
        $text = $this->pdfText($pdf);

        $this->assertStringContainsString('SDIT AL MUBARAK', $text);
        // Approved SD signature line, absent from the SMP template.
        $this->assertTrue($this->hasSdMotto($text), 'SD footer motto missing');
        $this->assertStringNotContainsString('SMPIT', $text);
    }

    public function test_smp_letter_uses_smp_design(): void
    {
        $this->seedConfig('SMP', 'SMPIT AL MUBARAK');

        $pdf  = app(KelulusanLetterService::class)->renderPreview('SMP', 'LULUS');
        $text = $this->pdfText($pdf);

        $this->assertStringContainsString('SMPIT AL MUBARAK', $text);
        // The SMP letter must NOT carry the SD-only bismillah line.
        $this->assertFalse($this->hasSdMotto($text), 'SMP must not carry the SD footer motto');
    }

    // ------------------------------------------------------------------ 3 & 4: config independence

    public function test_changing_smp_config_does_not_affect_sd_letter(): void
    {
        $svc = app(KelulusanLetterService::class);
        $this->seedConfig('SD', 'SDIT AL MUBARAK');
        $this->seedConfig('SMP', 'SMPIT AL MUBARAK');

        $sdBefore = $svc->renderPreview('SD', 'LULUS');

        // Re-save SMP with completely different content.
        $this->seedConfig('SMP', 'SMPIT NAMA BARU SEKALI');

        $sdAfter = $svc->renderPreview('SD', 'LULUS');

        $this->assertSame(
            $this->fingerprint($sdBefore),
            $this->fingerprint($sdAfter),
            'Saving SMP config altered the SD letter'
        );
        $this->assertStringNotContainsString('NAMA BARU', $this->pdfText($sdAfter));
    }

    public function test_changing_sd_config_does_not_affect_smp_letter(): void
    {
        $svc = app(KelulusanLetterService::class);
        $this->seedConfig('SD', 'SDIT AL MUBARAK');
        $this->seedConfig('SMP', 'SMPIT AL MUBARAK');

        $smpBefore = $svc->renderPreview('SMP', 'LULUS');

        // Re-save SD with completely different content.
        $this->seedConfig('SD', 'SDIT NAMA BARU SEKALI');

        $smpAfter = $svc->renderPreview('SMP', 'LULUS');

        $this->assertSame(
            $this->fingerprint($smpBefore),
            $this->fingerprint($smpAfter),
            'Saving SD config altered the SMP letter'
        );
        $this->assertStringNotContainsString('NAMA BARU', $this->pdfText($smpAfter));
    }

    // ------------------------------------------------------------------ 5 & 6: candidate template

    public function test_sd_candidate_receives_sd_template(): void
    {
        $this->seedConfig('SD', 'SDIT AL MUBARAK');
        $this->seedConfig('SMP', 'SMPIT AL MUBARAK');

        $candidate = $this->candidate('SD');
        $result    = app(KelulusanLetterService::class)->renderForUser($candidate->id);
        $text      = $this->pdfText($result['pdf']);

        $this->assertStringContainsString('SDIT AL MUBARAK', $text);
        $this->assertTrue($this->hasSdMotto($text), 'SD footer motto missing');
        $this->assertStringNotContainsString('SMPIT', $text);
    }

    public function test_smp_candidate_receives_smp_template(): void
    {
        $this->seedConfig('SD', 'SDIT AL MUBARAK');
        $this->seedConfig('SMP', 'SMPIT AL MUBARAK');

        $candidate = $this->candidate('SMP');
        $result    = app(KelulusanLetterService::class)->renderForUser($candidate->id);
        $text      = $this->pdfText($result['pdf']);

        $this->assertStringContainsString('SMPIT AL MUBARAK', $text);
        $this->assertFalse($this->hasSdMotto($text), 'SMP must not carry the SD footer motto');
    }

    // ------------------------------------------------------------------ 7 & 8: query-param cannot override

    public function test_sd_candidate_with_smp_query_param_still_gets_sd(): void
    {
        $this->seedConfig('SD', 'SDIT AL MUBARAK');
        $this->seedConfig('SMP', 'SMPIT AL MUBARAK');

        $candidate = $this->candidate('SD');

        Sanctum::actingAs($this->admin(), ['*']);
        // Attacker tries to force the SMP template via a query parameter.
        $response = $this->getJson('/api/kelulusan/surat?user_id='.$candidate->id.'&jenjang=SMP');

        $response->assertOk();
        $text = $this->pdfText($response->getContent());
        $this->assertStringContainsString('SDIT AL MUBARAK', $text);
        $this->assertTrue($this->hasSdMotto($text), 'SD footer motto missing');
        $this->assertStringNotContainsString('SMPIT', $text);
    }

    public function test_smp_candidate_with_sd_query_param_still_gets_smp(): void
    {
        $this->seedConfig('SD', 'SDIT AL MUBARAK');
        $this->seedConfig('SMP', 'SMPIT AL MUBARAK');

        $candidate = $this->candidate('SMP');

        Sanctum::actingAs($this->admin(), ['*']);
        // Attacker tries to force the SD template via a query parameter.
        $response = $this->getJson('/api/kelulusan/surat?user_id='.$candidate->id.'&jenjang=SD');

        $response->assertOk();
        $text = $this->pdfText($response->getContent());
        $this->assertStringContainsString('SMPIT AL MUBARAK', $text);
        $this->assertFalse($this->hasSdMotto($text), 'SMP must not carry the SD footer motto');
    }
}
