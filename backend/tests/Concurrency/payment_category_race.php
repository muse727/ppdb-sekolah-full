<?php

// Independent Laravel processes; no transaction-wrapped PHPUnit fixtures.
require __DIR__.'/../bootstrap.php';
ppdb_test_pin_env('CACHE_STORE', 'array');
ppdb_test_pin_env('SESSION_DRIVER', 'array');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PaymentController;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminReportService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

if (DB::selectOne('SELECT DATABASE() db')->db !== 'ppdb_sekolah_test') {
    throw new RuntimeException('Unsafe database; refusing writes.');
}
$root = sys_get_temp_dir().'/b3-payment-'.($argv[2] ?? getmypid());
config(['filesystems.disks.private.root' => $root]);
Storage::forgetDisk('private');
if (($argv[1] ?? '') === 'worker') {
    Auth::setUser(User::findOrFail($argv[3]));
    $app->bind(App\Services\PpdbCapacityService::class, fn () => throw new LogicException('No capacity dependency'));
    $action = $argv[4];
    $request = new Request(['jenjang' => 'SD', 'payment_category' => 'DAFTAR_ULANG']);
    if ($action === 'upload') {
        $request->files->set('bukti_bayar', UploadedFile::fake()->image('race.png'));
    }
    echo "READY\n";
    flush();
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Worker barrier failed');
    }
    $controller = app(PaymentController::class);
    if ($action === 'query') {
        $category = $argv[5];
        $userId = (int) $argv[6];
        $id = null;
        for ($i = 0; $i < 50; $i++) {
            $id = Payment::forCategory($category)->where('user_id', $userId)->latest('id')->value('id');
            $report = app(AdminReportService::class)->query('pembayaran', [], $category)->where('u.id', $userId)->first();
            if ((int) $report->payment_id !== (int) $id) {
                throw new RuntimeException('Category report mismatch');
            }
        }
        echo json_encode(['id' => $id, 'category' => $category]).PHP_EOL;
    } else {
        $response = $action === 'upload' ? $controller->manualPayment($request) : $controller->verifyManual($argv[5]);
        echo json_encode(['status' => $response->getStatusCode()]).PHP_EOL;
    }
    exit;
}

function check(bool $condition, string $label): void
{
    echo ($condition ? 'PASS ' : 'FAIL ').$label.PHP_EOL;
    if (! $condition) {
        throw new RuntimeException($label);
    }
}

// The parent optionally holds a row lock until all independent workers are ready.
function race(array $commands, ?callable $lock = null): array
{
    $jobs = [];
    try {
        if ($lock) {
            DB::beginTransaction();
            $lock();
        }
        foreach ($commands as $args) {
            $pipes = [];
            $process = proc_open(array_merge([PHP_BINARY, __FILE__, 'worker'], $args),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Cannot start worker');
            }
            stream_set_timeout($pipes[1], 20);
            $jobs[] = [$process, $pipes];
        }
        foreach ($jobs as [$process, $pipes]) {
            if (trim((string) fgets($pipes[1])) !== 'READY') {
                throw new RuntimeException('Worker not ready: '.stream_get_contents($pipes[2]));
            }
        }
        foreach ($jobs as [$process, $pipes]) {
            fwrite($pipes[0], "GO\n");
            fclose($pipes[0]);
        }
        usleep(350000);
        if ($lock) {
            DB::commit();
        }
        $results = [];
        foreach ($jobs as $i => [$process, $pipes]) {
            $results[] = json_decode(trim((string) fgets($pipes[1])), true, 512, JSON_THROW_ON_ERROR);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            unset($jobs[$i]);
            if ($code !== 0) {
                throw new RuntimeException('Worker failed: '.$error);
            }
        }
        echo json_encode($results).PHP_EOL;
        return $results;
    } finally {
        if (DB::transactionLevel()) {
            DB::rollBack();
        }
        foreach ($jobs as [$process, $pipes]) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}

echo 'SELECT DATABASE(): '.DB::selectOne('SELECT DATABASE() db')->db.PHP_EOL;
$tag = bin2hex(random_bytes(6));
$root = sys_get_temp_dir().'/b3-payment-'.$tag;
config(['filesystems.disks.private.root' => $root]);
Storage::forgetDisk('private');
$users = [];
$price = Setting::where('key', 'biaya_sd')->first();
$priceBefore = $price?->value;
$seats = DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson();
$capacities = DB::table('ppdb_capacities')->orderBy('jenjang')->get()->toJson();
try {
    $owner = User::factory()->create(['email' => "$tag-owner@b3-race.test", 'jenjang' => 'SD']);
    $users[] = $owner->id;
    $owner->forceFill(['role' => 'orang_tua'])->save();
    $admin = User::factory()->create(['email' => "$tag-admin@b3-race.test"]);
    $users[] = $admin->id;
    $admin->forceFill(['role' => 'admin'])->save();
    Setting::updateOrCreate(['key' => 'biaya_sd'], ['value' => '175000']);
    $du = Payment::create(['user_id' => $owner->id, 'payment_category' => 'DAFTAR_ULANG',
        'invoice_number' => "B3-DU-$tag", 'jenjang' => 'SD', 'amount' => 500000, 'status' => 'SUCCESS']);
    $duBefore = $du->fresh()->getAttributes();
    $results = race([[$tag, (string) $owner->id, 'upload'], [$tag, (string) $owner->id, 'upload']],
        fn () => DB::table('users')->where('id', $owner->id)->lockForUpdate()->first());
    $statuses = array_column($results, 'status');
    sort($statuses);
    check($statuses === [200, 409], 'same-category concurrent uploads: one success, one conflict');
    check(Payment::ppdb()->where('user_id', $owner->id)->count() === 1, 'exactly one PPDB financial row');
    check(count(Storage::disk('private')->allFiles()) === 1, 'exactly one private proof, no losing-upload artifact');
    $p = Payment::ppdb()->where('user_id', $owner->id)->firstOrFail();
    $results = race([[$tag, (string) $admin->id, 'verify', (string) $p->id],
        [$tag, (string) $admin->id, 'verify', (string) $p->id]],
        fn () => DB::table('payments')->where('id', $p->id)->lockForUpdate()->first());
    $statuses = array_column($results, 'status');
    sort($statuses);
    check($statuses === [200, 409] && $p->fresh()->status === 'SUCCESS', 'same-category concurrent verification: one transition');
    check($p->fresh()->verifier_id === $admin->id && $p->fresh()->verified_at !== null, 'financial audit preserved');
    $results = race([[$tag, (string) $admin->id, 'query', 'PPDB', (string) $owner->id],
        [$tag, (string) $admin->id, 'query', 'DAFTAR_ULANG', (string) $owner->id]]);
    check((int) $results[0]['id'] === $p->id && (int) $results[1]['id'] === $du->id, '50 concurrent queries/reports per category: isolated latest IDs');
    $results = race([[$tag, (string) $admin->id, 'verify', (string) $du->id],
        [$tag, (string) $admin->id, 'query', 'PPDB', (string) $owner->id]]);
    check($results[0]['status'] === 404 && (int) $results[1]['id'] === $p->id, 'cross-category verification refused during PPDB reads');
    check($duBefore === $du->fresh()->getAttributes(), 'DAFTAR_ULANG financial row unchanged');
    check($seats === DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson(), 'all seat allocation rows unchanged');
    check($capacities === DB::table('ppdb_capacities')->orderBy('jenjang')->get()->toJson(), 'all capacity rows unchanged');
    echo "B3 CATEGORY CONCURRENCY PASSED\n";
} finally {
    foreach (Payment::whereIn('user_id', $users)->get() as $p) {
        if ($p->proof_of_payment) {
            Storage::disk('private')->delete($p->proof_of_payment);
        }
    }
    User::whereIn('id', $users)->delete();
    if ($price) {
        Setting::whereKey($price->id)->update(['value' => $priceBefore]);
    } else {
        Setting::where('key', 'biaya_sd')->delete();
    }
    Storage::disk('private')->deleteDirectory('bukti_bayar');
    @rmdir($root);
    echo "Scoped fixture cleanup complete\n";
}
