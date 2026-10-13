<?php

/** Real MariaDB delete/register serialization proof; only disposable testing DB. */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once __DIR__.'/ConcurrencySafetyGuard.php';
assert_disposable_concurrency_target();

use App\Models\User;
use App\Models\Whitelist;
use Illuminate\Support\Facades\DB;

// The child uses a separate connection and signals immediately before its
// blocking read. The parent commits while the child is still waiting.
if (($argv[1] ?? '') === 'worker') {
    DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
    DB::beginTransaction();
    echo "READY\n";
    flush();
    $start = microtime(true);
    if ($argv[2] === 'delete') {
        $row = Whitelist::whereKey($argv[3])->lockForUpdate()->first();
        $status = !$row ? 404 : (($row->is_used || $row->user_id !== null) ? 409 : 204);
        if ($status === 204) $row->delete();
    } else {
        $row = Whitelist::where('registration_number', $argv[3])
            ->where('is_used', false)->lockForUpdate()->first();
        $status = $row ? 200 : 422;
        if ($row) {
            $user = User::create([
                'name' => 'Race loser', 'email' => 'loser@delete-register.test',
                'password' => bcrypt('Secret123'), 'jalur' => 'INTERNAL', 'jenjang' => 'SD',
            ]);
            $row->update(['is_used' => true, 'user_id' => $user->id]);
        }
    }
    DB::commit();
    echo json_encode(['status' => $status, 'wait' => microtime(true) - $start])."\n";
    exit(0);
}

$passed = true;
foreach (['registration', 'deletion'] as $winner) {
    $candidate = Whitelist::create([
        'nama_siswa' => 'Delete/register race', 'jenjang' => 'SD', 'asal_sekolah' => '',
        'registration_number' => app(App\Services\InternalAccessCodeService::class)->generateCode(),
        'is_used' => false,
    ]);
    $user = null;
    $proc = null;
    try {
        DB::beginTransaction();
        $locked = Whitelist::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
        if ($winner === 'registration') {
            $user = User::create([
                'name' => 'Race winner', 'email' => 'winner@delete-register.test',
                'password' => bcrypt('Secret123'), 'jalur' => 'INTERNAL', 'jenjang' => 'SD',
            ]);
            $locked->update(['is_used' => true, 'user_id' => $user->id]);
            $target = 'delete';
            $subject = (string) $candidate->id;
            $expected = 409;
        } else {
            $locked->delete();
            $target = 'register';
            $subject = $candidate->registration_number;
            $expected = 422;
        }
        $proc = proc_open([PHP_BINARY, __FILE__, 'worker', $target, $subject],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('Cannot start competing worker.');
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 20);
        if (trim((string) fgets($pipes[1])) !== 'READY') {
            throw new RuntimeException('Competing worker did not become ready.');
        }
        usleep(500000);
        DB::commit();
        $result = json_decode(trim(stream_get_contents($pipes[1])), true);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $proc = null;
        $fresh = $candidate->fresh();
        $safe = $winner === 'registration'
            ? ($fresh && $fresh->is_used && (int) $fresh->user_id === $user->id)
            : (!$fresh && !User::where('email', 'loser@delete-register.test')->exists());
        $ok = $exit === 0 && ($result['status'] ?? null) === $expected
            && ($result['wait'] ?? 0) >= 0.4 && $safe;
        $passed = $passed && $ok;
        echo $winner.' wins: '.($ok ? 'PASS' : 'FAIL')
            .' (competitor status='.($result['status'] ?? 'error').')'.PHP_EOL;
        if ($errors !== '') throw new RuntimeException('Competing worker reported an error.');
    } finally {
        while (DB::transactionLevel() > 0) DB::rollBack();
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
        Whitelist::whereKey($candidate->id)->delete();
        if ($user) $user->delete();
        User::where('email', 'loser@delete-register.test')->delete();
    }
}
echo $passed ? "DELETE/REGISTER RACE PROOF PASSED\n" : "DELETE/REGISTER RACE PROOF FAILED\n";
exit($passed ? 0 : 1);
