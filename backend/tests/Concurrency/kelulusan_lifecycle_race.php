<?php

// Real processes and committed fixtures. Never run against a non-test schema.
require __DIR__.'/../bootstrap.php';
ppdb_test_pin_env('CACHE_STORE', 'array');
ppdb_test_pin_env('SESSION_DRIVER', 'array');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\KelulusanController;
use App\Http\Controllers\PpdbSeatAllocationController;
use App\Models\Payment;
use App\Models\PpdbCapacity;
use App\Models\PpdbSeatAllocation;
use App\Models\Setting;
use App\Models\User;
use App\Services\KelulusanLifecycleService;
use App\Services\PpdbCapacityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$db = DB::selectOne('SELECT DATABASE() db')->db;
if ($db !== 'ppdb_sekolah_test') {
    fwrite(STDERR, "Unsafe database: {$db}\n");
    exit(1);
}

if (($argv[1] ?? '') === 'worker') {
    echo "READY {$db}\n";
    flush();
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(1);
    }
    $action = $argv[2];
    $ids = array_map('intval', explode(',', $argv[3]));
    $status = $argv[4];
    DB::enableQueryLog();
    $start = microtime(true);
    try {
        $lifecycle = app(KelulusanLifecycleService::class);
        $controller = app(KelulusanController::class);
        if ($action === 'single') {
            $response = $controller->update(new Request(['status' => $status]), $ids[0], $lifecycle);
            $code = $response->getStatusCode();
        } elseif ($action === 'bulk') {
            $response = $controller->bulkUpdate(new Request(['user_ids' => $ids, 'status' => $status]), $lifecycle);
            $code = $response->getStatusCode();
        } elseif ($action === 'release') {
            app(PpdbCapacityService::class)->releaseSeat(PpdbSeatAllocation::where('user_id', $ids[0])->firstOrFail(), 'NOT_ACCEPTED');
            $code = 200;
        } elseif ($action === 'generic') {
            $seat = PpdbSeatAllocation::where('user_id', $ids[0])->firstOrFail();
            $code = app(PpdbSeatAllocationController::class)->release(new Request(['reason' => 'NOT_ACCEPTED']), $seat->id)->getStatusCode();
        } else {
            throw new RuntimeException('Unknown action');
        }
    } catch (ValidationException $e) {
        $code = 422;
    } catch (Throwable $e) {
        fwrite(STDERR, (string) $e);
        exit(1);
    }
    $writes = count(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^update `ppdb_seat_allocations`/i', $q['query'])));
    echo json_encode(['status' => $code, 'elapsed' => microtime(true) - $start, 'allocation_writes' => $writes]).PHP_EOL;
    exit;
}

function check(bool $ok, string $label): void
{
    echo ($ok ? 'PASS ' : 'FAIL ').$label.PHP_EOL;
    if (!$ok) {
        throw new RuntimeException($label);
    }
}

function race(array $commands): array
{
    $jobs = [];
    DB::beginTransaction();
    try {
        PpdbCapacity::orderBy('jenjang')->lockForUpdate()->get();
        foreach ($commands as $args) {
            $pipes = [];
            $p = proc_open(array_merge([PHP_BINARY, __FILE__, 'worker'], $args),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($p)) {
                throw new RuntimeException('Cannot start worker');
            }
            stream_set_timeout($pipes[1], 20);
            $jobs[] = [$p, $pipes];
        }
        foreach ($jobs as [$p, $pipes]) {
            if (trim((string) fgets($pipes[1])) !== 'READY ppdb_sekolah_test') {
                throw new RuntimeException('Worker database/readiness failure');
            }
        }
        foreach ($jobs as [$p, $pipes]) {
            fwrite($pipes[0], "GO\n");
            fclose($pipes[0]);
        }
        usleep(350000);
        DB::commit();
        $results = [];
        foreach ($jobs as $i => [$p, $pipes]) {
            $line = trim((string) fgets($pipes[1]));
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($p);
            unset($jobs[$i]);
            if ($code !== 0) {
                throw new RuntimeException('Worker failed: '.$stderr);
            }
            $results[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
        echo json_encode($results).PHP_EOL;
        return $results;
    } finally {
        if (DB::transactionLevel()) {
            DB::rollBack();
        }
        foreach ($jobs as [$p, $pipes]) {
            proc_terminate($p);
            proc_close($p);
        }
    }
}

echo "SELECT DATABASE(): {$db}\n";
$tag = bin2hex(random_bytes(6));
$ids = [];
$capacityBefore = DB::table('ppdb_capacities')->orderBy('jenjang')->get();
$settingsBefore = DB::table('settings')->whereIn('key', ['ppdb_status', 'ppdb_auto_closed'])->get();
$failed = false;
try {
    foreach (['SD', 'SMP'] as $jenjang) {
        PpdbCapacity::updateOrCreate(['jenjang' => $jenjang], ['max_seats' => 1000 + PpdbSeatAllocation::where('jenjang', $jenjang)->where('status', 'ALLOCATED')->count()]);
    }
    Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => 'BUKA']);
    Setting::updateOrCreate(['key' => 'ppdb_auto_closed'], ['value' => '0']);
    $cases = [
        ['A same-student TIDAK_LULUS/TIDAK_LULUS', 'single', 'TIDAK_LULUS', 'single', 'TIDAK_LULUS'],
        ['B TIDAK_LULUS/domain NOT_ACCEPTED release', 'single', 'TIDAK_LULUS', 'release', 'TIDAK_LULUS'],
        ['B generic release attempt blocked', 'single', 'TIDAK_LULUS', 'generic', 'TIDAK_LULUS'],
        ['C TIDAK_LULUS/LULUS', 'single', 'TIDAK_LULUS', 'single', 'LULUS'],
        ['C LULUS/TIDAK_LULUS reversed worker order', 'single', 'LULUS', 'single', 'TIDAK_LULUS'],
        ['D single/bulk TIDAK_LULUS', 'single', 'TIDAK_LULUS', 'bulk', 'TIDAK_LULUS'],
        ['D single rejection/bulk LULUS', 'single', 'TIDAK_LULUS', 'bulk', 'LULUS'],
        ['D bulk rejection/single LULUS', 'bulk', 'TIDAK_LULUS', 'single', 'LULUS'],
    ];
    foreach ($cases as [$label, $actionA, $statusA, $actionB, $statusB]) {
        echo "CASE {$label}\n";
        $owners = [];
        foreach ([0, 1] as $i) {
            $owner = User::factory()->create(['email' => "$tag-".count($ids)."@b4-race.test", 'jenjang' => 'SD']);
            $ids[] = $owner->id;
            $owner->forceFill(['role' => 'orang_tua', 'status_kelulusan' => 'BELUM'])->save();
            PpdbSeatAllocation::create(['user_id' => $owner->id, 'payment_id' => null, 'jenjang' => 'SD', 'status' => 'ALLOCATED', 'allocated_at' => now()]);
            foreach (Payment::CATEGORIES as $category) {
                Payment::create(['user_id' => $owner->id, 'payment_category' => $category,
                    'invoice_number' => "$tag-{$owner->id}-$category", 'jenjang' => 'SD', 'amount' => 175000, 'status' => 'SUCCESS']);
            }
            $owners[] = $owner;
        }
        $owner = $owners[0];
        $other = $owners[1];
        $payments = DB::table('payments')->whereIn('user_id', [$owner->id, $other->id])->orderBy('id')->get()->toJson();
        $otherBefore = $other->fresh()->getAttributes();
        $quota = app(PpdbCapacityService::class)->getCapacity('SD')['sisa'];
        $args = fn ($action, $status) => [$action, $action === 'bulk' ? "$owner->id,$other->id" : (string) $owner->id, $status];
        $results = race([$args($actionA, $statusA), $args($actionB, $statusB)]);
        check($owner->fresh()->status_kelulusan === 'TIDAK_LULUS', 'deterministic final TIDAK_LULUS');
        $seat = PpdbSeatAllocation::where('user_id', $owner->id)->firstOrFail();
        check($seat->status === 'RELEASED' && $seat->release_reason === 'NOT_ACCEPTED' && $seat->payment_id === null, 'one preserved RELEASED history row');
        check(PpdbSeatAllocation::where('user_id', $owner->id)->count() === 1, 'no second allocation');
        $bulkRejected = ($actionA === 'bulk' && $statusA === 'TIDAK_LULUS') || ($actionB === 'bulk' && $statusB === 'TIDAK_LULUS');
        $expectedDelta = $bulkRejected ? 2 : 1;
        check(array_sum(array_column($results, 'allocation_writes')) === $expectedDelta, 'exactly one effective release per rejected student');
        check(app(PpdbCapacityService::class)->getCapacity('SD')['sisa'] === $quota + $expectedDelta, 'quota increases exactly '.$expectedDelta);
        foreach ($results as $i => $result) {
            $action = $i === 0 ? $actionA : $actionB;
            $status = $i === 0 ? $statusA : $statusB;
            check($action === 'generic' ? $result['status'] === 409 :
                ($status === 'TIDAK_LULUS' ? $result['status'] === 200 : in_array($result['status'], [200, 422], true)), 'worker '.$i.' valid response');
            if ($action !== 'generic') {
                check($result['elapsed'] >= 0.30, 'worker '.$i.' waited on real capacity-row lock');
            }
        }
        $otherSeat = PpdbSeatAllocation::where('user_id', $other->id)->firstOrFail();
        check($bulkRejected ? $otherSeat->status === 'RELEASED' && $other->fresh()->status_kelulusan === 'TIDAK_LULUS' :
            $otherSeat->status === 'ALLOCATED' && in_array($other->fresh()->status_kelulusan, ['BELUM', 'LULUS'], true), 'bulk peer lifecycle consistent');
        if (($actionA === 'bulk' && $results[0]['status'] === 422)
            || ($actionB === 'bulk' && $results[1]['status'] === 422)) {
            check($otherBefore === $other->fresh()->getAttributes(), 'invalid bulk reversal does not partially update peer');
        }
        check($payments === DB::table('payments')->whereIn('user_id', [$owner->id, $other->id])->orderBy('id')->get()->toJson(), 'all payment fields/categories unchanged');
        $history = $seat->fresh()->getAttributes();
        app(KelulusanLifecycleService::class)->transition([$owner->id => 'TIDAK_LULUS']);
        check($history === $seat->fresh()->getAttributes(), 'repeated handling preserves release timestamp/reason');
    }
    echo "B4 LIFECYCLE CONCURRENCY PASSED\n";
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, (string) $e.PHP_EOL);
} finally {
    User::whereIn('id', $ids)->delete();
    foreach (['SD', 'SMP'] as $jenjang) {
        $before = $capacityBefore->firstWhere('jenjang', $jenjang);
        if ($before) {
            DB::table('ppdb_capacities')->where('jenjang', $jenjang)->update((array) $before);
        } else {
            DB::table('ppdb_capacities')->where('jenjang', $jenjang)->delete();
        }
    }
    foreach (['ppdb_status', 'ppdb_auto_closed'] as $key) {
        $before = $settingsBefore->firstWhere('key', $key);
        if ($before) {
            DB::table('settings')->where('key', $key)->update((array) $before);
        } else {
            DB::table('settings')->where('key', $key)->delete();
        }
    }
    echo "Scoped fixture cleanup complete\n";
}
exit($failed ? 1 : 0);
