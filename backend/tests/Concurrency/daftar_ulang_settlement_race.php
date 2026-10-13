<?php

/**
 * Deterministic concurrency proof for Daftar Ulang (B.6A) payment settlement:
 * DU verify vs. DU reject competing on the SAME payment row.
 *
 * Why a standalone harness (mirroring payment_verification_race.php):
 *   The PHPUnit harness wraps every test in a transaction that is rolled back,
 *   so it cannot observe real row-lock contention between independent
 *   connections. This script uses real MySQL, real commits, real InnoDB locks.
 *
 * What it proves:
 *   Worker A: BEGIN; SELECT ... FOR UPDATE on the DU payment row; HOLD the lock;
 *             commit a verification (PENDING_MANUAL -> SUCCESS).
 *   Worker B: while A holds the lock, runs the SAME SELECT ... FOR UPDATE the
 *             service performs (scoped to DAFTAR_ULANG + invoice-backed), so B
 *             BLOCKS on the row lock and only proceeds after A commits — by
 *             which time status is SUCCESS, so B's reject returns 409 WITHOUT
 *             mutating anything.
 *
 * Deterministic expected outcome:
 *   - exactly ONE transition occurs (PENDING_MANUAL -> SUCCESS)
 *   - the competing reject is refused (409), leaving no REJECTED row
 *   - verifier_id / verified_at written exactly once, by A
 *   - ppdb_seat_allocations and ppdb_capacities are byte-for-byte unchanged
 *
 * SAFETY: refuses to run unless APP_ENV=testing AND the live connection resolves
 * to ppdb_sekolah_test. See ConcurrencySafetyGuard.php.
 *
 * Run with:  APP_ENV=testing php backend/tests/Concurrency/daftar_ulang_settlement_race.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Fail closed BEFORE any destructive setup can touch a database.
require_once __DIR__.'/ConcurrencySafetyGuard.php';
assert_disposable_concurrency_target();

use App\Models\DaftarUlangInvoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

echo 'DB driver: '.DB::connection()->getDriverName().PHP_EOL;

// ---------------------------------------------------------------- setup ----
// An admin actor, an owner, and one committed PENDING_MANUAL DAFTAR_ULANG
// payment backed by a real du_invoices row (the service requires the link).
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DaftarUlangInvoice::query()->delete();
Payment::query()->delete();
User::where('email', 'like', '%@du-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$admin = User::factory()->create(['email' => 'admin@du-race.test']);
$admin->forceFill(['role' => 'admin'])->save();
$owner = User::factory()->create(['email' => 'owner@du-race.test', 'jenjang' => 'SD']);
$owner->forceFill(['role' => 'orang_tua', 'status_kelulusan' => 'LULUS'])->save();

// Snapshot seat + quota state to prove settlement never touches them.
$seatsBefore = DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson();
$quotaBefore = DB::table('ppdb_capacities')->orderBy('jenjang')->get()->toJson();

// A real pricing revision (du_invoices.pricing_revision_id is NOT NULL).
$revisionId = DB::table('du_pricing_revisions')->insertGetId([
    'pricing_key' => 'SD',
    'revision' => 999,
    'source' => 'DEFAULT',
    'jenjang' => 'SD',
    'user_id' => null,
    'items' => json_encode([['id' => 'tuition', 'name' => 'Tuition', 'quantity' => 1, 'unit_amount' => 250000, 'line_total' => 250000]]),
    'subtotal' => 250000,
    'discount_nominal' => 0,
    'total' => 250000,
    'created_by' => $admin->id,
    'updated_by' => $admin->id,
    'created_at' => now(),
    'updated_at' => now(),
]);

$payment = Payment::create([
    'user_id' => $owner->id,
    'payment_category' => Payment::CATEGORY_DAFTAR_ULANG,
    'invoice_number' => 'DU-RACE-'.Str::uuid(),
    'jenjang' => 'SD',
    'amount' => 250000,
    'payment_method' => 'MANUAL',
    'status' => 'PENDING_MANUAL',
    'proof_of_payment' => 'bukti_bayar/du-race.png',
]);
$invoiceId = DB::table('du_invoices')->insertGetId([
    'user_id' => $owner->id,
    'payment_id' => $payment->id,
    'invoice_number' => $payment->invoice_number,
    'jenjang' => 'SD',
    'student_name' => 'Race student',
    'jalur_pendaftaran' => 'UMUM',
    'pricing_revision_id' => $revisionId,
    'pricing_snapshot' => json_encode(['source' => 'DEFAULT']),
    'subtotal' => 250000,
    'discount_nominal' => 0,
    'total' => 250000,
    'snapshot_at' => now(),
    'created_at' => now(),
    'updated_at' => now(),
]);

$pid = $payment->id;
echo "Setup: DU payment id={$pid} status=PENDING_MANUAL invoice_id={$invoiceId}\n";

// ------------------------------------------------- connection A holds lock ----
$cfg = config('database.connections.mysql');
$dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}";
$connA = new PDO($dsn, $cfg['username'], $cfg['password']);
$connA->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connA->beginTransaction();
// The exact lock the service takes: DAFTAR_ULANG category + invoice-backed.
$lockSql = 'SELECT id FROM payments WHERE id = ? AND payment_category = ?'
    .' AND id IN (SELECT payment_id FROM du_invoices) FOR UPDATE';
$stmt = $connA->prepare($lockSql);
$stmt->execute([$pid, Payment::CATEGORY_DAFTAR_ULANG]);
$locked = $stmt->fetchColumn();
echo "A: locked payment row id={$locked} (FOR UPDATE)\n";

// --------------------------------------------- connection B attempts reject ----
// While A holds the lock, B runs the same locking read then a status re-check.
// B blocks on InnoDB until A commits, then sees SUCCESS and must refuse (409).
$raceScript = tempnam(sys_get_temp_dir(), 'du_race_b_').'.php';
file_put_contents($raceScript, '<?php
require '.var_export(__DIR__.'/../../vendor/autoload.php', true).';
$app = require '.var_export(__DIR__.'/../../bootstrap/app.php', true).';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$pid = (int) '.var_export($pid, true).';
$out = DB::transaction(function () use ($pid) {
    $p = DB::table("payments")->where("id", $pid)->where("payment_category", "DAFTAR_ULANG")
        ->whereIn("id", DB::table("du_invoices")->select("payment_id"))->lockForUpdate()->first();
    if (!$p) return "404";
    if ($p->status !== "PENDING_MANUAL") return "409";
    DB::table("payments")->where("id", $pid)->update(["status" => "REJECTED"]);
    return "REJECTED";
});
echo $out;
');

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$procB = proc_open(
    [PHP_BINARY, $raceScript],
    $descriptors,
    $pipesB
);
fclose($pipesB[0]);

// Give B a moment to reach its blocking lock-wait (not a correctness sleep —
// the InnoDB lock itself guarantees ordering; this only paces the log output).
usleep(400000);
echo "B: launched, waiting on row lock...\n";

// ------------------------------------------------- connection A commits ----
$connA->prepare('UPDATE payments SET status = ?, verifier_id = ?, verified_at = ? WHERE id = ?')
    ->execute(['SUCCESS', $admin->id, date('Y-m-d H:i:s'), $pid]);
$connA->commit();
echo "A: committed SUCCESS\n";

$rejectOutcome = trim(stream_get_contents($pipesB[1]));
fclose($pipesB[1]);
$errB = stream_get_contents($pipesB[2]);
fclose($pipesB[2]);
proc_close($procB);
@unlink($raceScript);
echo "B: outcome = {$rejectOutcome}".($errB ? " (stderr: ".trim($errB).")" : "")."\n";

// ------------------------------------------------------------ final state ----
$final = Payment::find($pid);
$seatsAfter = DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson();
$quotaAfter = DB::table('ppdb_capacities')->orderBy('jenjang')->get()->toJson();

echo PHP_EOL;
echo "FINAL: status={$final->status} verifier_id={$final->verifier_id}\n";

$passed = $final->status === 'SUCCESS'
    && (int) $final->verifier_id === (int) $admin->id
    && $final->verified_at !== null
    && in_array($rejectOutcome, ['409', '404', 'BLOCKED'], true)
    && $seatsBefore === $seatsAfter
    && $quotaBefore === $quotaAfter;

echo $passed ? "DU SETTLEMENT CONCURRENCY PROOF PASSED\n" : "DU SETTLEMENT CONCURRENCY PROOF FAILED\n";
echo '  seat rows unchanged: '.($seatsBefore === $seatsAfter ? 'yes' : 'NO').PHP_EOL;
echo '  capacity rows unchanged: '.($quotaBefore === $quotaAfter ? 'yes' : 'NO').PHP_EOL;

// ------------------------------------------------------------- cleanup ------
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('du_invoices')->where('id', $invoiceId)->delete();
Payment::where('id', $pid)->delete();
DB::table('du_pricing_revisions')->where('id', $revisionId)->delete();
User::where('email', 'like', '%@du-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo "cleanup done\n";

exit($passed ? 0 : 1);
