<?php

namespace Tests\Feature;

use App\Models\PpdbSeatAllocation;
use App\Models\Setting;
use App\Models\User;
use App\Services\PpdbCapacityService;
use App\Services\SmpEmailTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class InitialRegistrationAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('register|127.0.0.1');
        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
        app(PpdbCapacityService::class)->increaseMaxCapacity('SD', 1);
        app(PpdbCapacityService::class)->increaseMaxCapacity('SMP', 1);
    }

    private function credential(string $jalur, string $suffix): array
    {
        $payload = ['name' => 'Parent', 'email' => $suffix.'@b1.test', 'password' => 'Secret123',
            'jalur' => $jalur, 'kredensial' => 'AM-'.$suffix, 'jenjang' => 'SMP', 'role' => 'admin'];
        if ($jalur === 'INTERNAL') {
            $payload['nomor_internal'] = $suffix === 'first' ? '9876543210' : '9876543211';
            DB::table('whitelists')->insert(['registration_number' => $payload['nomor_internal'],
                'nama_siswa' => 'Candidate', 'jenjang' => 'SD', 'asal_sekolah' => 'TK', 'is_used' => false]);
        } elseif ($jalur === 'SMP_EMAIL') {
            $svc = app(SmpEmailTokenService::class);
            DB::table('smp_email_tokens')->insert(['email' => $payload['email'], 'nama' => 'Bound Candidate',
                'jenjang' => 'SMP', 'token_digest' => $svc->digest($svc->normalize($payload['kredensial'])),
                'issued_at' => now(), 'expires_at' => now()->addDay()]);
        } else {
            DB::table('tokens')->insert(['code' => strtoupper($payload['kredensial']), 'is_used' => false]);
        }
        return $payload;
    }

    public function test_all_paths_allocate_once_without_payment_and_reject_replay_and_full_capacity(): void
    {
        foreach (['UMUM', 'INTERNAL', 'SMP_EMAIL'] as $jalur) {
            // Separate capacity budget for each path, while retaining previous seats.
            $jenjang = $jalur === 'SMP_EMAIL' ? 'SMP' : 'SD';
            $svc = app(PpdbCapacityService::class);
            $svc->increaseMaxCapacity($jenjang, $svc->activeCount($jenjang) + 1);
            $first = $this->credential($jalur, 'first');
            $first['email'] = strtolower($jalur).'-'.$first['email'];
            if ($jalur === 'SMP_EMAIL') {
                DB::table('smp_email_tokens')->where('email', 'first@b1.test')->update(['email' => $first['email']]);
            }
            $response = $this->postJson('/api/register', $first)->assertOk();
            $id = $response->json('user.id');
            $this->assertDatabaseHas('ppdb_seat_allocations', ['user_id' => $id, 'payment_id' => null,
                'jenjang' => $jenjang, 'status' => 'ALLOCATED']);
            $this->assertSame(1, PpdbSeatAllocation::where('user_id', $id)->count());
            $this->assertDatabaseHas('users', ['id' => $id, 'role' => 'orang_tua', 'jenjang' => $jenjang]);
            $this->assertSame(0, DB::table('payments')->count());
            $this->assertSame(0, $svc->getCapacity($jenjang)['sisa']);
            $users = User::count();
            $allocations = PpdbSeatAllocation::count();
            $replay = $first;
            if ($jalur !== 'SMP_EMAIL') {
                $replay['email'] = 'replay-'.strtolower($jalur).'@b1.test';
            }
            $this->postJson('/api/register', $replay)->assertStatus(422);
            $this->assertSame($users, User::count());
            $this->assertSame($allocations, PpdbSeatAllocation::count());
            $loser = $this->credential($jalur, 'second');
            $loser['email'] = strtolower($jalur).'-'.$loser['email'];
            if ($jalur === 'SMP_EMAIL') {
                DB::table('smp_email_tokens')->where('email', 'second@b1.test')->update(['email' => $loser['email']]);
            }
            $this->postJson('/api/register', $loser)->assertStatus(409);
            $this->assertDatabaseMissing('users', ['email' => $loser['email']]);
            $this->assertSame($allocations, PpdbSeatAllocation::count());
            if ($jalur === 'SMP_EMAIL') {
                $this->assertDatabaseHas('smp_email_tokens', ['email' => $loser['email'], 'consumed_at' => null, 'used_by_user_id' => null]);
            } else {
                $table = $jalur === 'INTERNAL' ? 'whitelists' : 'tokens';
                $key = $jalur === 'INTERNAL' ? ['registration_number' => $loser['nomor_internal']] : ['code' => strtoupper($loser['kredensial'])];
                $this->assertDatabaseHas($table, $key + ['is_used' => false, 'user_id' => null]);
            }
        }
    }

    public function test_database_rejects_second_active_allocation_for_same_user(): void
    {
        $payload = $this->credential('UMUM', 'unique');
        $id = $this->postJson('/api/register', $payload)->assertOk()->json('user.id');
        try {
            DB::transaction(function () use ($id) {
                PpdbSeatAllocation::create(['user_id' => $id, 'payment_id' => null,
                    'jenjang' => 'SD', 'status' => 'ALLOCATED', 'allocated_at' => now()]);
            });
            $this->fail('Duplicate active owner was accepted.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23000', (string) $e->getCode());
            $this->assertStringContainsString('ppdb_alloc_active_user_unique', $e->getMessage());
        }
        $this->assertSame(1, PpdbSeatAllocation::where('user_id', $id)->count());
    }

    public function test_failure_after_allocation_insert_rolls_back_user_allocation_and_credential(): void
    {
        $payload = $this->credential('UMUM', 'rollback');
        PpdbSeatAllocation::created(function () {
            throw new \RuntimeException('Injected post-insert failure');
        });
        try {
            $this->postJson('/api/register', $payload)->assertStatus(500);
            $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
            $this->assertSame(0, PpdbSeatAllocation::count());
            $this->assertDatabaseHas('tokens', ['code' => 'AM-ROLLBACK', 'is_used' => false, 'user_id' => null]);
        } finally {
            PpdbSeatAllocation::flushEventListeners();
        }
    }
}
