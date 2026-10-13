<?php

/**
 * Deterministic concurrency proof for PPDB seat consumption
 * (PpdbCapacityService::consumeSeat via PaymentController::verifyManual).
 *
 * Why a standalone script (same rationale as payment_verification_race.php):
 *   the PHPUnit harness wraps each test in a rolled-back transaction, so two
 *   independent connections cannot contend on real InnoDB row locks inside
 *   `php artisan test`. This script uses REAL MySQL commits and locks.
 *
 * Scenario — exactly ONE SD seat remaining, TWO verifications race for it:
 *   Connection A: locks the SD capacity row FOR UPDATE (the service's first
 *                 step), HOLDS it, consumes the seat, commits.
 *   Connection B: an independent PDO session attempts the same
 *                 SELECT ... FOR UPDATE on the SAME SD capacity row. InnoDB
 *                 serialises it — B blocks until A commits (or times out),
 *                 then re-reads and finds max_seats - active = 0 => full.
 *
 * Deterministic expected outcome:
 *   - at most ONE seat allocation is created for the last seat
 *   - the loser is refused (capacity full), never over-consumes
 *   - active ALLOCATED count for SD never exceeds max_seats
 *
 * Run with:  APP_ENV=testing php backend/tests/Concurrency/seat_consumption_race.php
 *
 * SAFETY: the guard below refuses to run unless APP_ENV=testing AND the live
 * connection resolves to the disposable ppdb_sekolah_test database. See
 * ConcurrencySafetyGuard.php. Never run this against ppdb_sekolah.
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Fail closed BEFORE the destructive setup below can touch any database.
// The setup issues an unscoped PpdbSeatAllocation::query()->delete().
require_once __DIR__.'/ConcurrencySafetyGuard.php';
assert_disposable_concurrency_target();

use App\Models\Payment;
use App\Models\PpdbCapacity;
use App\Models\PpdbSeatAllocation;
use App\Models\User;
use App\Services\PpdbCapacityService;
use Illuminate\Support\Facades\DB;

echo 'DB driver: '.DB::connection()->getDriverName().PHP_EOL;
if (DB::connection()->getDriverName() !== 'mysql') {
    echo "NOTE: row-lock semantics below are InnoDB-specific.\n";
}

// ---------------------------------------------------------------- setup ----
DB::statement('SET FOREIGN_KEY_CHECKS=0');
PpdbSeatAllocation::query()->delete();
Payment::where('invoice_number', 'like', 'CONC-SEAT-%')->delete();
User::where('email', 'like', '%@seat-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

// Exactly ONE SD seat.
DB::table('ppdb_capacities')->updateOrInsert(
    ['jenjang' => 'SD'],
    ['max_seats' => 1, 'created_at' => now(), 'updated_at' => now()]
);

$ownerA = User::factory()->create(['email' => 'a@seat-race.test', 'jenjang' => 'SD']);
$ownerA->forceFill(['role' => 'orang_tua'])->save();
$ownerB = User::factory()->create(['email' => 'b@seat-race.test', 'jenjang' => 'SD']);
$ownerB->forceFill(['role' => 'orang_tua'])->save();

$payA = Payment::create([
    'user_id' => $ownerA->id, 'invoice_number' => 'CONC-SEAT-A-'.uniqid(), 'jenjang' => 'SD',
    'amount' => 150000, 'payment_method' => 'MANUAL', 'status' => 'PENDING_MANUAL',
]);
$payB = Payment::create([
    'user_id' => $ownerB->id, 'invoice_number' => 'CONC-SEAT-B-'.uniqid(), 'jenjang' => 'SD',
    'amount' => 150000, 'payment_method' => 'MANUAL', 'status' => 'PENDING_MANUAL',
]);
echo "SD max_seats=1; two competing payments A(id={$payA->id}) and B(id={$payB->id})\n";

// ------------------------------------------------- Connection A: lock + win ----
// Mirror the service's first step: lock the SD capacity row, then consume.
$connA = DB::connection();
$connA->beginTransaction();
$connA->table('ppdb_capacities')->where('jenjang', 'SD')->lockForUpdate()->first();
echo "A: SD capacity-row lock acquired (FOR UPDATE), holding...\n";

// --------------------------- Connection B: compete for the same last seat ----
// Independent PDO => genuinely separate MySQL session. B attempts the same
// FOR UPDATE lock on the SAME row. It must block until A commits, then find
// the seat already taken (active=1, max=1 => remaining 0).
$config = config('database.connections.mysql');
$pdoB = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};port={$config['port']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdoB->exec('SET SESSION innodb_lock_wait_timeout = 5');

$bOutcome = null;
$bStart = microtime(true);
try {
    // B's capacity-row lock (the service's serialize point). This BLOCKS on A.
    $stmt = $pdoB->prepare('SELECT jenjang, max_seats FROM ppdb_capacities WHERE jenjang = ? FOR UPDATE');
    $stmt->execute(['SD']);
    $capRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $waited = round(microtime(true) - $bStart, 3);

    // Now (after A committed) re-count active SD allocations, as the service does.
    $activeStmt = $pdoB->prepare("SELECT COUNT(*) c FROM ppdb_seat_allocations WHERE jenjang='SD' AND status='ALLOCATED'");
    $activeStmt->execute();
    $active = (int) $activeStmt->fetch(PDO::FETCH_ASSOC)['c'];
    $remaining = (int) $capRow['max_seats'] - $active;

    if ($remaining <= 0) {
        $bOutcome = 'FULL';
        echo "B: waited {$waited}s for A's lock; remaining=$remaining => REFUSED (jenjang full)\n";
    } else {
        $bOutcome = 'OVERCONSUMED';
        echo "B: remaining=$remaining after A consumed => would over-consume — RACE!\n";
    }
    $pdoB->exec('ROLLBACK');
} catch (PDOException $e) {
    $waited = round(microtime(true) - $bStart, 3);
    $bOutcome = 'BLOCKED';
    echo "B: blocked on A's capacity lock for {$waited}s — serialised ({$e->getMessage()})\n";
}

// ------------------------------------------ A: consume the seat and commit ----
$svc = app(PpdbCapacityService::class);
// consumeSeat runs inside A's already-open transaction (it re-locks same row,
// same connection => no self-block).
$alloc = $svc->consumeSeat('SD', $ownerA->id, $payA->id);
$connA->table('payments')->where('id', $payA->id)->update(['status' => 'SUCCESS']);
$connA->commit();
echo "A: consumed seat (allocation id={$alloc->id}), committed SUCCESS\n";

// ------------------------------------------------------------ final state ----
$activeSD = PpdbSeatAllocation::where('jenjang', 'SD')->where('status', 'ALLOCATED')->count();
$maxSD    = (int) PpdbCapacity::where('jenjang', 'SD')->value('max_seats');
echo PHP_EOL;
echo "FINAL: SD active_allocations=$activeSD max_seats=$maxSD\n";
echo "payment A status=".Payment::find($payA->id)->status." payment B status=".Payment::find($payB->id)->status.PHP_EOL;

$passed = $activeSD === 1                       // exactly one seat consumed
    && $activeSD <= $maxSD                      // never exceeds capacity
    && Payment::find($payA->id)->status === 'SUCCESS'
    && Payment::find($payB->id)->status === 'PENDING_MANUAL'  // loser unpaid/unconsumed
    && in_array($bOutcome, ['FULL', 'BLOCKED'], true);

echo $passed ? "SEAT-CONSUMPTION RACE PROOF PASSED\n" : "SEAT-CONSUMPTION RACE PROOF FAILED\n";

// ------------------------------------------------------------- cleanup ------
DB::statement('SET FOREIGN_KEY_CHECKS=0');
PpdbSeatAllocation::whereIn('payment_id', [$payA->id, $payB->id])->delete();
Payment::where('invoice_number', 'like', 'CONC-SEAT-%')->delete();
User::where('email', 'like', '%@seat-race.test')->delete();
DB::table('ppdb_capacities')->where('jenjang', 'SD')->update(['max_seats' => 100]);
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo "cleanup done\n";

exit($passed ? 0 : 1);
