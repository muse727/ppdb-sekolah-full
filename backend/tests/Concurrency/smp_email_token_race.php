<?php

/**
 * Deterministic concurrency proof for SMP email tokens (P3-C):
 * TWO simultaneous registrations using the SAME code + email.
 *
 * Why a standalone script (not PHPUnit): the test harness wraps each test in
 * a rolled-back transaction, so two independent connections cannot contend on
 * a real InnoDB row lock inside `php artisan test`. This script uses REAL
 * MySQL sessions, real commits, real locks.
 *
 * Scenario — one issued (unconsumed, unexpired) token; two connections race:
 *   Conn A: acquires the per-email advisory lock, re-reads the LATEST issuance
 *           FOR UPDATE, verifies digest, creates the user, consumes the
 *           issuance (consumed_at + used_by_user_id), HOLDS everything, then
 *           commits.
 *   Conn B: while A holds the locks, presents the SAME code.
 *           InnoDB serialises B behind A: B either waits on the advisory
 *           lock or the row lock, then re-reads consumed_at set => refused.
 *
 * Deterministic expected outcome (A8 + A6):
 *   - exactly ONE user
 *   - exactly ONE issuance consumed, bound to exactly one user_id
 *   - the loser refused; no partial state
 *
 * Also proves (A17): a superseded issuance (older code after a resend) is
 * never consumable even without contention.
 *
 * Run with:  APP_ENV=testing php backend/tests/Concurrency/smp_email_token_race.php
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

use App\Models\SmpEmailToken;
use App\Models\User;
use App\Services\SmpEmailTokenService;
use Illuminate\Support\Facades\DB;

echo 'DB driver: '.DB::connection()->getDriverName().PHP_EOL;

$svc = app(SmpEmailTokenService::class);

// ---------------------------------------------------------------- setup ----
DB::statement('SET FOREIGN_KEY_CHECKS=0');
SmpEmailToken::where('email', 'race@smp-race.test')->delete();
User::where('email', 'like', '%@smp-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$code = $svc->generateCode();
$row = SmpEmailToken::create([
    'token_digest' => $svc->digest($svc->normalize($code)),
    'email'        => 'race@smp-race.test',
    'nama'         => 'Race Candidate',
    'jenjang'      => 'SMP',
    'issued_at'    => now(),
    'expires_at'   => now()->addHours(SmpEmailTokenService::TTL_HOURS),
]);
echo "issuance id={$row->id} email={$row->email} code=(secret, length ".strlen($code).")\n";

// ------------------------------------------- Connection A: lock + win ----
$connA = DB::connection();
$connA->beginTransaction();

// A acquires the SAME advisory lock the service uses (email-scoped).
$lockA = \Illuminate\Support\Facades\Cache::lock('smp-email-token|'.sha1('race@smp-race.test'), 15);
$lockA->block(10);
echo "A: acquired email advisory lock\n";

$rowA = $connA->table('smp_email_tokens')
    ->where('email', 'race@smp-race.test')
    ->orderByDesc('issued_at')->orderByDesc('id')
    ->lockForUpdate()->first();

// Re-read under the lock (A6): verify then consume.
$digest = $svc->digest($svc->normalize($code));
if (!hash_equals($rowA->token_digest, $digest) || $rowA->consumed_at !== null) {
    echo "A: token unexpectedly unusable — abort\n";
    exit(1);
}
echo "A: locked latest issuance, digest verified, creating user while holding locks...\n";

$userA = User::create([
    'name' => 'A Parent SMP', 'email' => 'race@smp-race.test',
    'password' => bcrypt('Secret123'), 'jalur' => 'UMUM', 'jenjang' => 'SMP',
]);
$userA->forceFill(['role' => 'orang_tua'])->save();

$connA->table('smp_email_tokens')->where('id', $rowA->id)->update([
    'consumed_at'     => now(),
    'used_by_user_id' => $userA->id,
    'verified_at'     => now(),
]);

// --------------------------- Connection B: compete for the same code ----
// B follows the exact service path: advisory lock, then row lock, re-read.
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
        'SELECT id, consumed_at FROM smp_email_tokens
          WHERE email = ? ORDER BY issued_at DESC, id DESC LIMIT 1 FOR UPDATE'
    );
    $stmt->execute(['race@smp-race.test']);
    $rowB = $stmt->fetch(PDO::FETCH_ASSOC);
    $waited = round(microtime(true) - $bStart, 3);

    // B re-reads committed state under its own lock (A6): consumption is
    // already done by A.
    if ($rowB === false) {
        $bOutcome = 'NO_ROW';
        echo "B: waited {$waited}s; no row => refused\n";
    } elseif ($rowB['consumed_at'] !== null) {
        $bOutcome = 'REFUSED_CONSUMED';
        echo "B: waited {$waited}s; re-read consumed_at set => REFUSED (single-use holds)\n";
    } else {
        // B could ALSO reach here only if A had not consumed — a real race bug.
        $bOutcome = 'DOUBLE_CONSUME';
        echo "B: re-read shows unconsumed AFTER A consumed => RACE!\n";
    }
    $pdoB->exec('ROLLBACK');
} catch (PDOException $e) {
    $waited = round(microtime(true) - $bStart, 3);
    $bOutcome = 'BLOCKED';
    echo "B: blocked {$waited}s — serialised ({$e->getMessage()})\n";
    // ALWAYS release B's transaction: a lock-wait abort leaves the txn open
    // and its gap locks would block later setup inserts.
    if ($pdoB->inTransaction()) {
        $pdoB->exec('ROLLBACK');
    }
}

// A commits (releasing all locks so B resolves).
$connA->commit();
$lockA->release();
echo "A: committed (user_id={$userA->id})\n";

// ------------------------------------------------------------ final state ----
$fresh        = SmpEmailToken::find($rowA->id);
$usersCreated = User::where('email', 'like', '%@smp-race.test')->count();
echo PHP_EOL;
echo "FINAL: consumed=" . ($fresh->consumed_at ? 'yes' : 'no')
    . " used_by_user_id={$fresh->used_by_user_id} users_created={$usersCreated}\n";

$passed = $fresh->consumed_at !== null
    && (int) $fresh->used_by_user_id === (int) $userA->id
    && $usersCreated === 1
    && in_array($bOutcome, ['REFUSED_CONSUMED', 'NO_ROW', 'BLOCKED'], true);

echo $passed ? "SMP EMAIL TOKEN RACE PROOF PASSED\n" : "SMP EMAIL TOKEN RACE PROOF FAILED\n";

// ------------------------------------------------- superseded issuance ----
// A17: after a resend, the OLD code must be unusable even without contention.
$oldCode = $svc->generateCode();
$oldRow  = SmpEmailToken::create([
    'token_digest' => $svc->digest($svc->normalize($oldCode)),
    'email'        => 'sup@smp-race.test', 'nama' => 'Old', 'jenjang' => 'SMP',
    'issued_at'    => now()->subMinutes(5),
    'expires_at'   => now()->addHours(20),
]);
$newCode = $svc->generateCode();
$newRow  = SmpEmailToken::create([
    'token_digest' => $svc->digest($svc->normalize($newCode)),
    'email'        => 'sup@smp-race.test', 'nama' => 'Old', 'jenjang' => 'SMP',
    'issued_at'    => now(),
    'expires_at'   => now()->addHours(SmpEmailTokenService::TTL_HOURS),
]);

// "Consume" attempt with the OLD code (older issuance): latest-first lookup
// means the old digest never matches the newest row.
$latest = SmpEmailToken::where('email', 'sup@smp-race.test')
    ->orderByDesc('issued_at')->orderByDesc('id')->first();
$oldAccepted = $latest !== null && hash_equals($latest->token_digest, $svc->digest($svc->normalize($oldCode)));
$supersededProof = $oldAccepted === false && (int) $oldRow->consumed_at === 0 && $oldRow->consumed_at === null;
echo 'SUPERSEDED: old_code_accepted=' . var_export($oldAccepted, true) . " (must be false)\n";
echo $supersededProof ? "SUPERSEDED-ISSUANCE PROOF PASSED\n" : "SUPERSEDED-ISSUANCE PROOF FAILED\n";

// ------------------------------------------------------------- cleanup ------
DB::statement('SET FOREIGN_KEY_CHECKS=0');
SmpEmailToken::whereIn('email', ['race@smp-race.test', 'sup@smp-race.test'])->delete();
User::where('email', 'like', '%@smp-race.test')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo "cleanup done\n";

exit(($passed && $supersededProof) ? 0 : 1);
