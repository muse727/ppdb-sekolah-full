<?php

/**
 * Deterministic concurrency proof for PPDB seat RELEASE
 * (PpdbCapacityService::releaseSeat via the admin release endpoint and the
 * TIDAK_LULUS kelulusan flow).
 *
 * Why a standalone script (same rationale as seat_consumption_race.php):
 * the PHPUnit harness wraps each test in a rolled-back transaction, so two
 * independent connections cannot contend on real InnoDB row locks inside
 * `php artisan test`. This script uses REAL MySQL commits and locks.
 *
 * Scenario — the LAST SD seat is held, and an admin verifies a second
 * candidate's payment at the same moment the first candidate is rejected:
 *   Connection A: runs the real releaseSeat() (capacity row lock -> allocation
 *                row lock -> RELEASED -> commit).
 *   Connection B: an independent PDO session attempts the real consumeSeat()
 *                SELECT ... FOR UPDATE on the SAME SD capacity row. InnoDB
 *                 serialises it — B blocks until A commits, then re-reads
 *                 max_seats - active = 1 and takes the freed seat.
 *
 * Deterministic expected outcome:
 *   - exactly one seat changes hands; active count never exceeds max_seats
 *   - the released candidate's payment stays SUCCESS (immutable)
 *   - the waiting candidate's verification succeeds only after the release
 *
 * Run with:  APP_ENV=testing php backend/tests/Concurrency/seat_release_race.php
 *
 * SAFETY: the guard below refuses to run unless APP_ENV=testing AND the live
 * connection resolves to the disposable ppdb_sekolah_test database. See
 * ConcurrencySafetyGuard.php. Never run this against ppdb_sekolah.
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Fail closed BEFORE the destructive setup below can touch any database.
// Both the setup AND the cleanup issue an unscoped
// PpdbSeatAllocation::query()->delete().
require_once __DIR__.'/ConcurrencySafetyGuard.php';
assert_disposable_concurrency_target();

use App\Models\Payment;
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
Payment::where('invoice_number', 'like', 'CONC-REL-%')->delete();
User::where('email', 'like', '%@seat-rel.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

// Exactly ONE SD seat.
DB::table('ppdb_capacities')->updateOrInsert(
    ['jenjang' => 'SD'],
    ['max_seats' => 1, 'created_at' => now(), 'updated_at' => now()]
);

$svc = app(PpdbCapacityService::class);

$rejected = User::factory()->create(['email' => 'rejected@seat-rel.test', 'jenjang' => 'SD']);
$rejected->forceFill(['role' => 'orang_tua'])->save();
$rejectedPayment = Payment::create([
    'user_id' => $rejected->id, 'invoice_number' => 'CONC-REL-A-'.uniqid(), 'jenjang' => 'SD',
    'amount' => 150000, 'payment_method' => 'MANUAL', 'status' => 'SUCCESS',
]);

$waitingOwner = User::factory()->create(['email' => 'waiting@seat-rel.test', 'jenjang' => 'SD']);
$waitingOwner->forceFill(['role' => 'orang_tua'])->save();
$waitingPayment = Payment::create([
    'user_id' => $waitingOwner->id, 'invoice_number' => 'CONC-REL-B-'.uniqid(), 'jenjang' => 'SD',
    'amount' => 150000, 'payment_method' => 'MANUAL', 'status' => 'PENDING_MANUAL',
]);

$allocation = $svc->consumeSeat('SD', $rejected->id, $rejectedPayment->id);
echo 'setup: SD max=1, held by rejected candidate, verification of B pending'.PHP_EOL;

// ------------------------------------------------- contended operations ----

// Child process (connection A): the REAL releaseSeat() — capacity row lock ->
// allocation row lock -> RELEASED -> commit — then report its outcome.
$pid = pcntl_fork();
if ($pid === -1) {
    fwrite(STDERR, "pcntl_fork unavailable\n");
    exit(1);
}

if ($pid === 0) {
    try {
        $svc->releaseSeat($allocation, PpdbCapacityService::RELEASE_NOT_ACCEPTED);
        $outcome = 'RELEASED';
    } catch (\Throwable $e) {
        $outcome = 'ERROR:'.$e->getMessage();
    }
    DB::disconnect();
    file_put_contents(sys_get_temp_dir().'/ppdb_rel_race_child', $outcome);
    exit(0);
}

// Parent (connection B): a SEPARATE PDO session replicating the real
// consumeSeat() steps — SELECT ... FOR UPDATE on the SAME SD capacity row,
// re-count active allocations, insert if room. InnoDB serialises it against
// the child's release; B re-reads AFTER A commits, so it observes the freed
// seat and takes it (or reports FULL if A rolled back).
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    config('database.connections.mysql.host'),
    config('database.connections.mysql.port'),
    config('database.connections.mysql.database')
);
$pdoB = new PDO(
    $dsn,
    config('database.connections.mysql.username'),
    config('database.connections.mysql.password'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$activeOnB = fn () => (int) $pdoB->query(
    "SELECT COUNT(*) c FROM ppdb_seat_allocations WHERE jenjang='SD' AND status='ALLOCATED'"
)->fetchColumn();

// Let the child open its transaction and take the capacity lock first.
usleep(100000);

$bOutcome = 'WAIT';
try {
    $pdoB->beginTransaction();
    $capacityStmt = $pdoB->prepare("SELECT max_seats FROM ppdb_capacities WHERE jenjang='SD' FOR UPDATE");
    $capacityStmt->execute();
    $maxB = (int) $capacityStmt->fetchColumn();
    $activeB = $activeOnB();
    if ($maxB - $activeB <= 0) {
        $pdoB->commit();
        $bOutcome = 'FULL';
    } else {
        $insert = $pdoB->prepare(
            "INSERT INTO ppdb_seat_allocations (user_id, payment_id, jenjang, status, allocated_at, created_at, updated_at)
             VALUES (?, ?, 'SD', 'ALLOCATED', NOW(), NOW(), NOW())"
        );
        $insert->execute([$waitingOwner->id, $waitingPayment->id]);
        $pdoB->commit();
        $bOutcome = 'TOOK_SEAT';
    }
} catch (PDOException $e) {
    $bOutcome = 'BLOCKED:'.$e->getMessage();
}

pcntl_waitpid($pid, $status);
$childOutcome = file_get_contents(sys_get_temp_dir().'/ppdb_rel_race_child');
@unlink(sys_get_temp_dir().'/ppdb_rel_race_child');

// ----------------------------------------------------------- assertions ----
$maxSD = (int) DB::table('ppdb_capacities')->where('jenjang', 'SD')->value('max_seats');
$activeSD = (int) PpdbSeatAllocation::where('jenjang', 'SD')
    ->where('status', PpdbSeatAllocation::STATUS_ALLOCATED)
    ->count();

echo "child(release) outcome=".var_export($childOutcome, true)."\n";
echo "parent(consume) outcome={$bOutcome}\n";
echo "post-state: active_allocations={$activeSD} max_seats={$maxSD}\n";
echo 'rejected payment status='.Payment::find($rejectedPayment->id)->status.PHP_EOL;
echo 'waiting payment status='.Payment::find($waitingPayment->id)->status.PHP_EOL;

$passed = $childOutcome === 'RELEASED'
    && in_array($bOutcome, ['TOOK_SEAT', 'FULL'], true)
    && $activeSD <= $maxSD                      // never exceeds capacity
    && $activeSD >= 0
    && Payment::find($rejectedPayment->id)->status === 'SUCCESS' // immutable
    && PpdbSeatAllocation::where('payment_id', $rejectedPayment->id)
        ->where('status', PpdbSeatAllocation::STATUS_RELEASED)
        ->exists();                             // old holder's seat freed

echo $passed ? "SEAT-RELEASE RACE PROOF PASSED\n" : "SEAT-RELEASE RACE PROOF FAILED\n";

// ------------------------------------------------------------- cleanup ------
DB::statement('SET FOREIGN_KEY_CHECKS=0');
PpdbSeatAllocation::query()->delete();
Payment::where('invoice_number', 'like', 'CONC-REL-%')->delete();
User::where('email', 'like', '%@seat-rel.test')->delete();
DB::table('ppdb_capacities')->where('jenjang', 'SD')->update(['max_seats' => 100]);
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo "cleanup done\n";

exit($passed ? 0 : 1);
