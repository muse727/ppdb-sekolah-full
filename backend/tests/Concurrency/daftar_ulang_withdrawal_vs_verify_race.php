<?php

/**
 * Deterministic concurrency proof (B.7.1): Daftar Ulang WITHDRAWAL (parent)
 * competing with admin payment VERIFY on the SAME student's DU payment.
 *
 * What it proves:
 *   Worker A (verify):  BEGIN; SELECT payments ... FOR UPDATE (scoped to
 *                       DAFTAR_ULANG + invoice-backed); HOLD the payment lock.
 *   Worker B (withdraw): runs the withdrawal lock order (users -> invoice ->
 *                       allocation -> payment). B acquires the users/invoice/
 *                       allocation locks, then BLOCKS on the payment row until
 *                       A commits. A's verify transitions PENDING_MANUAL ->
 *                       SUCCESS; B then proceeds and releases the seat WITHOUT
 *                       touching the payment.
 *
 * Deterministic expected outcome:
 *   - exactly ONE verify transition (payment PENDING_MANUAL -> SUCCESS)
 *   - exactly ONE seat RELEASED with reason WITHDRAWN (by B)
 *   - users.status_kelulusan remains LULUS
 *   - no deadlock: the two lock orders never form a cycle because the
 *     withdrawal path always takes the users row BEFORE the payment row,
 *     while verify only ever holds the single payment row.
 *
 * SAFETY: refuses to run unless APP_ENV=testing AND the live connection resolves
 * to ppdb_sekolah_test. See ConcurrencySafetyGuard.php.
 *
 * Run with:  APP_ENV=testing php backend/tests/Concurrency/daftar_ulang_withdrawal_vs_verify_race.php
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

DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('du_withdrawals')->delete();
DaftarUlangInvoice::query()->delete();
Payment::query()->delete();
PpdbSeatAllocation::query()->delete();
User::where('email', 'like', '%@du-wv-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$owner = User::factory()->create(['email' => 'owner@du-wv-race.test', 'jenjang' => 'SD']);
$owner->forceFill(['role' => 'orang_tua', 'status_kelulusan' => 'LULUS'])->save();
$revisionId = DB::table('du_pricing_revisions')->insertGetId([
    'pricing_key' => 'SD', 'revision' => 997, 'source' => 'DEFAULT', 'jenjang' => 'SD', 'user_id' => null,
    'items' => json_encode([['id' => 't', 'name' => 'T', 'quantity' => 1, 'unit_amount' => 250000, 'line_total' => 250000]]),
    'subtotal' => 250000, 'discount_nominal' => 0, 'total' => 250000,
    'created_by' => $owner->id, 'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
]);
$payment = Payment::create([
    'user_id' => $owner->id, 'payment_category' => Payment::CATEGORY_DAFTAR_ULANG,
    'invoice_number' => 'DU-WV-'.Str::uuid(), 'jenjang' => 'SD', 'amount' => 250000,
    'payment_method' => 'MANUAL', 'status' => 'PENDING_MANUAL', 'proof_of_payment' => 'bukti_bayar/wv.png',
]);
$invoiceId = DB::table('du_invoices')->insertGetId([
    'user_id' => $owner->id, 'payment_id' => $payment->id, 'invoice_number' => $payment->invoice_number,
    'jenjang' => 'SD', 'student_name' => 'Withdraw vs verify', 'jalur_pendaftaran' => 'UMUM',
    'pricing_revision_id' => $revisionId, 'pricing_snapshot' => json_encode(['source' => 'DEFAULT']),
    'subtotal' => 250000, 'discount_nominal' => 0, 'total' => 250000,
    'snapshot_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
$allocationId = DB::table('ppdb_seat_allocations')->insertGetId([
    'user_id' => $owner->id, 'payment_id' => null, 'jenjang' => 'SD', 'status' => 'ALLOCATED',
    'allocated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
]);
$uid = $owner->id;
$pid = $payment->id;
echo "Setup: user={$uid} payment={$pid} (PENDING_MANUAL) seat={$allocationId}\n";

// ------------------------------- connection A holds the payment lock (verify path) ----
$cfg = config('database.connections.mysql');
$dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}";
$connA = new PDO($dsn, $cfg['username'], $cfg['password']);
$connA->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connA->beginTransaction();
$stmt = $connA->prepare('SELECT id FROM payments WHERE id = ? AND payment_category = ? AND id IN (SELECT payment_id FROM du_invoices) FOR UPDATE');
$stmt->execute([$pid, Payment::CATEGORY_DAFTAR_ULANG]);
echo 'A: locked payment row id='.$stmt->fetchColumn()." (FOR UPDATE)\n";

// ------------- worker B: full withdrawal, blocks on the payment row until A commits ----
$raceScript = tempnam(sys_get_temp_dir(), 'duwv').'.php';
file_put_contents($raceScript, '<?php
require '.var_export(__DIR__.'/../../vendor/autoload.php', true).';
$app = require '.var_export(__DIR__.'/../../bootstrap/app.php', true).';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$uid = (int) '.var_export($uid, true).';
$invoiceId = (int) '.var_export($invoiceId, true).';
DB::transaction(function () use ($uid, $invoiceId) {
    $user = DB::table("users")->where("id", $uid)->lockForUpdate()->first();
    $invoice = DB::table("du_invoices")->where("user_id", $uid)->orderBy("id")->lockForUpdate()->first();
    if ($invoice->withdrawn_at !== null) { echo "REPLAY"; return; }
    $active = DB::table("ppdb_seat_allocations")->where("user_id", $uid)->where("status", "ALLOCATED")->orderBy("id")->lockForUpdate()->get();
    // Withdrawal reads the payment too (invoice->payment), blocking on the lock held by A.
    $payment = DB::table("payments")->where("id", $invoice->payment_id)->lockForUpdate()->first();
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
echo "B: launched (withdraw), will block on payment lock...\n";

// A completes its verify while holding the lock, then commits.
$now = date('Y-m-d H:i:s');
$connA->prepare('UPDATE payments SET status = ?, verified_at = ?, updated_at = ? WHERE id = ?')->execute(['SUCCESS', $now, $now, $pid]);
$connA->commit();
echo "A: verify committed (payment lock released)\n";

$bOut = trim(stream_get_contents($pipesB[1]));
fclose($pipesB[1]); fclose($pipesB[2]); proc_close($procB);
echo "B: completed with '{$bOut}'\n";

$payment = DB::table('payments')->where('id', $pid)->first();
$released = DB::table('ppdb_seat_allocations')->where('user_id', $uid)->where('status', 'RELEASED')->get();
$userAfter = DB::table('users')->where('id', $uid)->first();
$withdrawals = DB::table('du_withdrawals')->where('invoice_id', $invoiceId)->count();

$checks = [
    'verify won once (payment SUCCESS)' => $payment->status === 'SUCCESS',
    'exactly one seat RELEASED' => $released->count() === 1,
    'release_reason = WITHDRAWN' => $released->count() === 1 && $released->first()->release_reason === 'WITHDRAWN',
    'exactly one withdrawal record' => $withdrawals === 1,
    'status_kelulusan still LULUS' => $userAfter->status_kelulusan === 'LULUS',
    'no deadlock (B completed)' => in_array($bOut, ['WITHDREW', 'REPLAY'], true),
];
$fail = false;
foreach ($checks as $label => $ok) { echo ($ok ? 'PASS ' : 'FAIL ').$label.PHP_EOL; if (!$ok) $fail = true; }

DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('du_withdrawals')->delete();
DaftarUlangInvoice::query()->delete();
Payment::query()->delete();
PpdbSeatAllocation::query()->delete();
User::where('email', 'like', '%@du-wv-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
@unlink($raceScript);
echo "cleanup done\n";
exit($fail ? 1 : 0);
