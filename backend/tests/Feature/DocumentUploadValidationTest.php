<?php

namespace Tests\Feature;

use App\Models\Berkas;
use App\Models\User;
use App\Support\DocumentValidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E.4 — upload berkas with content-based validation (JPG/PNG/PDF).
 *
 * Covers the required matrix: valid formats, fake extensions and
 * MIME/content mismatch, corrupted/empty/oversized files, disallowed
 * formats, and the invariant that no upload path ever sets a verified
 * status (OCR is advisory client-side only and cannot reach the server).
 */
class DocumentUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    private function parent(): User
    {
        return User::create([
            'name'     => 'Orang Tua',
            'email'    => uniqid() . '@test.local',
            'password' => bcrypt('Password123'),
            'role'     => 'orang_tua',
            'jalur'    => 'UMUM',
        ]);
    }

    /** Minimal but structurally valid PDF (header, xref, trailer, %%EOF). */
    private function validPdf(): string
    {
        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 100] >>";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $num => $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF\n";
        return $pdf;
    }

    // ── Unit: DocumentValidation ────────────────────────────────────────────

    public function test_valid_jpeg_png_pdf_pass_content_check(): void
    {
        $jpeg = UploadedFile::fake()->image('a.jpg', 40, 30)->get();
        $this->assertNull(DocumentValidation::check($jpeg));

        $png = UploadedFile::fake()->image('b.png', 40, 30)->get();
        $this->assertNull(DocumentValidation::check($png));

        $this->assertNull(DocumentValidation::check($this->validPdf()));
    }

    public function test_empty_and_oversized_rejected(): void
    {
        $this->assertNotNull(DocumentValidation::check(''));
        $this->assertNotNull(DocumentValidation::check("\xFF\xD8\xFF"));
        $this->assertNotNull(DocumentValidation::check(str_repeat('A', 6000 * 1024)));
    }

    public function test_fake_extension_and_disallowed_format_rejected(): void
    {
        // GIF bytes with a .png name/label: magic sniff must reject it.
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7') ?? '';
        $this->assertNotNull(DocumentValidation::check($gif));

        // PHP script with .pdf extension.
        $this->assertNotNull(DocumentValidation::check("<?php echo 'x'; ?>"));
        // Text file labelled as image.
        $this->assertNotNull(DocumentValidation::check("hello world\n"));
    }

    public function test_truncated_png_and_jpeg_rejected(): void
    {
        $png = UploadedFile::fake()->image('c.png', 40, 30)->get();
        $this->assertNotNull(DocumentValidation::check(substr($png, 0, 40)));

        $jpeg = UploadedFile::fake()->image('d.jpg', 40, 30)->get();
        $this->assertNotNull(DocumentValidation::check(substr($jpeg, 0, 100)));
    }

    public function test_encrypted_and_many_page_pdf_rejected(): void
    {
        $pdf = str_replace('/Root 1 0 R', '/Root 1 0 R /Encrypt 4 0 R', $this->validPdf());
        $this->assertNotNull(DocumentValidation::check($pdf));

        $pdf = str_replace('/Count 1', '/Count 11', $this->validPdf());
        $this->assertNotNull(DocumentValidation::check($pdf));
    }

    // ── Feature: endpoint behaviour ────────────────────────────────────────

    public function test_upload_pdf_succeeds_and_waits_verification(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->parent());

        $pdf = $this->validPdf();
        $file = UploadedFile::fake()->createWithContent('kartu.pdf', $pdf)
            ->mimeType('application/pdf');

        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => $file,
        ], ['Accept' => 'application/json'])->assertOk();

        $berkas = Berkas::first();
        $this->assertNotNull($berkas);
        $this->assertEquals('LOLOS_TEKNIS', $berkas->status); // E.5: automatic technical acceptance, not TERVERIFIKASI
        $this->assertStringEndsWith('.pdf', $berkas->kartu_keluarga);
        Storage::disk('private')->assertExists($berkas->kartu_keluarga);
    }

    public function test_upload_fake_image_content_rejected_without_write(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->parent());

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7') ?? '';
        $file = UploadedFile::fake()->createWithContent('photo.png', $gif)
            ->mimeType('image/png');

        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => $file,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertNull(Berkas::first());
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }

    public function test_upload_oversized_rejected_with_friendly_422(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->parent());

        $big = UploadedFile::fake()->create('huge.jpg', 6000); // KB
        $response = $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => $big,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertStringContainsString('MB', (string) $response->json('message'));
    }

    public function test_upload_genuine_image_with_pdf_extension_still_stored_correctly(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->parent());

        // Real JPEG bytes, misleading .pdf filename: accepted as JPEG because
        // content decides, stored with a server random name.
        $jpeg = UploadedFile::fake()->image('x.jpg', 40, 30)->get();
        $file = UploadedFile::fake()->createWithContent('x.pdf', $jpeg)->mimeType('application/pdf');

        $this->post('/api/berkas/upload', [
            'jenis' => 'akte_kelahiran',
            'file'  => $file,
        ], ['Accept' => 'application/json'])->assertOk();

        $path = Berkas::firstOrFail()->akte_kelahiran;
        $this->assertMatchesRegularExpression('/\.(jpg|jpeg)$/', $path);
        Storage::disk('private')->assertExists($path);
    }

    public function test_serve_pdf_has_correct_content_type_and_nosniff(): void
    {
        Storage::fake('private');
        $user = $this->parent();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()
            ->createWithContent('kartu.pdf', $this->validPdf())
            ->mimeType('application/pdf');

        $this->post('/api/berkas/upload', [
            'jenis' => 'kartu_keluarga',
            'file'  => $file,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->get("/api/berkas/file/{$user->id}/kartu_keluarga", ['Accept' => 'application/json'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
