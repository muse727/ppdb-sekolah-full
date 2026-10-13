<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /**
     * P4-D.1 — Per-test database containment assertion.
     *
     * tests/bootstrap.php already pins the DB_* variables into $_SERVER before
     * Laravel bootstraps, and because Laravel's Dotenv repository is immutable
     * no env file can undo that. This hook is the second line of defence.
     *
     * WHY setUpTraits() AND NOT setUp()
     * --------------------------------
     * setUp() is useless for this purpose. InteractsWithTestCaseLifecycle::
     * setUpTheTestEnvironment() calls setUpTraits(), whose FIRST action is
     * `$this->refreshDatabase()` when the RefreshDatabase trait is present.
     * RefreshDatabase::refreshTestDatabase() then runs
     * `migrate:fresh` — which DROPS EVERY TABLE in the connected schema. By the
     * time parent::setUp() returns and a setUp() assertion could run, the data
     * is already gone.
     *
     * setUpTraits() is the correct seam: the application is already bootstrapped
     * (refreshApplication() ran earlier in setUpTheTestEnvironment), so the
     * default connection is live and inspectable, but no destructive migration
     * has happened yet. Asserting here therefore aborts BEFORE migrate:fresh.
     *
     * The check is also cheap enough to run on every test: RefreshDatabaseState
     * ::$migrated means migrate:fresh only fires once per process, but
     * connections can be repurposed later (a test calling config() + DB::purge(),
     * a service provider, a cached config file). Asserting per test catches that.
     *
     * WHY THE LIVE CONNECTION AND NOT config()
     * ----------------------------------------
     * ConfigurationUrlParser::parseConfiguration() array_merges a parsed `url`
     * over the connection array, so config('database.connections.mysql.database')
     * can read 'ppdb_sekolah_test' while the actual target is whatever DB_URL
     * points at. Only the server knows the truth, so that is what we ask.
     *
     * @see tests/bootstrap.php
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $this->assertTestingDatabaseIsDisposable();

        return parent::setUpTraits();
    }

    /**
     * Fail the test unless the default connection resolves to the disposable
     * testing database.
     *
     * Read-only: getDatabaseName() issues SELECT DATABASE(), which touches no
     * rows. This assertion is intentionally allowed to throw rather than to pass
     * silently — an unverifiable connection is not a safe connection.
     */
    private function assertTestingDatabaseIsDisposable(): void
    {
        // The one allowed target. Must stay in sync with tests/bootstrap.php,
        // which hard-codes the same name.
        $required = 'ppdb_sekolah_test';

        // Names that must never be a test target. `ppdb_sekolah` holds live PPDB
        // data (users, payments, seat allocations); `migrate:fresh` would drop
        // every table in it. The others are plausible near-misses and aliases.
        $forbidden = ['ppdb_sekolah', 'ppdb_sekolah_prod', 'ppdb_production', 'ppdb'];

        $live = null;

        try {
            $live = (string) DB::connection()->getDatabaseName();
        } catch (Throwable $e) {
            $this->fail(
                'Database containment could not be verified (connection failed): '
                .$e->getMessage().' — refusing to continue.'
            );
        }

        $message = sprintf(
            'Database containment violated: the default connection resolved to "%s", '
            .'but the test suite may only ever use "%s". RefreshDatabase runs '
            .'`migrate:fresh` against this connection, so continuing would destroy '
            .'that data. Check backend/.env.testing and tests/bootstrap.php.',
            $live === '' ? '(empty)' : $live,
            $required
        );

        $this->assertNotContains($live, $forbidden, $message);
        $this->assertSame($required, $live, $message);
    }
}
