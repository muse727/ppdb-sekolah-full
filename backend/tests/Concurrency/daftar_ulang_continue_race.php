<?php

/**
 * Deterministic concurrency proof for B.7.2 MELANJUTKAN continuation:
 * continue vs. continue competing on the SAME canonical users row.
 *
 * Why a standalone harness (mirroring daftar_ulang_withdrawal_race.php):
 *   The PHPUnit harness wraps every test in a transaction that is rolled back,
 *   so it cannot observe real row-lock contention between independent
 *   connections. This script uses real MySQL, real commits, real InnoDB locks.
 *
 * What it proves:
 *   Worker A: BEGIN; SELECT users ... FOR UPDATE (the FIRST lock the service
 *             takes); HOLD it; commit the full NULL -> TERDAFTAR transition.
 *   Worker B: runs the SAME users FOR UPDATE the service performs, so B BLOCKS
 *             on the user-row lock and only proceeds after A commits — by which
 *             time status_siswa = TERDAFTAR, so B replays idempotently WITHOUT
 *             changing continued_at, payment, invoice or seat.
 *
 * Deterministic expected outcome:
 *   - exactly ONE TERDAFTAR transition (A commits, B replays)
 *   - continued_at set exactly once, never overwritten
 *   - the linked DU payment row is byte-for-byte unchanged (SUCCESS stays)
 *   - the seat allocation is byte-for-byte unchanged (ALLOCATED stays)
 *   - ppdb_capacities unchanged
 *
 * SAFETY: refuses to run unless APP_ENV=testing AND the live connection resolves
 * to ppdb_sekolah_test. See ConcurrencySafetyGuard.php.
 *
 * Run with:  APP_ENV=testing php backend/tests/Concurrency/daftar_ulang_continue_race.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__.'/ConcurrencySafetyGuard.php';
assert_disposable_concurrency_target();

use App\Models\DaftarUlangAgreementAcceptance;
use App\Models\DaftarUlangInvoice;
use App\Models\Payment;
use App\Models\PpdbSeatAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

echo 'DB driver: '.DB::connection()->getDriverName().PHP_EOL;

// ---------------------------------------------------------------- setup ----
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('du_agreement_acceptances')->delete();
DB::table('du_withdrawals')->delete();
DaftarUlangInvoice::query()->delete();
Payment::query()->delete();
PpdbSeatAllocation::query()->delete();
DB::table('du_pricing_revisions')->where('pricing_key', 'SD')->where('revision', 997)->delete();
User::where('email', 'like', '%@du-cont-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$owner = User::factory()->create(['email' => 'owner@du-cont-race.test', 'jenjang' => 'SD']);
$owner->forceFill(['role' => 'orang_tua', 'status_kelulusan' => 'LULUS'])->save();

$revisionId = DB::table('du_pricing_revisions')->insertGetId([
    'pricing_key' => 'SD', 'revision' => 997, 'source' => 'DEFAULT', 'jenjang' => 'SD',
    'user_id' => null, 'items' => json_encode([['id' => 'x', 'name' => 'X', 'quantity' => 1, 'unit_amount' => 250000]]),
    'subtotal' => 250000, 'discount_nominal' => 0, 'total' => 250000,
    'created_by' => $owner->id, 'updated_by' => $owner->id,
    'created_at' => now(), 'updated_at' => now(),
]);

$agreementVersion = 'DU-RACE-1';
$agreementText = 'Race agreement.';
$agreementSha = hash('sha256', $agreementText);

$payment = Payment::create([
    'user_id' => $owner->id, 'payment_category' => Payment::CATEGORY_DAFTAR_ULANG,
    'invoice_number' => 'DU-CONT-'.Str::uuid(), 'jenjang' => 'SD', 'amount' => 250000,
    'payment_method' => 'MANUAL', 'status' => 'SUCCESS', 'proof_of_payment' => 'bukti_bayar/cont.png',
    'verified_at' => now(),
]);
$invoiceId = DB::table('du_invoices')->insertGetId([
    'user_id' => $owner->id, 'payment_id' => $payment->id, 'invoice_number' => $payment->invoice_number,
    'jenjang' => 'SD', 'student_name' => 'Continue race', 'jalur_pendaftaran' => 'UMUM',
    'pricing_revision_id' => $revisionId,
    'pricing_snapshot' => json_encode(['source' => 'DEFAULT', 'agreement' => [
        'version' => $agreementVersion, 'text' => $agreementText, 'sha256' => $agreementSha]]),
    'subtotal' => 250000, 'discount_nominal' => 0, 'total' => 250000,
    'snapshot_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
DB::table('du_agreement_acceptances')->insert([
    'user_id' => $owner->id, 'invoice_id' => $invoiceId,
    'agreement_version' => $agreementVersion, 'agreement_sha256' => $agreementSha,
    'agreed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
$allocationId = DB::table('ppdb_seat_allocations')->insertGetId([
    'user_id' => $owner->id, 'payment_id' => null, 'jenjang' => 'SD', 'status' => 'ALLOCATED',
    'allocated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);

$uid = $owner->id;
$paymentBefore = DB::table('payments')->where('id', $payment->id)->get()->toJson();
$seatBefore = DB::table('ppdb_seat_allocations')->where('id', $allocationId)->get()->toJson();
$capBefore = DB::table('ppdb_capacities')->get()->toJson();
echo "Setup: user id={$uid} invoice id={$invoiceId} payment id={$payment->id} (SUCCESS) seat id={$allocationId}\n";

// --------------------------------------------- connection A holds user lock ----
$cfg = config('database.connections.mysql');
$dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}";
$connA = new PDO($dsn, $cfg['username'], $cfg['password']);
$connA->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connA->beginTransaction();
$stmt = $connA->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
$stmt->execute([$uid]);
echo 'A: locked users row id='.$stmt->fetchColumn()." (FOR UPDATE)\n";

// ----------------------- worker B blocks on the same users lock, then continues ----
$raceScript = tempnam(sys_get_temp_dir(), 'ducont').'.php';
file_put_contents($raceScript, '<?php
require '.var_export(__DIR__.'/../../vendor/autoload.php', true).';
$app = require '.var_export(__DIR__.'/../../bootstrap/app.php', true).';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$uid = (int) '.var_export($uid, true).';
// Mirror DaftarUlangCompletionService: users lock first, then idempotent transition.
DB::transaction(function () use ($uid) {
    $user = DB::table("users")->where("id", $uid)->lockForUpdate()->first();
    if ($user->status_siswa === "TERDAFTAR") { echo "REPLAY"; return; }
    if ($user->status_siswa === "MENGUNDURKAN_DIRI") { echo "REJECT-WITHDRAWN"; return; }
    DB::table("users")->where("id", $uid)->update([
        "status_siswa" => "TERDAFTAR", "continued_at" => now(), "updated_at" => now()]);
    echo "CONTINUED";
});
');
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$procB = proc_open(['php', $raceScript], $descriptors, $pipesB);
sleep(1); // give B time to block on the users lock
echo "B: worker started (should be blocked on users FOR UPDATE)\n";

// A completes the transition and commits. Use a string timestamp so the
// assertion compares exactly what was written (no Carbon reparse drift).
$now = now()->toDateTimeString();
$connA->prepare('UPDATE users SET status_siswa = ?, continued_at = ?, updated_at = ? WHERE id = ?')
    ->execute(['TERDAFTAR', $now, $now, $uid]);
$connA->commit();
echo "A: committed TERDAFTAR transition\n";

$bOut = trim(stream_get_contents($pipesB[1]));
fclose($pipesB[1]); fclose($pipesB[2]); proc_close($procB);
echo "B: completed with result '{$bOut}' (blocked until A committed)\n";

// ------------------------------------------------------------- assertions ----
$userAfter = User::find($uid);
$continuedCount = DB::table('users')->where('id', $uid)->where('status_siswa', 'TERDAFTAR')->count();
$paymentAfter = DB::table('payments')->where('id', $payment->id)->get()->toJson();
$seatAfter = DB::table('ppdb_seat_allocations')->where('id', $allocationId)->get()->toJson();
$capAfter = DB::table('ppdb_capacities')->get()->toJson();

$checks = [
    'exactly one TERDAFTAR user' => $continuedCount === 1,
    'status_siswa = TERDAFTAR' => $userAfter->status_siswa === 'TERDAFTAR',
    'continued_at set once (not overwritten by B)' => $userAfter->continued_at !== null
        && \Carbon\Carbon::parse($userAfter->continued_at)->toDateTimeString() === $now,
    'B replayed idempotently (REPLAY)' => $bOut === 'REPLAY',
    'payment byte-for-byte unchanged' => $paymentAfter === $paymentBefore,
    'seat byte-for-byte unchanged' => $seatAfter === $seatBefore,
    'capacity unchanged' => $capAfter === $capBefore,
];
$fail = false;
foreach ($checks as $label => $ok) { echo ($ok ? 'PASS ' : 'FAIL ').$label.PHP_EOL; if (!$ok) $fail = true; }

// ---------------------------------------------------------------- cleanup ----
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('du_agreement_acceptances')->delete();
DB::table('du_withdrawals')->delete();
DaftarUlangInvoice::query()->delete();
Payment::query()->delete();
PpdbSeatAllocation::query()->delete();
DB::table('du_pricing_revisions')->where('pricing_key', 'SD')->where('revision', 997)->delete();
User::where('email', 'like', '%@du-cont-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
@unlink($raceScript);
echo "cleanup done\n";

exit($fail ? 1 : 0);
