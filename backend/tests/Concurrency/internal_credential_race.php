<?php

/**
 * Deterministic concurrency proof for INTERNAL candidate registration:
 * TWO simultaneous requests using the SAME 10-digit registration_number.
 *
 * Why a standalone script (not PHPUnit): the test harness wraps each test in a
 * rolled-back transaction, so two independent connections cannot contend on a
 * real InnoDB row lock inside `php artisan test`. This script uses REAL MySQL
 * sessions, real commits, real locks.
 *
 * Scenario — one unused candidate; two connections race to consume it:
 *   Conn A: locks the whitelist row FOR UPDATE, creates user, sets
 *           is_used=1 + user_id, HOLDS the lock, then commits.
 *   Conn B: while A holds the lock, runs the SAME
 *           SELECT ... WHERE registration_number=? AND is_used=0 FOR UPDATE.
 *           InnoDB serialises B — it blocks until A commits, then re-reads and
 *           finds is_used=1, so B's WHERE matches NOTHING and B cannot proceed.
 *
 * Deterministic expected outcome:
 *   - exactly ONE user is created for the candidate
 *   - the whitelist row ends is_used=1 with exactly one user_id
 *   - the loser finds no consumable row (registration refused)
 *
 * Run with:  APP_ENV=testing php backend/tests/Concurrency/internal_credential_race.php
 *
 * SAFETY: the guard below refuses to run unless APP_ENV=testing AND the live
 * connection resolves to the disposable ppdb_sekolah_test database. See
 * ConcurrencySafetyGuard.php. Never run this against ppdb_sekolah.
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Fail closed BEFORE the destructive setup below can touch any database.
require_once __DIR__.'/ConcurrencySafetyGuard.php';
assert_disposable_concurrency_target();

use App\Models\User;
use App\Models\Whitelist;
use Illuminate\Support\Facades\DB;

echo 'DB driver: '.DB::connection()->getDriverName().PHP_EOL;

// ---------------------------------------------------------------- setup ----
DB::statement('SET FOREIGN_KEY_CHECKS=0');
Whitelist::where('registration_number', '5550001111')->delete();
User::where('email', 'like', '%@cred-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$cand = Whitelist::create([
    'registration_number' => '5550001111', 'nama_siswa' => 'Race Candidate', 'jenjang' => 'SD',
    'asal_sekolah' => 'TK IT Al-Mubarak', 'is_used' => false,
]);
$number = $cand->registration_number;
echo "candidate registration_number=$number is_used=0\n";

// ------------------------------------------------- Connection A: lock + win ----
$connA = DB::connection();
$connA->beginTransaction();
$rowA = $connA->table('whitelists')
    ->where('registration_number', $number)->where('is_used', false)
    ->lockForUpdate()->first();
echo "A: locked candidate row (is_used={$rowA->is_used}), creating user + holding lock...\n";

$userA = User::create([
    'name' => 'A Parent', 'email' => 'a@cred-race.test',
    'password' => bcrypt('Secret123'), 'jalur' => 'INTERNAL', 'jenjang' => 'SD',
]);
$userA->forceFill(['role' => 'orang_tua'])->save();
$connA->table('whitelists')->where('id', $cand->id)->update(['is_used' => true, 'user_id' => $userA->id]);

// --------------------------- Connection B: compete for the same credential ----
$config = config('database.connections.mysql');
$pdoB = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};port={$config['port']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdoB->exec('SET SESSION innodb_lock_wait_timeout = 5');

$bOutcome = null;
$bStart = microtime(true);
try {
    $pdoB->exec('START TRANSACTION');
    // B's identical lookup FOR UPDATE blocks until A commits.
    $stmt = $pdoB->prepare(
        'SELECT id, is_used FROM whitelists WHERE registration_number = ? AND is_used = 0 FOR UPDATE'
    );
    $stmt->execute([$number]);
    $rowB = $stmt->fetch(PDO::FETCH_ASSOC);
    $waited = round(microtime(true) - $bStart, 3);

    if ($rowB === false) {
        $bOutcome = 'NO_ROW';
        echo "B: waited {$waited}s for A's lock; re-read matches NOTHING (is_used already 1) => refused\n";
    } else {
        $bOutcome = 'DOUBLE_CONSUME';
        echo "B: matched a consumable row (id={$rowB['id']}) AFTER A consumed => RACE!\n";
    }
    $pdoB->exec('ROLLBACK');
} catch (PDOException $e) {
    $waited = round(microtime(true) - $bStart, 3);
    $bOutcome = 'BLOCKED';
    echo "B: blocked on A's lock {$waited}s — serialised ({$e->getMessage()})\n";
}

// A commits (releasing the lock so B resolves).
$connA->commit();
echo "A: committed (user_id={$userA->id}, is_used=1)\n";

// ------------------------------------------------------------ final state ----
$fresh = Whitelist::find($cand->id);
$usersCreated = User::where('email', 'like', '%@cred-race.test')->count();
echo PHP_EOL;
echo "FINAL: whitelist is_used={$fresh->is_used} user_id={$fresh->user_id} users_created={$usersCreated}\n";

$passed = (int)$fresh->is_used === 1
    && (int)$fresh->user_id === (int)$userA->id
    && $usersCreated === 1
    && in_array($bOutcome, ['NO_ROW', 'BLOCKED'], true);

echo $passed ? "INTERNAL-CREDENTIAL RACE PROOF PASSED\n" : "INTERNAL-CREDENTIAL RACE PROOF FAILED\n";

// ------------------------------------------------------------- cleanup ------
DB::statement('SET FOREIGN_KEY_CHECKS=0');
Whitelist::where('registration_number', '5550001111')->delete();
User::where('email', 'like', '%@cred-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo "cleanup done\n";

exit($passed ? 0 : 1);
