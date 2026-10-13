<?php

// Real HTTP-kernel registrations in separate PHP/MySQL sessions, test DB only.
require __DIR__.'/../bootstrap.php';
ppdb_test_pin_env('CACHE_STORE', 'array');
ppdb_test_pin_env('SESSION_DRIVER', 'array');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PpdbSeatAllocation;
use App\Models\User;
use App\Services\PpdbCapacityService;
use Illuminate\Support\Facades\DB;

$database = DB::selectOne('SELECT DATABASE() AS db')->db;
if ($database !== 'ppdb_sekolah_test') {
    throw new RuntimeException('Unsafe database');
}

if (($argv[1] ?? '') === 'worker') {
    $app->bind(PpdbCapacityService::class, function () {
        return new class extends PpdbCapacityService {
            public function lockRegistrationCapacity(string $jenjang): void
            {
                echo "READY\n";
                flush();
                parent::lockRegistrationCapacity($jenjang);
            }
        };
    });
    $request = Illuminate\Http\Request::create('/api/register', 'POST', [
        'name' => 'Race Parent', 'email' => $argv[2], 'password' => 'Secret123',
        'jalur' => 'UMUM', 'kredensial' => $argv[3],
    ], [], [], ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1']);
    $start = microtime(true);
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    echo json_encode(['status' => $response->getStatusCode(), 'elapsed' => microtime(true) - $start,
        'body' => json_decode($response->getContent(), true)]).PHP_EOL;
    exit(0);
}

echo 'SELECT DATABASE(): '.$database.PHP_EOL;
$tag = bin2hex(random_bytes(6));
$emails = ["a-$tag@b1-race.test", "b-$tag@b1-race.test"];
$codes = ['AM-RACE-A-'.strtoupper($tag), 'AM-RACE-B-'.strtoupper($tag)];
$oldCapacity = DB::table('ppdb_capacities')->where('jenjang', 'SD')->first();
$oldGate = DB::table('settings')->where('key', 'ppdb_status')->first();
$beforeUsers = User::count();
$beforeSeats = PpdbSeatAllocation::count();
$beforeActive = app(PpdbCapacityService::class)->activeCount('SD');
$beforePayments = DB::table('payments')->count();
$processes = [];
$pipes = [];
try {
    DB::table('settings')->updateOrInsert(['key' => 'ppdb_status'], ['value' => 'BUKA']);
    DB::table('ppdb_capacities')->updateOrInsert(['jenjang' => 'SD'], ['max_seats' => $beforeActive + 1]);
    foreach ($codes as $code) {
        DB::table('tokens')->insert(['code' => $code, 'is_used' => false]);
    }
    DB::beginTransaction();
    DB::table('ppdb_capacities')->where('jenjang', 'SD')->lockForUpdate()->first();
    foreach ($emails as $i => $email) {
        $processes[$i] = proc_open([PHP_BINARY, __FILE__, 'worker', $email, $codes[$i]],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        if (!is_resource($processes[$i])) {
            throw new RuntimeException('Could not start worker');
        }
        fclose($pipes[$i][0]);
        stream_set_timeout($pipes[$i][1], 15);
    }
    foreach ($pipes as $i => $workerPipes) {
        if (trim((string) fgets($workerPipes[1])) !== 'READY') {
            throw new RuntimeException('Worker did not reach capacity lock: '.stream_get_contents($workerPipes[2]));
        }
        echo "Worker $i: credential locked, reached capacity lock\n";
    }
    // Both requests are pending while a third session owns the capacity row.
    usleep(300000);
    DB::commit();
    $results = [];
    foreach ($pipes as $i => $workerPipes) {
        $results[$i] = json_decode(trim((string) fgets($workerPipes[1])), true, 512, JSON_THROW_ON_ERROR);
        $stderr = stream_get_contents($workerPipes[2]);
        fclose($workerPipes[1]);
        fclose($workerPipes[2]);
        if (proc_close($processes[$i]) !== 0) {
            throw new RuntimeException('Worker failed: '.$stderr);
        }
        unset($processes[$i]);
        echo 'Worker '.$i.': '.json_encode($results[$i]).PHP_EOL;
    }
    $statuses = array_column($results, 'status');
    sort($statuses);
    $winner = $results[0]['status'] === 200 ? 0 : 1;
    $loser = 1 - $winner;
    $owner = User::where('email', $emails[$winner])->first();
    $seat = $owner ? PpdbSeatAllocation::where('user_id', $owner->id)->first() : null;
    $loserToken = DB::table('tokens')->where('code', $codes[$loser])->first();
    $winnerToken = DB::table('tokens')->where('code', $codes[$winner])->first();
    $checks = [
        'statuses exactly 200 and 409' => $statuses === [200, 409],
        'both workers contended on held capacity' => min(array_column($results, 'elapsed')) >= 0.3,
        'exactly one new users row' => User::count() === $beforeUsers + 1,
        'exactly one new allocation' => PpdbSeatAllocation::count() === $beforeSeats + 1,
        'exactly one new active allocation' => app(PpdbCapacityService::class)->activeCount('SD') === $beforeActive + 1,
        'owner correct and payment NULL' => $seat && $seat->user_id === $owner->id && $seat->payment_id === null,
        'winner credential bound' => $owner && (int) $winnerToken->user_id === $owner->id && (bool) $winnerToken->is_used,
        'loser credential unused and unbound' => !(bool) $loserToken->is_used && $loserToken->user_id === null,
        'loser has no account' => !User::where('email', $emails[$loser])->exists(),
        'quota zero' => app(PpdbCapacityService::class)->getCapacity('SD')['sisa'] === 0,
        'no payment created' => DB::table('payments')->count() === $beforePayments,
    ];
    foreach ($checks as $label => $passed) {
        echo ($passed ? 'PASS ' : 'FAIL ').$label.PHP_EOL;
    }
    if (in_array(false, $checks, true)) {
        throw new RuntimeException('Concurrency invariant failed');
    }
    echo "B1 REGISTRATION RACE PASSED\n";
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($processes as $process) {
        proc_terminate($process);
        proc_close($process);
    }
    // Only this run's fixtures are removed; unrelated test allocations remain.
    DB::table('tokens')->whereIn('code', $codes)->delete();
    User::whereIn('email', $emails)->delete();
    if ($oldCapacity) {
        DB::table('ppdb_capacities')->where('jenjang', 'SD')->update(['max_seats' => $oldCapacity->max_seats]);
    } else {
        DB::table('ppdb_capacities')->where('jenjang', 'SD')->delete();
    }
    if ($oldGate) {
        DB::table('settings')->where('key', 'ppdb_status')->update(['value' => $oldGate->value]);
    } else {
        DB::table('settings')->where('key', 'ppdb_status')->delete();
    }
    echo "Scoped fixture cleanup complete\n";
}
