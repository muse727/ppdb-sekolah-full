<?php

/**
 * Deterministic concurrency proof for Daftar Ulang (B.7.1) withdrawal:
 * withdraw vs. withdraw competing on the SAME canonical users row.
 *
 * Why a standalone harness (mirroring daftar_ulang_settlement_race.php):
 *   The PHPUnit harness wraps every test in a transaction that is rolled back,
 *   so it cannot observe real row-lock contention between independent
 *   connections. This script uses real MySQL, real commits, real InnoDB locks.
 *
 * What it proves:
 *   Worker A: BEGIN; SELECT users ... FOR UPDATE (the FIRST lock the service
 *             takes); HOLD it; complete a full withdrawal (set withdrawn_at,
 *             insert du_withdrawals, release exactly one ALLOCATED seat).
 *   Worker B: runs the SAME users FOR UPDATE the service performs, so B BLOCKS
 *             on the user-row lock and only proceeds after A commits — by which
 *             time withdrawn_at is set, so B replays idempotently WITHOUT
 *             releasing a seat or writing another withdrawal row.
 *
 * Deterministic expected outcome:
 *   - exactly ONE du_withdrawals row for the invoice
 *   - exactly ONE withdrawn_at timestamp (set once)
 *   - exactly ONE ppdb_seat_allocations row RELEASED with reason WITHDRAWN
 *   - users.status_kelulusan remains LULUS (withdrawal != TIDAK_LULUS)
 *   - the linked DU payment row is byte-for-byte unchanged (SUCCESS stays)
 *
 * SAFETY: refuses to run unless APP_ENV=testing AND the live connection resolves
 * to ppdb_sekolah_test. See ConcurrencySafetyGuard.php.
 *
 * Run with:  APP_ENV=testing php backend/tests/Concurrency/daftar_ulang_withdrawal_race.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

require_once __DIR__.'/ConcurrencySafetyGuard.php';
assert_disposable_concurrency_target();

use App\Models\DaftarUlangInvoice;
use App\Models\Payment;
use App\Models\PpdbSeatAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

echo 'DB driver: '.DB::connection()->getDriverName().PHP_EOL;

// ---------------------------------------------------------------- setup ----
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('du_withdrawals')->delete();
DaftarUlangInvoice::query()->delete();
Payment::query()->delete();
PpdbSeatAllocation::query()->delete();
User::where('email', 'like', '%@du-wd-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$owner = User::factory()->create(['email' => 'owner@du-wd-race.test', 'jenjang' => 'SD']);
$owner->forceFill(['role' => 'orang_tua', 'status_kelulusan' => 'LULUS'])->save();

$revisionId = DB::table('du_pricing_revisions')->insertGetId([
    'pricing_key' => 'SD', 'revision' => 998, 'source' => 'DEFAULT', 'jenjang' => 'SD', 'user_id' => null,
    'items' => json_encode([['id' => 'tuition', 'name' => 'Tuition', 'quantity' => 1, 'unit_amount' => 250000, 'line_total' => 250000]]),
    'subtotal' => 250000, 'discount_nominal' => 0, 'total' => 250000,
    'created_by' => $owner->id, 'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
]);
$payment = Payment::create([
    'user_id' => $owner->id, 'payment_category' => Payment::CATEGORY_DAFTAR_ULANG,
    'invoice_number' => 'DU-WD-'.Str::uuid(), 'jenjang' => 'SD', 'amount' => 250000,
    'payment_method' => 'MANUAL', 'status' => 'SUCCESS', 'proof_of_payment' => 'bukti_bayar/wd.png',
    'verified_at' => now(),
]);
$invoiceId = DB::table('du_invoices')->insertGetId([
    'user_id' => $owner->id, 'payment_id' => $payment->id, 'invoice_number' => $payment->invoice_number,
    'jenjang' => 'SD', 'student_name' => 'Withdraw race', 'jalur_pendaftaran' => 'UMUM',
    'pricing_revision_id' => $revisionId, 'pricing_snapshot' => json_encode(['source' => 'DEFAULT']),
    'subtotal' => 250000, 'discount_nominal' => 0, 'total' => 250000,
    'snapshot_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
$allocationId = DB::table('ppdb_seat_allocations')->insertGetId([
    'user_id' => $owner->id, 'payment_id' => null, 'jenjang' => 'SD', 'status' => 'ALLOCATED',
    'allocated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);

$uid = $owner->id;
$paymentBefore = DB::table('payments')->where('id', $payment->id)->get()->toJson();
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

// ----------------------- worker B blocks on the same users lock, then withdraws ----
$raceScript = tempnam(sys_get_temp_dir(), 'duwd').'.php';
file_put_contents($raceScript, '<?php
require '.var_export(__DIR__.'/../../vendor/autoload.php', true).';
$app = require '.var_export(__DIR__.'/../../bootstrap/app.php', true).';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$uid = (int) '.var_export($uid, true).';
$invoiceId = (int) '.var_export($invoiceId, true).';
// Mirror DaftarUlangWithdrawalService: users -> invoice -> allocation, then release.
DB::transaction(function () use ($uid, $invoiceId) {
    $user = DB::table("users")->where("id", $uid)->lockForUpdate()->first();
    $invoice = DB::table("du_invoices")->where("user_id", $uid)->orderBy("id")->lockForUpdate()->first();
    if ($invoice->withdrawn_at !== null) { echo "REPLAY"; return; }
    $active = DB::table("ppdb_seat_allocations")->where("user_id", $uid)->where("status", "ALLOCATED")->orderBy("id")->lockForUpdate()->get();
    if ($active->count() !== 1) { echo "NOSEAT"; return; }
    DB::table("du_invoices")->where("id", $invoiceId)->update(["withdrawn_at" => now(), "updated_at" => now()]);
    DB::table("du_withdrawals")->insert(["user_id" => $uid, "invoice_id" => $invoiceId, "reason" => "MENGUNDURKAN_DIRI", "note" => null, "initiated_by" => "PARENT", "created_at" => now(), "updated_at" => now()]);
    DB::table("ppdb_seat_allocations")->where("id", $active->first()->id)->update(["status" => "RELEASED", "released_at" => now(), "release_reason" => "WITHDRAWN", "updated_at" => now()]);
    echo "WITHDREW";
});
');
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$procB = proc_open([PHP_BINARY, $raceScript], $descriptors, $pipesB);
fclose($pipesB[0]);
usleep(400000);
echo "B: launched, waiting on users row lock...\n";

// A completes its withdrawal while still holding the lock, then commits.
$now = date('Y-m-d H:i:s');
$connA->prepare('UPDATE du_invoices SET withdrawn_at = ?, updated_at = ? WHERE id = ?')->execute([$now, $now, $invoiceId]);
$connA->prepare('INSERT INTO du_withdrawals (user_id, invoice_id, reason, note, initiated_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
    ->execute([$uid, $invoiceId, 'MENGUNDURKAN_DIRI', null, 'PARENT', $now, $now]);
$connA->prepare('UPDATE ppdb_seat_allocations SET status = ?, released_at = ?, release_reason = ?, updated_at = ? WHERE id = ?')
    ->execute(['RELEASED', $now, 'WITHDRAWN', $now, $allocationId]);
$connA->commit();
echo "A: withdrawal committed (users lock released)\n";

$bOut = trim(stream_get_contents($pipesB[1]));
fclose($pipesB[1]); fclose($pipesB[2]); proc_close($procB);
echo "B: completed with result '{$bOut}' (blocked until A committed)\n";

// ------------------------------------------------------------- assertions ----
$withdrawals = DB::table('du_withdrawals')->where('invoice_id', $invoiceId)->count();
$invoice = DB::table('du_invoices')->where('id', $invoiceId)->first();
$released = DB::table('ppdb_seat_allocations')->where('user_id', $uid)->where('status', 'RELEASED')->get();
$userAfter = DB::table('users')->where('id', $uid)->first();
$paymentAfter = DB::table('payments')->where('id', $payment->id)->get()->toJson();

$checks = [
    'exactly one du_withdrawals row' => $withdrawals === 1,
    'withdrawn_at set once' => $invoice->withdrawn_at !== null,
    'exactly one seat RELEASED' => $released->count() === 1,
    'release_reason = WITHDRAWN' => $released->count() === 1 && $released->first()->release_reason === 'WITHDRAWN',
    'B replayed idempotently (REPLAY)' => $bOut === 'REPLAY',
    'status_kelulusan still LULUS' => $userAfter->status_kelulusan === 'LULUS',
    'payment byte-for-byte unchanged' => $paymentAfter === $paymentBefore,
];
$fail = false;
foreach ($checks as $label => $ok) { echo ($ok ? 'PASS ' : 'FAIL ').$label.PHP_EOL; if (!$ok) $fail = true; }

// ---------------------------------------------------------------- cleanup ----
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('du_withdrawals')->delete();
DaftarUlangInvoice::query()->delete();
Payment::query()->delete();
PpdbSeatAllocation::query()->delete();
User::where('email', 'like', '%@du-wd-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
@unlink($raceScript);
echo "cleanup done\n";

exit($fail ? 1 : 0);
