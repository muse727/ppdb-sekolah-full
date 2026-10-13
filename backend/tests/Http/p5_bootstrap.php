<?php

// Reuse the suite's fail-closed DB containment under both CLI and cli-server.
if (! defined('STDERR')) define('STDERR', fopen('php://stderr', 'w'));
require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (\Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'ppdb_sekolah_test') throw new RuntimeException('P5 HTTP verification requires the disposable database.');
config([
    'app.key' => 'base64:MWQxMTIzM2Q0NTU2Nzc4OGFiY2RlZjAxMjM0NTY3ODk=',
    'filesystems.disks.private.root' => storage_path('framework/testing/p5-http-private'), 'cors.allowed_origins' => ['http://localhost:3005'],
    'session.driver' => 'file', 'session.cookie' => 'p5_test_session', 'session.domain' => null, 'session.secure' => false,
    'sanctum.stateful' => ['localhost:3005', 'localhost:8015'],
]);
return $app;
