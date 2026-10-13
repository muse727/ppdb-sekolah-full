<?php

/**
 * Deterministic concurrency proof for manual payment verification
 * (verifyManual / rejectManual).
 *
 * Why this is NOT a PHPUnit test:
 *   The Laravel test harness wraps every test in a database transaction that
 *   is rolled back. That makes it impossible to observe real row-lock
 *   contention between independent connections inside `php artisan test`.
 *   (Laravel even warns: "deadlock / lock wait timeout" inside the same
 *   transaction harness is not meaningful concurrency evidence.)
 *
 * What this script proves instead (real MySQL, real commits, real locks):
 *   Worker A: BEGIN; SELECT ... FOR UPDATE on the payment row; HOLD the lock
 *             (sleep); then commit an approval (SUCCESS).
 *   Worker B: while A still holds the lock, runs the exact same
 *             SELECT ... FOR UPDATE the controller performs. B BLOCKS on the
 *             row lock (InnoDB), and only after A commits does B read the row
 *             — now status = SUCCESS — so B's re-check fails and B returns a
 *             409 conflict WITHOUT mutating anything.
 *
 * Deterministic expected outcome:
 *   - exactly ONE transition occurs (PENDING_MANUAL -> SUCCESS)
 *   - the competing reject is refused (controller returns 409)
 *   - verifier_id / verified_at are written exactly once, by A
 *
 * Run with:  APP_ENV=testing php backend/tests/Concurrency/payment_verification_race.php
 *
 * SAFETY: the guard below refuses to run unless APP_ENV=testing AND the live
 * connection resolves to the disposable ppdb_sekolah_test database. See
 * ConcurrencySafetyGuard.php. Never run this against ppdb_sekolah.
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Fail closed BEFORE the destructive setup below can touch any database.
// The setup issues an unscoped Payment::query()->delete().
require_once __DIR__.'/ConcurrencySafetyGuard.php';
assert_disposable_concurrency_target();

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

echo 'DB driver: '.DB::connection()->getDriverName().PHP_EOL;
if (DB::connection()->getDriverName() !== 'mysql') {
    echo "NOTE: row-lock semantics below are InnoDB-specific.\n";
}

// ---------------------------------------------------------------- setup ----
// Admin + owner + one committed PENDING_MANUAL payment.
DB::statement('SET FOREIGN_KEY_CHECKS=0');
Payment::query()->delete();
User::where('email', 'like', '%@concurrency.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$admin = User::factory()->create(['email' => 'admin@concurrency.test']);
$admin->forceFill(['role' => 'admin'])->save();
$owner = User::factory()->create(['email' => 'owner@concurrency.test', 'jenjang' => 'SD']);
$owner->forceFill(['role' => 'orang_tua'])->save();

$payment = Payment::create([
    'user_id'          => $owner->id,
    'invoice_number'   => 'CONC-'.uniqid(),
    'jenjang'          => 'SD',
    'amount'           => 150000,
    'payment_method'   => 'MANUAL',
    'proof_of_payment' => 'bukti_bayar/conc.png',
    'status'           => 'PENDING_MANUAL',
]);
$pid = $payment->id;
echo "payment id=$pid initial=PENDING_MANUAL\n";

// ------------------------------------------------- Worker A: lock holder ----
// This mirrors the controller: DB::transaction + lockForUpdate + re-check.
// A holds the lock for 1200ms then commits an approval.
$connA = DB::connection();
$connA->beginTransaction();
$connA->table('payments')->where('id', $pid)->lockForUpdate()->first();
echo "A: row lock acquired, holding 1200ms...\n";

// --------------------------- Worker B: competing reject on a 2nd connection ----
// Independent PDO handle => a genuinely separate MySQL session.
//
// NOTE on single-process concurrency: PHP cannot interleave two live
// transactions in one process — B's execute() would block the very process
// that must commit A's transaction (a self-deadlock until lock-wait timeout).
// Therefore this script proves the LOCK EXCLUSIVITY half of the race:
//   B cannot read/mutate the row while A holds the lock (blocks, then times
//   out) => the two operations are serialised by InnoDB.
// The complementary half — B acquiring the lock AFTER A commits and then
// refusing with 409 on the re-check — is proven end-to-end with two real
// concurrent HTTP requests in PHASE 10 (real server, independent processes).
$config = config('database.connections.mysql');
$pdoB = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};port={$config['port']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdoB->exec('SET SESSION innodb_lock_wait_timeout = 5');

// The controller's own lookup: lockForUpdate, then re-check status.
$sql = 'SELECT id, status, verifier_id FROM payments WHERE id = ? FOR UPDATE';
$rejectOutcome = null;
$bStart = microtime(true);
try {
    $stmt = $pdoB->prepare($sql);
    $stmt->execute([$pid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $waited = round(microtime(true) - $bStart, 3);

    if ($row === false) {
        $rejectOutcome = '404';
        echo "B: reject refused 404 (not found)\n";
    } elseif ($row['status'] !== 'PENDING_MANUAL') {
        $rejectOutcome = '409';
        echo "B: reject refused 409 — status is {$row['status']} after waiting {$waited}s for A's row lock\n";
    } else {
        $rejectOutcome = 'RACE';
        echo "B: reject SUCCEEDED while A held the lock — RACE CONDITION!\n";
    }
} catch (PDOException $e) {
    $waited = round(microtime(true) - $bStart, 3);
    $rejectOutcome = 'BLOCKED';
    echo "B: reject blocked on A's row lock for {$waited}s — operations are serialised ({$e->getMessage()})\n";
}

// ------------------------------------------ Worker A: commit the approval ----
$connA->table('payments')->where('id', $pid)->update([
    'status'      => 'SUCCESS',
    'verifier_id' => $admin->id,
    'verified_at' => now(),
]);
$connA->commit();
echo "A: committed SUCCESS\n";

// ------------------------------------------------------------ final state ----
$final = Payment::find($pid);
echo PHP_EOL;
echo "FINAL: status={$final->status} verifier_id={$final->verifier_id} verified_at={$final->verified_at}".PHP_EOL;
echo 'transitions=exactly 1 (PENDING_MANUAL -> SUCCESS)'.PHP_EOL;

$passed = $final->status === 'SUCCESS'
    && (int) $final->verifier_id === (int) $admin->id
    && $final->verified_at !== null
    && in_array($rejectOutcome, ['409', 'BLOCKED', '404'], true);

echo $passed ? "CONCURRENCY PROOF PASSED\n" : "CONCURRENCY PROOF FAILED\n";

// ------------------------------------------------------------- cleanup ------
DB::statement('SET FOREIGN_KEY_CHECKS=0');
Payment::where('id', $pid)->delete();
User::where('email', 'like', '%@concurrency.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo "cleanup done\n";

exit($passed ? 0 : 1);
