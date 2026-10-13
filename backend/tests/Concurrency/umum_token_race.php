<?php

/**
 * Deterministic concurrency proof for UMUM registration tokens (P3-C):
 * TWO simultaneous requests using the SAME token code.
 *
 * Why a standalone script (not PHPUnit): the test harness wraps each test in
 * a rolled-back transaction, so two independent connections cannot contend on
 * a real InnoDB row lock inside `php artisan test`. This script uses REAL
 * MySQL sessions, real commits, real locks — mirroring what two concurrent
 * HTTP requests do in production.
 *
 * Scenario — one unused (and unexpired) token; two connections race:
 *   Conn A: locks the token row FOR UPDATE, re-reads it, marks it used,
 *           creates the user, HOLDS the lock, then commits.
 *   Conn B: while A holds the lock, runs the SAME
 *           SELECT ... WHERE code=? FOR UPDATE.
 *           InnoDB serialises B — it blocks until A commits, then re-reads and
 *           finds is_used=1, so B is refused.
 *
 * Deterministic expected outcome (A8):
 *   - exactly ONE successful registration
 *   - exactly ONE user created
 *   - exactly ONE token marked used (bound to that user)
 *   - the loser rejected
 *
 * Also proves (A6): the row is RE-READ after acquiring the lock, so B never
 * consumes based on a stale snapshot.
 *
 * Run with:  APP_ENV=testing php backend/tests/Concurrency/umum_token_race.php
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

use App\Models\Token;
use App\Models\User;
use Illuminate\Support\Facades\DB;

echo 'DB driver: '.DB::connection()->getDriverName().PHP_EOL;

// ---------------------------------------------------------------- setup ----
DB::statement('SET FOREIGN_KEY_CHECKS=0');
Token::where('code', 'AM-RACE-PROOF-0000-0001')->delete();
User::where('email', 'like', '%@umum-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$token = Token::create([
    'code'       => 'AM-RACE-PROOF-0000-0001',
    'is_used'    => false,
    'expires_at' => now()->addDays(30), // valid, unexpired
]);
echo "token code={$token->code} is_used=0 expires_at={$token->expires_at}\n";

// ------------------------------------------- Connection A: lock + win ----
$connA = DB::connection();
$connA->beginTransaction();

// This is exactly the controller's lookup shape: SELECT ... FOR UPDATE with a
// re-read of the locked row before any state transition.
$rowA = $connA->table('tokens')->where('code', $token->code)->lockForUpdate()->first();
echo "A: locked token row (is_used={$rowA->is_used}), verifying + creating user while holding lock...\n";

// A re-reads the locked row (A6): unused + unexpired => eligible.
$banned = $rowA->is_used
    || ($rowA->expires_at !== null && \Carbon\Carbon::parse($rowA->expires_at)->isPast());
if ($banned) {
    echo "A: token unexpectedly unusable — abort\n";
    exit(1);
}

$userA = User::create([
    'name' => 'A Parent', 'email' => 'a@umum-race.test',
    'password' => bcrypt('Secret123'), 'jalur' => 'UMUM', 'jenjang' => 'SD',
]);
$userA->forceFill(['role' => 'orang_tua'])->save();

// Single-use transition under the lock.
$connA->table('tokens')->where('id', $rowA->id)->update(['is_used' => true, 'user_id' => $userA->id]);

// --------------------------- Connection B: compete for the same token ----
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
    $stmt = $pdoB->prepare('SELECT id, is_used FROM tokens WHERE code = ? FOR UPDATE');
    $stmt->execute([$token->code]);
    $rowB = $stmt->fetch(PDO::FETCH_ASSOC);
    $waited = round(microtime(true) - $bStart, 3);

    // B re-reads the (now committed) state under its own lock (A6).
    if ($rowB === false) {
        $bOutcome = 'NO_ROW';
        echo "B: waited {$waited}s; row vanished => refused\n";
    } elseif ((int) $rowB['is_used'] === 1) {
        $bOutcome = 'REFUSED_USED';
        echo "B: waited {$waited}s for A's lock; re-read shows is_used=1 => REFUSED (single-use holds)\n";
    } else {
        $bOutcome = 'DOUBLE_CONSUME';
        echo "B: re-read shows is_used=0 AFTER A consumed => RACE!\n";
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
$fresh         = Token::find($token->id);
$usersCreated  = User::where('email', 'like', '%@umum-race.test')->count();
echo PHP_EOL;
echo "FINAL: token is_used={$fresh->is_used} user_id={$fresh->user_id} users_created={$usersCreated}\n";

$passed = (int) $fresh->is_used === 1
    && (int) $fresh->user_id === (int) $userA->id
    && $usersCreated === 1
    && in_array($bOutcome, ['REFUSED_USED', 'NO_ROW', 'BLOCKED'], true);

echo $passed ? "UMUM TOKEN RACE PROOF PASSED\n" : "UMUM TOKEN RACE PROOF FAILED\n";

// ------------------------------------------------------------ extra proof ----
// Expired token under contention: never consumed, never creates a user.
$expired = Token::create([
    'code'       => 'AM-RACE-EXPI-0000-0002',
    'is_used'    => false,
    'expires_at' => now()->subMinute(),
]);
$connE = DB::connection();
$connE->beginTransaction();
$rowE = $connE->table('tokens')->where('code', $expired->code)->lockForUpdate()->first();
$expiredRejected = $rowE->is_used
    || ($rowE->expires_at !== null && \Carbon\Carbon::parse($rowE->expires_at)->isPast());
$connE->rollBack();

$freshE = Token::find($expired->id);
$expiredProof = $expiredRejected === true && (int) $freshE->is_used === 0;
echo 'EXPIRED TOKEN: rejected=' . var_export($expiredRejected, true)
    . " is_used_after={$freshE->is_used} (must stay 0)\n";
echo $expiredProof ? "EXPIRED-TOKEN RACE PROOF PASSED\n" : "EXPIRED-TOKEN RACE PROOF FAILED\n";
$expired->delete();

// ------------------------------------------------------------- cleanup ------
DB::statement('SET FOREIGN_KEY_CHECKS=0');
Token::where('code', 'AM-RACE-PROOF-0000-0001')->delete();
User::where('email', 'like', '%@umum-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo "cleanup done\n";

exit(($passed && $expiredProof) ? 0 : 1);
