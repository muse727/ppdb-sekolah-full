<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SettingAuditLog;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $jenjang = 'SD'): User
    {
        $user = User::factory()->create(['jenjang' => $jenjang]);
        $user->forceFill(['role' => $role])->save();
        return $user->refresh();
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'biaya_sd' => '150000', 'biaya_smp' => '250000',
            'bank_name' => 'BSI', 'bank_owner' => 'Yayasan',
            'rekening_sd' => '001234567890', 'rekening_smp' => '009876543210',
        ], $extra);
    }

    public function test_only_super_admin_can_update_and_accounts_are_separate(): void
    {
        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson('/api/settings/biaya', $this->payload())->assertOk();
        $this->assertDatabaseHas('settings', ['key' => 'rekening_sd', 'value' => '001234567890']);
        $this->assertDatabaseHas('settings', ['key' => 'rekening_smp', 'value' => '009876543210']);

        $this->postJson('/api/settings/biaya', ['rekening_sd' => '000000111111'])->assertOk();
        $this->assertDatabaseHas('settings', ['key' => 'rekening_smp', 'value' => '009876543210']);
    }

    public function test_guest_cannot_write_or_read_payment_instructions(): void
    {
        $this->postJson('/api/settings/biaya', $this->payload())->assertUnauthorized();
        $this->getJson('/api/payment/instructions')->assertUnauthorized();
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_non_super_admin_are_denied(): void
    {
        foreach (['admin', 'guru', 'orang_tua'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->postJson('/api/settings/biaya', $this->payload())->assertForbidden();
        }
        $this->assertDatabaseCount('settings', 0);
        $this->assertDatabaseCount('setting_audit_logs', 0);
    }

    public function test_validation_allowlist_and_payment_mapping_ignore_client_jenjang(): void
    {
        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson('/api/settings/biaya', $this->payload())->assertOk();
        $this->postJson('/api/settings/biaya', ['nomor_rekening' => '111111111'])->assertStatus(422);
        $this->postJson('/api/settings/biaya', ['rekening_sd' => '12A'])->assertStatus(422);
        $this->postJson('/api/settings/biaya', ['rekening_sd' => '123'])->assertStatus(422);

        Sanctum::actingAs($this->user('orang_tua', 'SMP'));
        $this->getJson('/api/payment/instructions?jenjang=SD&rekening_sd=attacker')
            ->assertOk()->assertJsonPath('rekening', '009876543210')->assertJsonPath('amount', 250000);
    }

    public function test_trim_and_leading_zero_are_preserved(): void
    {
        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson('/api/settings/biaya', $this->payload(['rekening_sd' => ' 000001234567 ']))->assertOk();
        $this->assertDatabaseHas('settings', ['key' => 'rekening_sd', 'value' => '000001234567']);
    }

    public function test_instructions_return_only_selected_account_and_do_not_mutate(): void
    {
        foreach ($this->payload() as $key => $value) {
            Setting::create(['key' => $key, 'value' => $value]);
        }
        $before = Setting::orderBy('id')->get()->toArray();
        foreach (['SD' => '001234567890', 'SMP' => '009876543210'] as $jenjang => $account) {
            Sanctum::actingAs($this->user('orang_tua', $jenjang));
            $this->getJson('/api/payment/instructions?jenjang=' . ($jenjang === 'SD' ? 'SMP' : 'SD'))
                ->assertOk()->assertExactJson([
                    'jenjang' => $jenjang, 'rekening' => $account,
                    'amount' => $jenjang === 'SD' ? 150000 : 250000,
                    'bank_name' => 'BSI', 'bank_owner' => 'Yayasan',
                ]);
            $this->getJson('/api/settings/biaya')->assertForbidden();
        }
        $this->assertSame($before, Setting::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('setting_audit_logs', 0);
    }

    public function test_legacy_fallback_is_absence_only_and_read_only(): void
    {
        Setting::create(['key' => 'bank_account', 'value' => '001111111111']);
        Setting::create(['key' => 'rekening_sd', 'value' => '002222222222']);
        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/settings/biaya')->assertOk()
            ->assertJsonPath('rekening_sd', '002222222222')
            ->assertJsonPath('rekening_smp', '001111111111')->assertJsonMissingPath('bank_account');
        $this->assertDatabaseCount('settings', 2);
        Setting::create(['key' => 'rekening_smp', 'value' => '']);
        $this->getJson('/api/settings/biaya')->assertOk()->assertJsonPath('rekening_smp', '');
        $this->assertDatabaseCount('setting_audit_logs', 0);
    }

    public function test_audit_records_actor_and_masked_old_and_new_values(): void
    {
        Setting::create(['key' => 'rekening_sd', 'value' => '001234567890']);
        $actor = $this->user('super_admin');
        Sanctum::actingAs($actor);
        $this->postJson('/api/settings/biaya', ['rekening_sd' => '000000111111'])->assertOk();
        $this->assertDatabaseHas('setting_audit_logs', [
            'actor_id' => $actor->id, 'action' => 'UPDATE_PAYMENT_ACCOUNT',
            'setting_key' => 'rekening_sd', 'old_value' => '********7890',
            'new_value' => '********1111', 'result' => 'SUCCESS',
        ]);
        $this->postJson('/api/settings/biaya', ['rekening_sd' => 'invalid'])->assertStatus(422);
        $this->assertDatabaseCount('setting_audit_logs', 1);
        $this->assertNotNull(SettingAuditLog::first()->created_at);
    }

    public static function invalidPayloads(): array
    {
        return [
            'empty' => [['rekening_sd' => '']], 'null' => [['rekening_sd' => null]],
            'letters' => [['rekening_smp' => '123abc456']], 'short' => [['rekening_sd' => '12345']],
            'long' => [['rekening_smp' => str_repeat('1', 31)]], 'number' => [['rekening_sd' => 123456]],
            'array' => [['rekening_smp' => ['123456']]], 'whitespace' => [['rekening_sd' => '123 456']],
            'unicode' => [['rekening_sd' => '\u{FF11}\u{FF12}\u{FF13}\u{FF14}\u{FF15}\u{FF16}']],
            'legacy' => [['bank_account' => '111111']], 'legacy alias' => [['nomor_rekening' => '111111']],
            'arbitrary key' => [['ppdb_status' => 'BUKA']], 'forged role' => [['role' => 'super_admin']],
            'empty payload' => [[]],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_rejected_without_partial_writes(array $payload): void
    {
        Setting::create(['key' => 'rekening_sd', 'value' => '001234567890']);
        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson('/api/settings/biaya', $payload)->assertStatus(422);
        $this->assertDatabaseHas('settings', ['key' => 'rekening_sd', 'value' => '001234567890']);
        $this->assertDatabaseCount('settings', 1);
        $this->assertDatabaseCount('setting_audit_logs', 0);
    }

    public function test_update_is_throttled_by_authenticated_actor(): void
    {
        Sanctum::actingAs($this->user('super_admin'));
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/settings/biaya', ['rekening_sd' => '001234567890'])->assertOk();
        }
        $this->postJson('/api/settings/biaya', ['rekening_sd' => '111111111111'])->assertStatus(429);
        $this->assertDatabaseHas('settings', ['key' => 'rekening_sd', 'value' => '001234567890']);
    }
}
