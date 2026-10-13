<?php

// Real controller calls in independent processes/sessions. Test DB pinned before bootstrap.
require __DIR__.'/../bootstrap.php';
ppdb_test_pin_env('CACHE_STORE', 'array');
ppdb_test_pin_env('SESSION_DRIVER', 'array');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PaymentController;
use App\Models\Payment;
use App\Models\PpdbSeatAllocation;
use App\Models\User;
use App\Services\PpdbCapacityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$database = DB::selectOne('SELECT DATABASE() db')->db;
if ($database !== 'ppdb_sekolah_test') {
    throw new RuntimeException('Unsafe database');
}
if (($argv[1] ?? '') === 'worker') {
    Auth::setUser(User::findOrFail($argv[3]));
    // Fail loudly if verification ever depends on the capacity service again.
    $app->bind(PpdbCapacityService::class, function () {
        throw new LogicException('Financial verification must not resolve capacity.');
    });
    echo "READY\n";
    flush();
    $start = microtime(true);
    $controller = $app->make(PaymentController::class);
    $response = ($argv[4] ?? 'verify') === 'reject'
        ? $controller->rejectManual(new Request(['reason' => 'Concurrent rejection']), $argv[2])
        : $app->call([$controller, 'verifyManual'], ['id' => $argv[2]]);
    echo json_encode(['status' => $response->getStatusCode(), 'elapsed' => microtime(true) - $start]).PHP_EOL;
    exit(0);
}
echo 'SELECT DATABASE(): '.$database.PHP_EOL;
$tag = bin2hex(random_bytes(6));
$users = [];
$payments = [];
$processes = [];
$pipes = [];
$oldCapacity = DB::table('ppdb_capacities')->where('jenjang', 'SD')->first();
try {
    $owner = User::factory()->create(['email' => "owner-$tag@b2-race.test", 'jenjang' => 'SD']);
    $users[] = $owner->id;
    $admins = [];
    for ($i = 0; $i < 2; $i++) {
        $admin = User::factory()->create(['email' => "admin-$i-$tag@b2-race.test"]);
        $admin->forceFill(['role' => 'admin'])->save();
        $admins[] = $admin;
        $users[] = $admin->id;
    }
    PpdbSeatAllocation::create(['user_id' => $owner->id, 'payment_id' => null, 'jenjang' => 'SD',
        'status' => 'ALLOCATED', 'allocated_at' => now()]);
    $svc = app(PpdbCapacityService::class);
    DB::table('ppdb_capacities')->updateOrInsert(['jenjang' => 'SD'], ['max_seats' => $svc->activeCount('SD')]);
    $seats = DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson();
    $quota = $svc->publicQuota();
    if ($quota['sd']['sisa'] !== 0) {
        throw new RuntimeException('Expected zero remaining capacity');
    }
    foreach (['verify', 'reject'] as $competitor) {
        $payment = Payment::create(['user_id' => $owner->id, 'invoice_number' => "B2-$competitor-$tag",
            'jenjang' => 'SD', 'amount' => 150000, 'payment_method' => 'MANUAL', 'status' => 'PENDING_MANUAL']);
        $payments[] = $payment->id;
        DB::beginTransaction();
        DB::table('payments')->where('id', $payment->id)->lockForUpdate()->first();
        foreach (['verify', $competitor] as $i => $action) {
            $processes[$i] = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $payment->id,
                (string) $admins[$i]->id, $action],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
            if (!is_resource($processes[$i])) {
                throw new RuntimeException('Worker failed to start');
            }
            fclose($pipes[$i][0]);
            stream_set_timeout($pipes[$i][1], 15);
        }
        foreach ($pipes as $worker) {
            if (trim((string) fgets($worker[1])) !== 'READY') {
                throw new RuntimeException('Worker did not become ready');
            }
        }
        usleep(300000);
        DB::commit();
        $results = [];
        foreach ($pipes as $i => $worker) {
            $results[$i] = json_decode(trim((string) fgets($worker[1])), true, 512, JSON_THROW_ON_ERROR);
            $stderr = stream_get_contents($worker[2]);
            fclose($worker[1]);
            fclose($worker[2]);
            if (proc_close($processes[$i]) !== 0) {
                throw new RuntimeException('Worker failed: '.$stderr);
            }
            unset($processes[$i]);
            echo "$competitor worker $i: ".json_encode($results[$i]).PHP_EOL;
        }
        $statuses = array_column($results, 'status');
        sort($statuses);
        $winner = $results[0]['status'] === 200 ? 0 : 1;
        $expected = $winner === 1 && $competitor === 'reject' ? 'REJECTED' : 'SUCCESS';
        $final = $payment->fresh();
        $checks = [
            'exactly one transition, statuses 200/409' => $statuses === [200, 409],
            'both sessions waited on payment lock' => min(array_column($results, 'elapsed')) >= 0.3,
            'financial state matches winner' => $final->status === $expected,
            'audit belongs to winner' => $final->verifier_id === $admins[$winner]->id && $final->verified_at !== null,
            'all allocation rows byte-for-byte unchanged' => $seats === DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson(),
            'quota unchanged, zero remaining' => $quota === $svc->publicQuota(),
            'one active seat, NULL payment_id' => PpdbSeatAllocation::where('user_id', $owner->id)->where('status', 'ALLOCATED')->whereNull('payment_id')->count() === 1,
        ];
        foreach ($checks as $label => $passed) {
            echo ($passed ? 'PASS ' : 'FAIL ').$label.PHP_EOL;
        }
        if (in_array(false, $checks, true)) {
            throw new RuntimeException('Race invariant failed');
        }
    }
    echo "B2 PAYMENT RACE PASSED\n";
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($processes as $process) {
        proc_terminate($process);
        proc_close($process);
    }
    Payment::whereIn('id', $payments)->delete();
    User::whereIn('id', $users)->delete();
    if ($oldCapacity) {
        DB::table('ppdb_capacities')->where('jenjang', 'SD')->update(['max_seats' => $oldCapacity->max_seats]);
    } else {
        DB::table('ppdb_capacities')->where('jenjang', 'SD')->delete();
    }
    echo "Scoped fixture cleanup complete\n";
}
