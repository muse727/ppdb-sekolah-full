<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for POST /api/payment/manual.
 *
 * Guards the hotfix for the HTTP 500 raised by dereferencing ->value on a
 * missing Setting row ("Attempt to read property value on null"), and locks
 * down the surrounding security boundary (auth, CSRF, validation, ownership).
 */
class ManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/payment/manual';

    private function user(string $name, string $role = 'orang_tua', string $jenjang = 'SD'): User
    {
        $user = User::factory()->create(['name' => $name, 'jenjang' => $jenjang]);

        // `role` is guarded on the model and may only be set server-side.
        $user->forceFill(['role' => $role])->save();

        return $user->refresh();
    }

    private function price(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Multipart POST that still receives JSON error payloads. */
    private function upload(array $fields, array $headers = [])
    {
        return $this->post(self::ENDPOINT, $fields, array_merge([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ], $headers));
    }

    private function validImage(string $name = 'bukti.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 64, 64);
    }

    // == ROOT-CAUSE REGRESSION ==

    public function test_upload_is_rejected_with_clear_422_when_price_is_not_configured(): void
    {
        // Exact local-dev state that produced the HTTP 500: no biaya_* rows.
        $this->assertSame(0, Setting::count());
        Storage::fake('private');

        Sanctum::actingAs($this->user('Parent A'));

        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => $this->validImage(),
        ])->assertStatus(422)->assertJson(['message' => 'Biaya pendaftaran belum dikonfigurasi.']);

        // Nothing is persisted and no file is stored.
        $this->assertDatabaseCount('payments', 0);
        Storage::disk('private')->assertMissing('bukti_bayar');
    }

    public function test_upload_uses_configured_price_when_setting_exists(): void
    {
        $this->price('biaya_smp', '250000');
        Storage::fake('private');

        Sanctum::actingAs($this->user('Parent B', 'orang_tua', 'SMP'));

        $this->upload([
            'jenjang' => 'SMP',
            'bukti_bayar' => $this->validImage(),
        ])->assertOk();

        $payment = Payment::firstOrFail();
        $this->assertSame(250000, (int) $payment->amount);
        $this->assertSame('SMP', $payment->jenjang);
        $this->assertSame('PENDING_MANUAL', $payment->status);
        $this->assertSame('MANUAL', $payment->payment_method);
    }

    public function test_client_amount_is_ignored_in_favour_of_trusted_setting(): void
    {
        $this->price('biaya_sd', '150000');
        Storage::fake('private');

        Sanctum::actingAs($this->user('Parent Amount'));

        // The client tries to dictate a far lower amount; the server must bill
        // from the authoritative Setting, never the request body.
        $this->upload([
            'jenjang' => 'SD',
            'amount' => 1,
            'total' => 0,
            'biaya' => 100,
            'bukti_bayar' => $this->validImage(),
        ])->assertOk();

        $this->assertSame(150000, (int) Payment::firstOrFail()->amount);
    }

    public function test_missing_price_setting_for_account_jenjang_is_rejected(): void
    {
        // Only the sibling jenjang is configured; the account's own is absent.
        $this->price('biaya_sd', '150000');
        Storage::fake('private');

        Sanctum::actingAs($this->user('Parent C', 'orang_tua', 'SMP'));

        $this->upload([
            'jenjang' => 'SMP',
            'bukti_bayar' => $this->validImage(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    // == AUTHENTICATION BOUNDARY ==

    public function test_unauthenticated_upload_is_rejected_by_middleware(): void
    {
        Storage::fake('private');

        // Real route invocation with no session cookie and no bearer token.
        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => $this->validImage(),
        ])->assertUnauthorized();

        $this->assertDatabaseCount('payments', 0);
        Storage::disk('private')->assertMissing('bukti_bayar');
    }

    public function test_route_remains_behind_sanctum_middleware(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(function (Route $r) {
                return $r->uri() === 'api/payment/manual'
                    && in_array('POST', $r->methods(), true);
            });

        $this->assertNotNull($route, 'POST /api/payment/manual route must exist.');
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
    }

    public function test_forged_bearer_token_is_rejected(): void
    {
        Storage::fake('private');

        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => $this->validImage(),
        ], ['Authorization' => 'Bearer 1|'.str_repeat('a', 40)])
            ->assertUnauthorized();

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * NOTE: Illuminate\Foundation\Http\Middleware\PreventRequestForgery
     * short-circuits via runningUnitTests(), so CSRF rejection (419) can
     * never be produced from the PHPUnit client. The protection is therefore
     * asserted structurally here; the 419 rejection was additionally verified
     * over real HTTP against the running dev server (tokenless session POST
     * to /api/payment/manual -> HTTP 419 "CSRF token mismatch.").
     */
    public function test_csrf_protection_is_not_disabled(): void
    {
        $groups = app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups();

        // statefulApi() keeps Sanctum's stateful wrapper on the api group.
        // It applies the web stack (including CSRF) to first-party SPA
        // requests such as the browser's multipart upload.
        $this->assertContains(
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            $groups['api'] ?? [],
            'API routes must stay stateful so browser sessions are CSRF-protected.'
        );

        // The inherited web stack still validates the CSRF token.
        $this->assertContains(
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            $groups['web'] ?? [],
            'CSRF token validation must remain registered.'
        );

        // No route is carved out of CSRF validation.
        $except = (new \ReflectionClass(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class))
            ->getDefaultProperties()['except'];
        $this->assertSame([], $except, 'No route may be exempted from CSRF validation.');

        // And no application-level subclass widens those exemptions.
        $this->assertFileDoesNotExist(app_path('Http/Middleware/VerifyCsrfToken.php'));
    }

    // == VALIDATION ==

    public function test_jenjang_is_server_derived_and_a_client_forgery_is_ignored(): void
    {
        Storage::fake('private');
        $this->price('biaya_sd', '150000');
        $this->price('biaya_smp', '250000');

        // Account is SD but the request body claims SMP — the server must
        // trust the account, not the client.
        Sanctum::actingAs($this->user('Parent D', 'orang_tua', 'SD'));

        $this->upload([
            'jenjang' => 'SMP',
            'bukti_bayar' => $this->validImage(),
        ])->assertOk();

        $payment = Payment::firstOrFail();
        $this->assertSame('SD', $payment->jenjang);
        $this->assertSame(150000, (int) $payment->amount);
    }

    public function test_upload_is_rejected_when_account_jenjang_is_not_set(): void
    {
        Storage::fake('private');

        $parent = $this->user('Parent D2');
        $parent->forceFill(['jenjang' => null])->save();
        Sanctum::actingAs($parent->refresh());

        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => $this->validImage(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_evidence_file_is_required(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->user('Parent E'));

        $this->upload(['jenjang' => 'SD'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bukti_bayar');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_non_image_mime_type_is_rejected(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->user('Parent F'));

        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => UploadedFile::fake()->create('bukti.txt', 10, 'text/plain'),
        ])->assertStatus(422)->assertJsonValidationErrors('bukti_bayar');

        $this->assertDatabaseCount('payments', 0);
        Storage::disk('private')->assertMissing('bukti_bayar');
    }

    public function test_php_payload_renamed_to_png_is_rejected(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->user('Parent G'));

        $tmp = tempnam(sys_get_temp_dir(), 'probe');
        file_put_contents($tmp, "<?php echo 'pwned'; ?>");

        // Test-mode UploadedFile skips the is_uploaded_file check but still
        // goes through real MIME sniffing.
        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => new UploadedFile($tmp, 'shell.png', 'image/png', null, true),
        ])->assertStatus(422)->assertJsonValidationErrors('bukti_bayar');

        @unlink($tmp);
        $this->assertDatabaseCount('payments', 0);
        Storage::disk('private')->assertMissing('bukti_bayar');
    }

    public function test_oversized_image_is_rejected_with_controlled_validation_response(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->user('Parent H'));

        // Limit is max:2048 (KB); 3000 KB exceeds it.
        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => UploadedFile::fake()->image('besar.png', 200, 200)->size(3000),
        ])->assertStatus(422)->assertJsonValidationErrors('bukti_bayar');

        $this->assertDatabaseCount('payments', 0);
        Storage::disk('private')->assertMissing('bukti_bayar');
    }

    public function test_image_within_size_limit_is_accepted(): void
    {
        Storage::fake('private');
        $this->price('biaya_sd', '150000');
        Sanctum::actingAs($this->user('Parent I'));

        // 1024 KB is inside the 2048 KB ceiling.
        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => UploadedFile::fake()->image('sedang.jpg', 200, 200)->size(1024),
        ])->assertOk();

        $this->assertDatabaseCount('payments', 1);
    }

    // == OWNERSHIP / SERVER-DERIVED IDENTITY ==

    public function test_payment_belongs_to_authenticated_user_and_ignores_client_identity_fields(): void
    {
        Storage::fake('private');
        $this->price('biaya_sd', '150000');

        $owner = $this->user('Real Owner');
        $victim = $this->user('Victim');
        Sanctum::actingAs($owner);

        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => $this->validImage(),
            // Attempts to redirect ownership or forge the record.
            'user_id' => $victim->id,
            'id' => 9999,
            'payment_id' => 9999,
            'status' => 'SUCCESS',
            'amount' => 1,
            'invoice_number' => 'MANUAL-FORGED-0001',
            'proof_of_payment' => 'bukti_bayar/forged.png',
        ])->assertOk();

        $payment = Payment::firstOrFail();
        $this->assertSame($owner->id, $payment->user_id);
        $this->assertSame('PENDING_MANUAL', $payment->status, 'Client must not be able to self-verify.');
        $this->assertNotSame('MANUAL-FORGED-0001', $payment->invoice_number);
        $this->assertNotSame('bukti_bayar/forged.png', $payment->proof_of_payment);
        $this->assertStringStartsWith('MANUAL-'.date('Ymd').'-', $payment->invoice_number);
        $this->assertStringStartsWith('bukti_bayar/', $payment->proof_of_payment);
        $this->assertSame('MANUAL', $payment->payment_method);
    }

    public function test_stored_path_is_server_generated_and_not_client_controlled(): void
    {
        Storage::fake('private');
        $this->price('biaya_sd', '150000');
        Sanctum::actingAs($this->user('Parent J'));

        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => $this->validImage('../../evil.png'),
            'path' => '../../../public/evil.php',
        ])->assertOk();

        $stored = Payment::firstOrFail()->proof_of_payment;

        $this->assertStringStartsWith('bukti_bayar/', $stored);
        $this->assertStringNotContainsString('..', $stored);
        $this->assertStringNotContainsString('evil', $stored);
        $this->assertTrue(Storage::disk('private')->exists($stored));
        Storage::disk('private')->assertMissing('evil.png');
    }

    public function test_one_parent_cannot_overwrite_another_parents_evidence(): void
    {
        Storage::fake('private');
        $this->price('biaya_sd', '150000');

        $first = $this->user('Parent First');
        $second = $this->user('Parent Second');

        Sanctum::actingAs($first);
        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => $this->validImage('first.png'),
        ])->assertOk();

        $firstPayment = Payment::firstOrFail();
        $firstPath = $firstPayment->proof_of_payment;

        // Second parent posts, also trying to target the first parent's record.
        Sanctum::actingAs($second);
        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => $this->validImage('second.png'),
            'payment_id' => $firstPayment->id,
            'id' => $firstPayment->id,
            'user_id' => $first->id,
        ])->assertOk();

        // The first parent's record and file are untouched.
        $this->assertSame($firstPath, $firstPayment->fresh()->proof_of_payment);
        $this->assertSame($first->id, $firstPayment->fresh()->user_id);
        $this->assertTrue(Storage::disk('private')->exists($firstPath));

        // The second parent received their own separate record.
        $this->assertDatabaseCount('payments', 2);
        $secondPayment = Payment::where('user_id', $second->id)->firstOrFail();
        $this->assertNotSame($firstPath, $secondPayment->proof_of_payment);
    }

    // == PERSISTENCE / RESPONSE / REFRESH ==

    public function test_successful_upload_persists_record_and_file_and_survives_status_refetch(): void
    {
        Storage::fake('private');
        $this->price('biaya_sd', '175000');

        $parent = $this->user('Parent K');
        Sanctum::actingAs($parent);

        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => $this->validImage(),
        ])->assertOk()->assertExactJson([
            'success' => true,
            'message' => 'Bukti pembayaran berhasil diunggah.',
        ]);

        $payment = Payment::firstOrFail();
        $this->assertTrue(Storage::disk('private')->exists($payment->proof_of_payment));
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'user_id' => $parent->id,
            'jenjang' => 'SD',
            'amount' => 175000,
            'payment_method' => 'MANUAL',
            'status' => 'PENDING_MANUAL',
        ]);

        // Page refresh: the status endpoint still reports the uploaded evidence.
        $status = $this->getJson('/api/payment/status')->assertOk()->json();
        $this->assertTrue($status['has_payment']);
        $this->assertSame('PENDING_MANUAL', $status['payment']['status']);
        $this->assertSame($payment->proof_of_payment, $status['payment']['proof_of_payment']);
    }

    public function test_second_upload_while_pending_is_rejected_to_prevent_evidence_replacement(): void
    {
        Storage::fake('private');
        $this->price('biaya_sd', '150000');
        $parent = $this->user('Parent L');
        Sanctum::actingAs($parent);

        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => $this->validImage('one.png'),
        ])->assertOk();
        $first = Payment::orderBy('id')->firstOrFail();

        // A second submission while the first awaits verification is refused.
        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => $this->validImage('two.png'),
        ])->assertStatus(409);

        $this->assertDatabaseCount('payments', 1);

        // The original evidence is preserved rather than overwritten.
        $this->assertSame($first->proof_of_payment, $first->fresh()->proof_of_payment);
        $this->assertTrue(Storage::disk('private')->exists($first->proof_of_payment));
    }

    public function test_validation_failure_stores_nothing(): void
    {
        Storage::fake('private');
        Sanctum::actingAs($this->user('Parent M'));

        $this->upload([
            'jenjang' => 'SD',
            'bukti_bayar' => UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf'),
        ])->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
        Storage::disk('private')->assertMissing('bukti_bayar');
    }

    // == EVIDENCE ACCESS CONTROL ==

    private function manualPaymentRecord(User $owner): Payment
    {
        Storage::disk('private')->put('bukti_bayar/secret.png', 'secret-bytes');

        return Payment::create([
            'user_id' => $owner->id,
            'invoice_number' => 'MANUAL-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(4)),
            'jenjang' => 'SD',
            'amount' => 150000,
            'payment_method' => 'MANUAL',
            'proof_of_payment' => 'bukti_bayar/secret.png',
            'status' => 'PENDING_MANUAL',
        ]);
    }

    public function test_evidence_is_served_to_its_owner(): void
    {
        Storage::fake('private');
        $owner = $this->user('Owner');
        $payment = $this->manualPaymentRecord($owner);

        Sanctum::actingAs($owner);
        $this->get("/api/payments/{$payment->id}/evidence")->assertOk();
    }

    public function test_evidence_is_served_to_an_admin(): void
    {
        Storage::fake('private');
        $owner = $this->user('Owner');
        $payment = $this->manualPaymentRecord($owner);

        Sanctum::actingAs($this->user('Admin', 'admin'));
        $this->get("/api/payments/{$payment->id}/evidence")->assertOk();
    }

    public function test_evidence_is_denied_to_another_parent(): void
    {
        Storage::fake('private');
        $owner = $this->user('Owner');
        $stranger = $this->user('Stranger');
        $payment = $this->manualPaymentRecord($owner);

        Sanctum::actingAs($stranger);
        $this->get("/api/payments/{$payment->id}/evidence")->assertForbidden();
    }

    public function test_evidence_requires_authentication(): void
    {
        Storage::fake('private');
        $owner = $this->user('Owner');
        $payment = $this->manualPaymentRecord($owner);

        // Without credentials the file must never be served (auth middleware
        // intercepts the request; a JSON client receives 401).
        $this->getJson("/api/payments/{$payment->id}/evidence")->assertUnauthorized();
    }

    public function test_no_public_storage_route_serves_uploaded_files(): void
    {
        // The legacy public URLs (/storage/...) must not resolve to any route,
        // and the public disk must not be registered as a served disk.
        $uris = collect(app('router')->getRoutes())
            ->map(fn (Route $r) => $r->uri())
            ->all();

        $this->assertEmpty(
            array_filter($uris, fn (string $u) => str_starts_with($u, 'storage/')),
            'No route may serve files from public storage.'
        );

        // The public disk must not be web-servable for uploads.
        $this->assertFalse(config('filesystems.disks.public.serve', false));
        $this->assertFalse(config('filesystems.disks.private.serve', false));
        $this->assertEmpty(config('filesystems.links', []), 'No public storage symlink may be linked.');
    }
}
