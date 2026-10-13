<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReportsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $status = 'BELUM', string $role = 'orang_tua', string $jenjang = 'SD'): User
    {
        $user = User::factory()->create(['jenjang' => $jenjang]);
        $user->forceFill(['role' => $role, 'status_kelulusan' => $status])->save();
        return $user;
    }

    private function admin(): void
    {
        Sanctum::actingAs($this->user('BELUM', 'admin'));
    }

    private function payment(User $user, string $status = 'PENDING_MANUAL', ?string $path = null): Payment
    {
        return Payment::create(['user_id' => $user->id, 'jenjang' => $user->jenjang, 'status' => $status,
            'amount' => 175000, 'invoice_number' => 'P5-'.uniqid(), 'payment_method' => 'MANUAL',
            'proof_of_payment' => $path]);
    }

    private function snapshot(): string
    {
        return json_encode(array_map(fn ($table) => DB::table($table)->get()->toArray(), ['users', 'payments', 'biodatas', 'ppdb_seat_allocations', 'tokens', 'whitelists', 'smp_email_tokens']));
    }

    public function test_finalized_results_filters_detail_and_export_are_read_only(): void
    {
        $passed = $this->user('LULUS');
        $failed = $this->user('TIDAK_LULUS', 'orang_tua', 'SMP');
        $unfinished = $this->user();
        $this->user('LULUS', 'guru');
        $this->admin();
        $before = $this->snapshot();
        $this->getJson('/api/admin/data-kelulusan')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/data-kelulusan?status=LULUS')->assertOk()->assertJsonPath('data.0.id', $passed->id)->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/data-kelulusan?status=TIDAK_LULUS&jenjang=SMP')->assertOk()->assertJsonPath('data.0.id', $failed->id);
        $this->getJson('/api/admin/data-kelulusan?search='.urlencode($passed->email))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/data-kelulusan/'.$passed->id)->assertOk()->assertJsonPath('status_kelulusan', 'LULUS');
        $this->getJson('/api/admin/data-kelulusan/'.$unfinished->id)->assertNotFound();
        $this->getJson('/api/admin/data-kelulusan?status=BELUM')->assertUnprocessable();
        $csv = $this->get('/api/admin/data-kelulusan/export?status=LULUS')->assertOk()->streamedContent();
        $this->assertStringContainsString($passed->email, $csv);
        $this->assertStringNotContainsString($failed->email, $csv);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_each_payment_state_and_no_row_are_distinct_and_latest_id_wins(): void
    {
        $unpaid = $this->user();
        $expected = ['NO_PAYMENT' => [$unpaid->id, 'Belum Bayar']];
        foreach (\App\Services\AdminReportService::PAYMENT_LABELS as $status => $label) {
            $user = $this->user();
            $this->payment($user, 'SUCCESS');
            $latest = $this->payment($user, $status);
            $expected[$status] = [$user->id, $label, $latest->id];
        }
        $this->admin();
        $before = $this->snapshot();
        $this->getJson('/api/admin/data-pembayaran')->assertOk()->assertJsonCount(7, 'data')->assertJsonPath('meta.total', 7);
        foreach ($expected as $status => $values) {
            $response = $this->getJson('/api/admin/data-pembayaran?status='.$status)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $values[0])->assertJsonPath('data.0.payment_label', $values[1]);
            if ($status === 'NO_PAYMENT') {
                $response->assertJsonPath('data.0.payment', null);
            } else {
                $response->assertJsonPath('data.0.payment.id', $values[2])->assertJsonPath('data.0.payment.status', $status);
            }
        }
        $this->getJson('/api/admin/data-pembayaran/'.$unpaid->id)->assertOk()->assertJsonPath('payment', null);
        $this->getJson('/api/admin/data-pembayaran?per_page=2&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2);
        $this->get('/api/admin/data-pembayaran/export')->assertOk()->streamedContent();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_jalur_and_name_filters_use_biodata_and_not_account_route(): void
    {
        $user = $this->user('LULUS');
        $user->forceFill(['jalur' => 'UMUM'])->save();
        $payload = [
            'user_id' => $user->id, 'jalur_pendaftaran' => 'INTERNAL_TK', 'asal_sekolah' => 'TK', 'nama_lengkap' => 'Nama Peserta P5',
            'jenis_kelamin' => 'L', 'nik_siswa' => '123', 'no_kk' => '123', 'tempat_lahir' => 'Jakarta', 'tanggal_lahir' => '2020-01-01',
            'anak_ke' => 1, 'status_peserta_didik' => 'Lengkap', 'nama_ibu' => 'Ibu', 'nik_ibu' => '123', 'pendidikan_ibu' => 'S1',
            'pekerjaan_ibu' => 'Guru', 'penghasilan_ibu' => '100', 'nama_ayah' => 'Ayah', 'nik_ayah' => '123', 'pendidikan_ayah' => 'S1',
            'pekerjaan_ayah' => 'Guru', 'penghasilan_ayah' => '100', 'alamat_lengkap' => 'Jalan', 'kota' => 'Jakarta',
            'kecamatan' => 'Cilandak', 'kelurahan' => 'Lebak Bulus', 'rt' => '1', 'rw' => '1', 'no_hp_ortu' => '08123',
        ];
        DB::table('biodatas')->insert($payload);
        $this->admin();
        foreach (['kelulusan', 'pembayaran'] as $kind) {
            $this->getJson('/api/admin/data-'.$kind.'?jalur_pendaftaran=INTERNAL_TK&search=Peserta')->assertOk()->assertJsonPath('data.0.id', $user->id);
            $this->getJson('/api/admin/data-'.$kind.'?jalur_pendaftaran=UMUM')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/admin/data-'.$kind.'?search=%25')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/api/admin/data-'.$kind.'?jalur_pendaftaran=INTERNAL')->assertUnprocessable();
            $this->getJson('/api/admin/data-'.$kind.'?per_page=101')->assertUnprocessable();
        }
    }

    public function test_all_report_routes_require_admin_including_detail_export_and_proof(): void
    {
        $user = $this->user('LULUS');
        $payment = $this->payment($user);
        $urls = ['/api/admin/data-kelulusan', '/api/admin/data-kelulusan/export', '/api/admin/data-kelulusan/'.$user->id,
            '/api/admin/data-pembayaran', '/api/admin/data-pembayaran/export', '/api/admin/data-pembayaran/'.$user->id,
            '/api/admin/data-pembayaran/'.$user->id.'/payments/'.$payment->id.'/proof'];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach (['orang_tua', 'guru'] as $role) {
            Sanctum::actingAs($this->user('BELUM', $role));
            foreach ($urls as $url) {
                $this->getJson($url)->assertForbidden();
            }
        }
        Sanctum::actingAs($this->user('BELUM', 'super_admin'));
        $this->getJson('/api/admin/data-pembayaran')->assertOk();
        $this->getJson('/api/admin/data-kelulusan')->assertOk();
    }

    public function test_proof_is_resource_bound_private_and_rejects_invalid_files(): void
    {
        Storage::fake('private');
        $user = $this->user();
        $other = $this->user();
        $file = \Illuminate\Http\UploadedFile::fake()->image('proof.png');
        Storage::disk('private')->put('bukti_bayar/p5.png', file_get_contents($file->getPathname()));
        $payment = $this->payment($user, 'PENDING_MANUAL', 'bukti_bayar/p5.png');
        $this->admin();
        $before = $this->snapshot();
        $url = '/api/admin/data-pembayaran/'.$user->id.'/payments/'.$payment->id.'/proof';
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/admin/data-pembayaran/'.$other->id.'/payments/'.$payment->id.'/proof')->assertNotFound();
        $this->getJson('/api/admin/data-pembayaran/'.$user->id.'/payments/999999/proof')->assertNotFound();
        $this->assertSame($before, $this->snapshot());
        foreach ([null, 'bukti_bayar/missing.png', '../secret.png', 'bukti_bayar/../secret.png', 'https://evil.test/proof.png', 'bukti_bayar/fake.png'] as $path) {
            Storage::disk('private')->put('bukti_bayar/fake.png', '<?php echo 1;');
            $payment->update(['proof_of_payment' => $path]);
            $this->getJson($url)->assertNotFound();
        }
    }

    public function test_exports_escape_csv_and_expose_no_credentials_or_paths(): void
    {
        $user = $this->user('LULUS');
        $user->update(['name' => '=SUM(1,2) "quoted"' . "\nnext"]);
        $payment = $this->payment($user, 'REJECTED', 'bukti_bayar/secret.png');
        $this->admin();
        foreach (['kelulusan', 'pembayaran'] as $kind) {
            $response = $this->get('/api/admin/data-'.$kind.'/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
            $csv = $response->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
            $stream = fopen('php://memory', 'r+'); fwrite($stream, substr($csv, 3)); rewind($stream);
            fgetcsv($stream, 0, ',', '"', '');
            $row = fgetcsv($stream, 0, ',', '"', '');
            $this->assertSame("'".$user->name, $row[0]); fclose($stream);
            foreach ([$user->password, 'proof_of_payment', 'bukti_bayar', 'registration_number', 'provider_token', 'verifier_id', 'remember_token'] as $secret) {
                $this->assertStringNotContainsString($secret, $csv);
            }
            $json = $this->getJson('/api/admin/data-'.$kind.'/'.$user->id)->assertOk()->getContent();
            $this->assertStringNotContainsString($payment->proof_of_payment, $json);
            $this->assertStringNotContainsString($user->password, $json);
        }
    }
}
