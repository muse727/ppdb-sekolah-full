<?php

/**
 * P4-A.2-H — Concurrency harness safety guard.
 *
 * The harnesses in this directory issue DESTRUCTIVE, unscoped queries against
 * the live connection, e.g.:
 *
 *     PpdbSeatAllocation::query()->delete();
 *     Payment::query()->delete();
 *
 * Bootstrapping Laravel from a plain `php harness.php` loads `.env`, which
 * points at the real `ppdb_sekolah` database. Running a harness that way would
 * wipe preserved PPDB data. Documentation alone is not a safeguard, so every
 * harness MUST call assert_disposable_concurrency_target() immediately after
 * bootstrap and before its first database operation.
 *
 * The check fails closed. BOTH conditions must hold, so setting APP_ENV alone
 * is not enough to reach a production database:
 *
 *   1. APP_ENV === 'testing'
 *   2. the LIVE connection resolves to exactly 'ppdb_sekolah_test'
 *
 * Condition 2 is evaluated against the live connection rather than only
 * config('database.connections.mysql.database'), because a DB_URL override can
 * redirect the real target while leaving the configured name untouched. The
 * config value is checked as well, since the harnesses use it to build their
 * competing connection.
 *
 * There is deliberately no --force flag, no bypass environment variable, and no
 * confirmation prompt: these are automated harnesses and must refuse outright.
 */

use Illuminate\Support\Facades\DB;

if (! function_exists('assert_disposable_concurrency_target')) {
    /**
     * Abort with a non-zero exit code unless we are provably on the disposable
     * testing database. Performs no writes of any kind.
     */
    function assert_disposable_concurrency_target(): void
    {
        $requiredEnv = 'testing';
        $requiredDatabase = 'ppdb_sekolah_test';

        $actualEnv = (string) env('APP_ENV');

        // Resolving the database name opens a read-only connection. If that
        // itself fails we cannot prove safety, so treat it as a refusal.
        $actualDatabase = null;
        $resolveError = null;
        try {
            $actualDatabase = (string) DB::connection()->getDatabaseName();
        } catch (\Throwable $e) {
            $resolveError = $e->getMessage();
        }

        // The harnesses open their competing "connection B" from this config
        // array rather than from the live connection, so both must agree. A
        // DB_URL override can redirect the live connection while leaving
        // config('...database') holding the raw .env value.
        $configDatabase = (string) config('database.connections.mysql.database');

        $envOk = $actualEnv === $requiredEnv;
        $dbOk = $resolveError === null && $actualDatabase === $requiredDatabase;
        $configOk = $configDatabase === $requiredDatabase;

        if ($envOk && $dbOk && $configOk) {
            return;
        }

        $shownDatabase = $resolveError !== null
            ? 'UNRESOLVABLE'
            : ($actualDatabase === '' ? '(empty)' : $actualDatabase);
        $shownConfigDatabase = $configDatabase === '' ? '(empty)' : $configDatabase;

        $lines = [
            '',
            '[SAFETY GUARD] Refusing to run concurrency harness.',
            '',
            sprintf('  APP_ENV    expected "%s"%s got "%s"', $requiredEnv, str_repeat(' ', max(1, 18 - strlen($requiredEnv))), $actualEnv === '' ? '(empty)' : $actualEnv),
            sprintf('  database   expected "%s"%s got "%s"', $requiredDatabase, str_repeat(' ', max(1, 18 - strlen($requiredDatabase))), $shownDatabase),
            sprintf('  cfg db     expected "%s"%s got "%s"', $requiredDatabase, str_repeat(' ', max(1, 18 - strlen($requiredDatabase))), $shownConfigDatabase),
            '',
        ];

        if ($resolveError !== null) {
            $lines[] = '  The live database could not be resolved, so safety cannot be proven:';
            $lines[] = '    '.$resolveError;
            $lines[] = '';
        }

        $lines = array_merge($lines, [
            '  These harnesses execute DESTRUCTIVE, unscoped DELETE statements against',
            '  the live connection (e.g. PpdbSeatAllocation::query()->delete()). They may',
            '  only run against the dedicated disposable testing database.',
            '',
            '  Both conditions are required; setting APP_ENV=testing while the application',
            '  still points at ppdb_sekolah is NOT sufficient.',
            '',
            '  Safe invocation:',
            '    APP_ENV=testing php backend/tests/Concurrency/<harness>.php',
            '',
            '  No database changes were made.',
            '',
        ]);

        fwrite(STDERR, implode(PHP_EOL, $lines));

        exit(1);
    }
}
