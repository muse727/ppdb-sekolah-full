<?php

/**
 * Deterministic concurrency proof for B.7.2: MELANJUTKAN vs MENGUNDURKAN DIRI
 * competing on the SAME canonical users row.
 *
 * This is the CRITICAL B.7.2 race. Both flows take the users row lock FIRST
 * (evidence: DaftarUlangWithdrawalService + DaftarUlangCompletionService +
 * daftar_ulang_withdrawal_race.php), so InnoDB serializes them. The loser
 * observes the winner's committed terminal state and rejects/replays.
 *
 * Scenario A (withdrawal commits first):
 *   Worker W: locks users, sets withdrawn_at + status_siswa=MENGUNDURKAN_DIRI,
 *             releases seat, commits.
 *   Worker C: blocks on users lock; after W commits it sees
 *             status_siswa=MENGUNDURKAN_DIRI and REJECTS the continuation.
 *   Final: MENGUNDURKAN_DIRI, seat RELEASED, invoice withdrawn.
 *
 * Scenario B (continuation commits first):
 *   Worker C: locks users, commits NULL -> TERDAFTAR, commits.
 *   Worker W: blocks on users lock; after C commits it sees
 *             status_siswa=TERDAFTAR and REJECTS the withdrawal.
 *   Final: TERDAFTAR, seat ALLOCATED, invoice NOT withdrawn.
 *
 * Both outcomes are VALID terminal states; neither ever produces both.
 *
 * SAFETY: refuses to run unless APP_ENV=testing AND the live connection resolves
 * to ppdb_sekolah_test. See ConcurrencySafetyGuard.php.
 *
 * Run with:  APP_ENV=testing php backend/tests/Concurrency/daftar_ulang_continue_vs_withdraw_race.php
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

function makeEligibleStudent(string $email): array
{
    $owner = User::factory()->create(['email' => $email, 'jenjang' => 'SD']);
    $owner->forceFill(['role' => 'orang_tua', 'status_kelulusan' => 'LULUS'])->save();
    $revisionId = DB::table('du_pricing_revisions')->insertGetId([
        'pricing_key' => 'SD', 'revision' => 996, 'source' => 'DEFAULT', 'jenjang' => 'SD',
        'user_id' => null, 'items' => json_encode([['id' => 'x', 'name' => 'X', 'quantity' => 1, 'unit_amount' => 250000]]),
        'subtotal' => 250000, 'discount_nominal' => 0, 'total' => 250000,
        'created_by' => $owner->id, 'updated_by' => $owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $sha = hash('sha256', 'Race agreement.');
    $payment = Payment::create([
        'user_id' => $owner->id, 'payment_category' => Payment::CATEGORY_DAFTAR_ULANG,
        'invoice_number' => 'DU-CVW-'.Str::uuid(), 'jenjang' => 'SD', 'amount' => 250000,
        'payment_method' => 'MANUAL', 'status' => 'SUCCESS', 'proof_of_payment' => 'bukti_bayar/cvw.png',
        'verified_at' => now(),
    ]);
    $invoiceId = DB::table('du_invoices')->insertGetId([
        'user_id' => $owner->id, 'payment_id' => $payment->id, 'invoice_number' => $payment->invoice_number,
        'jenjang' => 'SD', 'student_name' => 'Race', 'jalur_pendaftaran' => 'UMUM',
        'pricing_revision_id' => $revisionId,
        'pricing_snapshot' => json_encode(['source' => 'DEFAULT', 'agreement' => [
            'version' => 'DU-RACE-1', 'text' => 'Race agreement.', 'sha256' => $sha]]),
        'subtotal' => 250000, 'discount_nominal' => 0, 'total' => 250000,
        'snapshot_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('du_agreement_acceptances')->insert([
        'user_id' => $owner->id, 'invoice_id' => $invoiceId,
        'agreement_version' => 'DU-RACE-1', 'agreement_sha256' => $sha,
        'agreed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $allocationId = DB::table('ppdb_seat_allocations')->insertGetId([
        'user_id' => $owner->id, 'payment_id' => null, 'jenjang' => 'SD', 'status' => 'ALLOCATED',
        'allocated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    return [$owner, $invoiceId, $payment->id, $allocationId];
}

function resetRaceData(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    DB::table('du_agreement_acceptances')->delete();
    DB::table('du_withdrawals')->delete();
    DaftarUlangInvoice::query()->delete();
    Payment::query()->delete();
    PpdbSeatAllocation::query()->delete();
    DB::table('du_pricing_revisions')->where('pricing_key', 'SD')->where('revision', 996)->delete();
    User::where('email', 'like', '%@du-cvw-race.test')->delete();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
}

$fail = false;

// =========================================================================
// SCENARIO A: withdrawal commits FIRST -> continuation must be rejected.
// =========================================================================
echo "\n=== SCENARIO A: withdrawal commits first ===\n";
resetRaceData();
[$owner, $invoiceId, $paymentId, $allocationId] = makeEligibleStudent('a@du-cvw-race.test');
$uid = $owner->id;

$cfg = config('database.connections.mysql');
$dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}";
$connW = new PDO($dsn, $cfg['username'], $cfg['password']);
$connW->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connW->beginTransaction();
$stmt = $connW->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
$stmt->execute([$uid]);
echo 'W: locked users row id='.$stmt->fetchColumn()." (FOR UPDATE)\n";

// Continuation worker blocks on the users lock, then sees MENGUNDURKAN_DIRI.
$contScript = tempnam(sys_get_temp_dir(), 'ducvw').'.php';
file_put_contents($contScript, '<?php
require '.var_export(__DIR__.'/../../vendor/autoload.php', true).';
$app = require '.var_export(__DIR__.'/../../bootstrap/app.php', true).';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$uid = (int) '.var_export($uid, true).';
DB::transaction(function () use ($uid) {
    $user = DB::table("users")->where("id", $uid)->lockForUpdate()->first();
    if ($user->status_siswa === "TERDAFTAR") { echo "REPLAY"; return; }
    if ($user->status_siswa === "MENGUNDURKAN_DIRI") { echo "REJECTED"; return; }
    $inv = DB::table("du_invoices")->where("user_id", $uid)->orderBy("id")->lockForUpdate()->first();
    if ($inv->withdrawn_at !== null) { echo "REJECTED"; return; }
    $seat = DB::table("ppdb_seat_allocations")->where("user_id", $uid)->where("status", "ALLOCATED")->orderBy("id")->lockForUpdate()->first();
    if (!$seat) { echo "REJECTED"; return; }
    DB::table("users")->where("id", $uid)->update(["status_siswa" => "TERDAFTAR", "continued_at" => now(), "updated_at" => now()]);
    echo "CONTINUED";
});
');
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$procC = proc_open(['php', $contScript], $descriptors, $pipesC);
sleep(1);
echo "C: continuation worker started (blocked on users FOR UPDATE)\n";

// W completes the withdrawal (users status + invoice + seat) and commits.
$now = now();
$connW->prepare('UPDATE users SET status_siswa = ?, updated_at = ? WHERE id = ?')->execute(['MENGUNDURKAN_DIRI', $now, $uid]);
$connW->prepare('UPDATE du_invoices SET withdrawn_at = ?, updated_at = ? WHERE id = ?')->execute([$now, $now, $invoiceId]);
$connW->prepare('UPDATE ppdb_seat_allocations SET status = ?, released_at = ?, release_reason = ?, updated_at = ? WHERE id = ?')
    ->execute(['RELEASED', $now, 'WITHDRAWN', $now, $allocationId]);
$connW->commit();
echo "W: committed MENGUNDURKAN_DIRI (withdrawal)\n";

$cOut = trim(stream_get_contents($pipesC[1]));
fclose($pipesC[1]); fclose($pipesC[2]); proc_close($procC);
echo "C: completed with '{$cOut}'\n";

$userA = User::find($uid);
$invoiceA = DB::table('du_invoices')->where('id', $invoiceId)->first();
$seatA = DB::table('ppdb_seat_allocations')->where('id', $allocationId)->first();
$checks = [
    'C rejected continuation (REJECTED)' => $cOut === 'REJECTED',
    'final status_siswa = MENGUNDURKAN_DIRI' => $userA->status_siswa === 'MENGUNDURKAN_DIRI',
    'continued_at stayed NULL' => $userA->continued_at === null,
    'invoice withdrawn' => $invoiceA->withdrawn_at !== null,
    'seat RELEASED' => $seatA->status === 'RELEASED',
];
foreach ($checks as $label => $ok) { echo ($ok ? 'PASS ' : 'FAIL ').$label.PHP_EOL; if (!$ok) $fail = true; }
@unlink($contScript);

// =========================================================================
// SCENARIO B: continuation commits FIRST -> withdrawal must be rejected.
// =========================================================================
echo "\n=== SCENARIO B: continuation commits first ===\n";
resetRaceData();
[$owner, $invoiceId, $paymentId, $allocationId] = makeEligibleStudent('b@du-cvw-race.test');
$uid = $owner->id;

$connC = new PDO($dsn, $cfg['username'], $cfg['password']);
$connC->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connC->beginTransaction();
$stmt = $connC->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
$stmt->execute([$uid]);
echo 'C: locked users row id='.$stmt->fetchColumn()." (FOR UPDATE)\n";

// Withdrawal worker blocks on the users lock, then sees TERDAFTAR.
$wdScript = tempnam(sys_get_temp_dir(), 'ducvw').'.php';
file_put_contents($wdScript, '<?php
require '.var_export(__DIR__.'/../../vendor/autoload.php', true).';
$app = require '.var_export(__DIR__.'/../../bootstrap/app.php', true).';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$uid = (int) '.var_export($uid, true).';
DB::transaction(function () use ($uid) {
    $user = DB::table("users")->where("id", $uid)->lockForUpdate()->first();
    if ($user->status_siswa === "TERDAFTAR") { echo "REJECTED"; return; }
    $inv = DB::table("du_invoices")->where("user_id", $uid)->orderBy("id")->lockForUpdate()->first();
    if ($inv->withdrawn_at !== null) { echo "REPLAY"; return; }
    DB::table("users")->where("id", $uid)->update(["status_siswa" => "MENGUNDURKAN_DIRI", "updated_at" => now()]);
    DB::table("du_invoices")->where("id", $inv->id)->update(["withdrawn_at" => now(), "updated_at" => now()]);
    DB::table("ppdb_seat_allocations")->where("user_id", $uid)->where("status", "ALLOCATED")->update(["status" => "RELEASED", "released_at" => now(), "release_reason" => "WITHDRAWN", "updated_at" => now()]);
    echo "WITHDREW";
});
');
$procW = proc_open(['php', $wdScript], $descriptors, $pipesW);
sleep(1);
echo "W: withdrawal worker started (blocked on users FOR UPDATE)\n";

// C commits the TERDAFTAR transition.
$now = now();
$connC->prepare('UPDATE users SET status_siswa = ?, continued_at = ?, updated_at = ? WHERE id = ?')
    ->execute(['TERDAFTAR', $now, $now, $uid]);
$connC->commit();
echo "C: committed TERDAFTAR transition\n";

$wOut = trim(stream_get_contents($pipesW[1]));
fclose($pipesW[1]); fclose($pipesW[2]); proc_close($procW);
echo "W: completed with '{$wOut}'\n";

$userB = User::find($uid);
$invoiceB = DB::table('du_invoices')->where('id', $invoiceId)->first();
$seatB = DB::table('ppdb_seat_allocations')->where('id', $allocationId)->first();
$checks = [
    'W rejected withdrawal (REJECTED)' => $wOut === 'REJECTED',
    'final status_siswa = TERDAFTAR' => $userB->status_siswa === 'TERDAFTAR',
    'continued_at set' => $userB->continued_at !== null,
    'invoice NOT withdrawn' => $invoiceB->withdrawn_at === null,
    'seat still ALLOCATED' => $seatB->status === 'ALLOCATED',
];
foreach ($checks as $label => $ok) { echo ($ok ? 'PASS ' : 'FAIL ').$label.PHP_EOL; if (!$ok) $fail = true; }
@unlink($wdScript);

// ---------------------------------------------------------------- cleanup ----
resetRaceData();
echo "\ncleanup done\n";
exit($fail ? 1 : 0);
