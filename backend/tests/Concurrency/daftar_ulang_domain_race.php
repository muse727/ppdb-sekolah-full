<?php

require __DIR__.'/../bootstrap.php';
ppdb_test_pin_env('CACHE_STORE', 'array');
ppdb_test_pin_env('SESSION_DRIVER', 'array');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DaftarUlangIndividualPricing;
use App\Models\DaftarUlangInvoice;
use App\Models\DaftarUlangPricingRevision;
use App\Models\Payment;
use App\Models\PpdbSeatAllocation;
use App\Models\User;
use App\Services\DaftarUlangPricingService;
use App\Services\DaftarUlangService;
use Illuminate\Support\Facades\DB;

$db = DB::selectOne('SELECT DATABASE() db')->db;
if ($db !== 'ppdb_sekolah_test') {
    fwrite(STDERR, "Unsafe database: {$db}\n");
    exit(1);
}
config(['daftar_ulang.agreement' => ['version' => 'RACE-1', 'text' => 'Reviewed race test agreement.']]);

function items(int $amount): array
{
    return [['id' => 'tuition', 'name' => 'Tuition', 'quantity' => 2, 'unit_amount' => $amount],
        ['id' => 'kit', 'name' => 'Kit', 'quantity' => 1, 'unit_amount' => 50000]];
}

if (($argv[1] ?? '') === 'worker') {
    echo "READY {$db}\n";
    flush();
    if (trim((string) fgets(STDIN)) !== 'GO') exit(1);
    [$action, $userId, $actorId, $amount] = array_slice($argv, 2);
    $start = microtime(true);
    try {
        $pricing = app(DaftarUlangPricingService::class);
        if ($action === 'invoice') {
            $invoice = app(DaftarUlangService::class)->createInvoice((int) $userId);
            $result = ['invoice_id' => $invoice->id, 'snapshot' => $invoice->pricing_snapshot];
        } elseif ($action === 'default') {
            $revision = $pricing->updateDefault((int) $actorId, 'SD', items((int) $amount), 123);
            $result = ['revision_id' => $revision->id, 'revision' => $revision->revision];
        } elseif ($action === 'deactivate') {
            $pricing->updateIndividual((int) $actorId, (int) $userId, ['active' => false]);
            $result = ['active' => false];
        } else {
            $head = $pricing->updateIndividual((int) $actorId, (int) $userId,
                ['mode' => 'CUSTOM', 'active' => true, 'items' => items((int) $amount), 'discount_nominal' => 123]);
            $result = ['revision_id' => $head->pricing_revision_id, 'total' => $head->revision->total];
        }
        echo json_encode([...$result, 'elapsed' => microtime(true) - $start]).PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, (string) $e);
        exit(1);
    }
    exit;
}

function check(bool $ok, string $message): void
{
    echo ($ok ? 'PASS ' : 'FAIL ').$message.PHP_EOL;
    if (!$ok) throw new RuntimeException($message);
}

function race(array $commands, string $lock, int $userId): array
{
    $jobs = [];
    DB::beginTransaction();
    try {
        if ($lock === 'user') User::whereKey($userId)->lockForUpdate()->firstOrFail();
        else DB::table('du_default_pricings')->where('jenjang', 'SD')->lockForUpdate()->first();
        foreach ($commands as $args) {
            $pipes = [];
            $p = proc_open(array_merge([PHP_BINARY, __FILE__, 'worker'], $args),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($p)) throw new RuntimeException('Cannot start worker');
            stream_set_timeout($pipes[1], 20);
            $jobs[] = [$p, $pipes];
        }
        foreach ($jobs as [$p, $pipes]) {
            if (trim((string) fgets($pipes[1])) !== 'READY ppdb_sekolah_test') throw new RuntimeException('Unsafe/unready worker');
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
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($p);
            unset($jobs[$i]);
            if ($exit !== 0) throw new RuntimeException($error);
            $results[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
        echo json_encode($results).PHP_EOL;
        foreach ($results as $r) check($r['elapsed'] >= 0.30, 'worker actually waited on held row lock');
        return $results;
    } finally {
        if (DB::transactionLevel()) DB::rollBack();
        foreach ($jobs as [$p, $pipes]) {
            proc_terminate($p);
            proc_close($p);
        }
    }
}

function coherent(DaftarUlangInvoice $invoice): void
{
    $snapshot = $invoice->pricing_snapshot;
    $revision = DaftarUlangPricingRevision::findOrFail($invoice->pricing_revision_id);
    check($snapshot['items'] == $revision->items && $snapshot['revision'] === $revision->revision
        && $snapshot['source'] === $revision->source, 'snapshot contains exactly one immutable pricing revision');
    $sum = 0;
    foreach ($snapshot['items'] as $item) {
        check($item['line_total'] === $item['quantity'] * $item['unit_amount'], 'line totals are coherent integer Rupiah');
        $sum += $item['line_total'];
    }
    check($sum === $snapshot['subtotal'] && $snapshot['total'] === $sum - $snapshot['discount_nominal'], 'snapshot totals coherent');
    check($invoice->payment->amount === $snapshot['total'] && $invoice->payment->payment_category === 'DAFTAR_ULANG', 'payment comes from snapshot/category');
}

echo "SELECT DATABASE(): {$db}\n";
$tag = bin2hex(random_bytes(6));
$users = [];
$headsBefore = DB::table('du_default_pricings')->get();
$failed = false;
try {
    $actor = User::factory()->create(['email' => "$tag-admin@b5-race.test"]);
    $users[] = $actor->id;
    $actor->forceFill(['role' => 'super_admin'])->save();
    $pricing = app(DaftarUlangPricingService::class);
    $pricing->updateDefault($actor->id, 'SD', items(100000), 10000);
    foreach (['A duplicate invoices', 'B default update versus invoice', 'C custom deactivate versus invoice', 'D concurrent custom updates'] as $case) {
        echo "CASE {$case}\n";
        $user = User::factory()->create(['email' => "$tag-".count($users).'@b5-race.test', 'jenjang' => 'SD']);
        $users[] = $user->id;
        $user->forceFill(['role' => 'orang_tua', 'status_kelulusan' => 'LULUS'])->save();
        // Existing registration seat is a fixture, never a Daftar Ulang action.
        PpdbSeatAllocation::create(['user_id' => $user->id, 'payment_id' => null, 'jenjang' => 'SD', 'status' => 'ALLOCATED', 'allocated_at' => now()]);
        Payment::create(['user_id' => $user->id, 'invoice_number' => "$tag-PPDB-{$user->id}", 'jenjang' => 'SD', 'amount' => 175000, 'status' => 'SUCCESS']);
        $seats = DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson();
        $capacities = DB::table('ppdb_capacities')->orderBy('jenjang')->get()->toJson();
        $ppdb = Payment::ppdb()->orderBy('id')->get()->map->getAttributes()->all();
        $args = fn ($action, $amount = 0) => [$action, (string) $user->id, (string) $actor->id, (string) $amount];
        if (str_starts_with($case, 'A')) {
            $r = race([$args('invoice'), $args('invoice')], 'user', $user->id);
            check($r[0]['invoice_id'] === $r[1]['invoice_id'], 'both attempts return the same canonical invoice');
            check(DaftarUlangInvoice::where('user_id', $user->id)->count() === 1 && Payment::daftarUlang()->where('user_id', $user->id)->count() === 1, 'one invoice and one payment');
        } elseif (str_starts_with($case, 'B')) {
            race([$args('invoice'), $args('default', 300000)], 'default', $user->id);
        } elseif (str_starts_with($case, 'C')) {
            $pricing->updateIndividual($actor->id, $user->id, ['mode' => 'CUSTOM', 'active' => true, 'items' => items(70000), 'discount_nominal' => 5000]);
            $r = race([$args('invoice'), $args('deactivate')], 'user', $user->id);
            check(in_array($r[0]['snapshot']['source'], ['DEFAULT', 'CUSTOM'], true), 'deactivation linearizes to a valid DEFAULT or CUSTOM revision');
            check(!DaftarUlangIndividualPricing::where('user_id', $user->id)->firstOrFail()->active, 'custom ends inactive');
        } else {
            $r = race([$args('custom', 70000), $args('custom', 90000)], 'user', $user->id);
            check($r[0]['revision_id'] !== $r[1]['revision_id'], 'updates append distinct revisions');
            check(DaftarUlangPricingRevision::where('user_id', $user->id)->count() === 2, 'both pricing histories retained');
            foreach (DaftarUlangPricingRevision::where('user_id', $user->id)->get() as $rev) {
                check($rev->total === array_sum(array_column($rev->items, 'line_total')) - $rev->discount_nominal, 'each custom revision has uncorrupted totals');
            }
            app(DaftarUlangService::class)->createInvoice($user->id);
        }
        $invoice = DaftarUlangInvoice::where('user_id', $user->id)->firstOrFail();
        coherent($invoice);
        check($seats === DB::table('ppdb_seat_allocations')->orderBy('id')->get()->toJson(), 'all allocation rows byte-for-byte unchanged');
        check($capacities === DB::table('ppdb_capacities')->orderBy('jenjang')->get()->toJson(), 'all quota capacity rows unchanged');
        check($ppdb === Payment::ppdb()->orderBy('id')->get()->map->getAttributes()->all(), 'all PPDB payments unchanged');
    }
    echo "B5 DOMAIN CONCURRENCY PASSED\n";
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, (string) $e.PHP_EOL);
} finally {
    // Restore pointers before removing only this run's immutable fixture history.
    foreach ($headsBefore as $head) DB::table('du_default_pricings')->where('jenjang', $head->jenjang)->update((array) $head);
    DB::table('du_agreement_acceptances')->whereIn('user_id', $users)->delete();
    DB::table('du_invoices')->whereIn('user_id', $users)->delete();
    DB::table('du_individual_pricings')->whereIn('user_id', $users)->delete();
    DB::table('du_pricing_audits')->whereIn('actor_id', $users)->delete();
    DB::table('du_pricing_revisions')->whereIn('created_by', $users)->delete();
    User::whereIn('id', $users)->delete();
    echo "Scoped fixture cleanup complete\n";
}
exit($failed ? 1 : 0);
