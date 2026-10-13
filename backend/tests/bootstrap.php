<?php

/**
 * P4-D.1 — Test-suite database containment bootstrap.
 *
 * PURPOSE
 * -------
 * The PHPUnit suite runs destructive operations against its default connection
 * (RefreshDatabase => `migrate:fresh`, plus unscoped deletes in the
 * tests/Concurrency harnesses). It must therefore be *structurally impossible*
 * for the suite to resolve to the production database `ppdb_sekolah`.
 *
 * THE HAZARD THIS CLOSES (reproduced, not theoretical)
 * ----------------------------------------------------
 * `phpunit.xml` previously relied on `<env>` entries. Those are applied by
 * PHPUnit's PhpHandler with putenv()/$_ENV only — it never touches $_SERVER.
 * Dotenv's default reader chain is [ServerConstAdapter, EnvConstAdapter], i.e.
 * $_SERVER is consulted FIRST. Two independent consequences:
 *
 *   1. Any DB_* variable exported in the shell lands in $_SERVER (PHP's
 *      variables_order is "GPCS") and therefore WINS over `<env force="true">`.
 *      Verified: with DB_DATABASE=ppdb_sekolah exported, Env::get() still
 *      returned the production name.
 *
 *   2. `.env.testing` is gitignored. On a fresh clone or in CI it does not
 *      exist, so Laravel's LoadEnvironmentVariables falls back to `.env` —
 *      which points at `ppdb_sekolah`. Verified: with `.env.testing` absent and
 *      APP_ENV=testing, the suite resolved to `ppdb_sekolah` and the live
 *      connection returned DATABASE() = 'ppdb_sekolah'. `migrate:fresh` would
 *      then have dropped every production table.
 *
 * WHY THIS APPROACH IS AIRTIGHT
 * -----------------------------
 * Laravel builds its Dotenv repository via Env::getRepository(), which is
 * immutable (RepositoryBuilder::immutable() wraps writers in ImmutableWriter).
 * ImmutableWriter::write() refuses to overwrite any key already defined in the
 * reader chain ($_SERVER / $_ENV). So values pinned here — before any Laravel
 * bootstrap — cannot be overridden by `.env`, `.env.testing`, `.env.production`,
 * or a cached config file. Writing all three sinks ($_SERVER, $_ENV, putenv)
 * also defeats any code path that calls getenv() directly.
 *
 * This bootstrap performs NO writes to any database. It only reads env files
 * and opens a single read-only connection for verification.
 */

declare(strict_types=1);

/** The ONLY database name the test suite may ever connect to. */
const PPDB_TEST_DATABASE = 'ppdb_sekolah_test';

/** Database names that must never appear as a test target. */
const PPDB_FORBIDDEN_DATABASES = ['ppdb_sekolah', 'ppdb_sekolah_prod', 'ppdb'];

if (! function_exists('ppdb_test_bootstrap_fail')) {
    /**
     * Abort the whole run, loudly, without touching any database.
     *
     * @param  list<string>  $lines
     * @return never
     */
    function ppdb_test_bootstrap_fail(array $lines): void
    {
        fwrite(STDERR, PHP_EOL.str_repeat('=', 78).PHP_EOL);
        fwrite(STDERR, 'TEST SUITE REFUSED TO START — database containment failed'.PHP_EOL);
        fwrite(STDERR, str_repeat('=', 78).PHP_EOL);
        foreach ($lines as $line) {
            fwrite(STDERR, $line.PHP_EOL);
        }
        fwrite(STDERR, str_repeat('=', 78).PHP_EOL.PHP_EOL);

        exit(1);
    }
}

if (! function_exists('ppdb_test_parse_env_file')) {
    /**
     * Minimal dotenv reader. Returns [] when the file is absent.
     *
     * Deliberately not reusing vlucas/phpdotenv here: this bootstrap runs
     * before Composer's autoloader is guaranteed to be primed for the test
     * context, and we must not let Dotenv's own resolution order participate
     * in deciding the test target.
     *
     * @return array<string, string>
     */
    function ppdb_test_parse_env_file(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $values = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip matching surrounding quotes, mirroring dotenv semantics.
            if (strlen($value) >= 2) {
                $quote = $value[0];
                if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
                    $value = substr($value, 1, -1);
                }
            }

            $values[$key] = $value;
        }

        return $values;
    }
}

if (! function_exists('ppdb_test_pin_env')) {
    /**
     * Pin a value into every sink Dotenv can read from, so the immutable
     * repository can never replace it from an env file afterwards.
     */
    function ppdb_test_pin_env(string $name, string $value): void
    {
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv($name.'='.$value);
    }
}

$backendRoot = dirname(__DIR__);

/*
|--------------------------------------------------------------------------
| 1. Load the disposable test credentials from .env.testing
|--------------------------------------------------------------------------
|
| `.env.testing` is gitignored, so its absence is the expected state on a
| fresh clone and in CI. We refuse to start rather than silently falling back
| to `.env`, because that fallback is precisely what pointed the suite at
| production in the first place.
|
*/

$testingEnvPath = $backendRoot.'/.env.testing';

if (! is_file($testingEnvPath)) {
    ppdb_test_bootstrap_fail([
        '',
        '  Missing required file:',
        '    backend/.env.testing',
        '',
        '  This file is gitignored, so it does not exist on a fresh clone. It holds',
        '  the credentials for the DISPOSABLE test database ('.PPDB_TEST_DATABASE.') and is',
        '  the only supported source of test DB configuration.',
        '',
        '  The suite refuses to fall back to backend/.env, because .env points at the',
        '  production database `ppdb_sekolah` and the tests run `migrate:fresh`,',
        '  which drops every table in the connected database.',
        '',
        '  Create it now:',
        '    cp backend/.env.testing.example backend/.env.testing',
        '    # then edit DB_USERNAME / DB_PASSWORD to match your local test user',
        '',
        '  No database changes were made.',
        '',
    ]);
}

$testingEnv = ppdb_test_parse_env_file($testingEnvPath);

/*
|--------------------------------------------------------------------------
| 2. Resolve the test connection parameters
|--------------------------------------------------------------------------
|
| The database name is hard-coded to the disposable schema. It is never taken
| from an env file, an exported shell variable, or a caller — only validated
| against them. This removes the entire class of "wrong file loaded" bugs.
|
*/

$testDatabase = PPDB_TEST_DATABASE;

// The env file may state a different DB_DATABASE (typo, copy of .env, stale
// value). Trust the constant, but surface the mismatch so it cannot hide.
$fileDatabase = $testingEnv['DB_DATABASE'] ?? null;
if ($fileDatabase !== null && $fileDatabase !== $testDatabase) {
    fwrite(STDERR, PHP_EOL.'  WARNING: .env.testing declares DB_DATABASE='.$fileDatabase.PHP_EOL);
    fwrite(STDERR, '           Overriding to the disposable target '.$testDatabase.PHP_EOL.PHP_EOL);
}

$testHost = $testingEnv['DB_HOST'] ?? '127.0.0.1';
$testPort = $testingEnv['DB_PORT'] ?? '3306';
$testUser = $testingEnv['DB_USERNAME'] ?? '';
$testPassword = $testingEnv['DB_PASSWORD'] ?? '';

if ($testUser === '') {
    ppdb_test_bootstrap_fail([
        '',
        '  backend/.env.testing does not define DB_USERNAME.',
        '',
        '  A dedicated, least-privilege MySQL user is required so the test suite',
        '  cannot reach the production schema even if its configuration is wrong.',
        '',
        '  Expected shape:',
        '    DB_USERNAME='.PPDB_TEST_DATABASE,
        '    DB_PASSWORD=<password for that user>',
        '',
        '  That user must be granted ONLY: GRANT ALL ON `'.PPDB_TEST_DATABASE.'`.*',
        '',
        '  No database changes were made.',
        '',
    ]);
}

/*
|--------------------------------------------------------------------------
| 3. Pin every DB_* sink BEFORE Laravel bootstraps
|--------------------------------------------------------------------------
|
| Because Dotenv is immutable in Laravel, these values survive LoadEnvironment-
| Variables unchanged: `.env`, `.env.testing`, `.env.production` and any cached
| config can no longer influence the test target.
|
| DB_URL is cleared explicitly: config/database.php passes `url` to
| ConfigurationUrlParser, and a non-empty URL overrides host/port/database,
| letting an unrelated env var silently redirect the connection.
|
| DB_SOCKET is cleared explicitly: `.env` sets it to the local unix socket, and
| PDO prefers a socket over host/port — which would route the "test" connection
| to whatever server owns that socket.
|
*/

ppdb_test_pin_env('APP_ENV', 'testing');
ppdb_test_pin_env('DB_CONNECTION', 'mysql');
ppdb_test_pin_env('DB_DATABASE', $testDatabase);
ppdb_test_pin_env('DB_HOST', $testHost);
ppdb_test_pin_env('DB_PORT', (string) $testPort);
ppdb_test_pin_env('DB_USERNAME', $testUser);
ppdb_test_pin_env('DB_PASSWORD', $testPassword);
ppdb_test_pin_env('DB_URL', '');
ppdb_test_pin_env('DB_SOCKET', '');

/*
|--------------------------------------------------------------------------
| 4. Verify containment with a real (read-only) connection
|--------------------------------------------------------------------------
|
| Configuration alone is not proof — a DB_URL or socket override can redirect
| the live connection while leaving the configured name intact. We ask MySQL
| which schema it actually selected. SELECT DATABASE() reads nothing.
|
| If the disposable database does not exist or the credentials are wrong we
| abort here, before any test (and therefore before any migrate:fresh) runs.
|
*/

try {
    $probe = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $testHost, $testPort),
        $testUser,
        $testPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
        ]
    );
} catch (Throwable $e) {
    ppdb_test_bootstrap_fail([
        '',
        '  Could not connect to MySQL with the .env.testing credentials:',
        '    host = '.$testHost.':'.$testPort,
        '    user = '.$testUser,
        '    error: '.$e->getMessage(),
        '',
        '  Fix backend/.env.testing, or start MySQL. The suite will not guess.',
        '',
        '  No database changes were made.',
        '',
    ]);
}

// Confirm the target schema exists and is reachable by this user only.
$schemas = [];
try {
    $stmt = $probe->query('SHOW DATABASES');
    $schemas = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (Throwable $e) {
    $schemas = []; // privileges may hide SHOW DATABASES; not fatal by itself
}

if ($schemas !== [] && ! in_array($testDatabase, $schemas, true)) {
    ppdb_test_bootstrap_fail([
        '',
        '  The disposable test database does not exist:',
        '    expected: '.$testDatabase,
        '    visible:  '.implode(', ', $schemas),
        '',
        '  Create it once (as a MySQL administrator):',
        '    CREATE DATABASE '.$testDatabase.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        '    GRANT ALL PRIVILEGES ON '.$testDatabase.'.* TO \''.$testUser.'\'@\'localhost\';',
        '    FLUSH PRIVILEGES;',
        '',
        '  No database changes were made.',
        '',
    ]);
}

// Prove the production schema is NOT reachable from the test credentials.
// This is the least-privilege guarantee the harnesses rely on.
$leaks = [];
foreach (PPDB_FORBIDDEN_DATABASES as $forbidden) {
    if (in_array($forbidden, $schemas, true)) {
        $leaks[] = $forbidden;
    }
}

if ($leaks !== [] && $schemas !== []) {
    fwrite(STDERR, PHP_EOL.'  WARNING: the test MySQL user can also see: '.implode(', ', $leaks).PHP_EOL);
    fwrite(STDERR, '           Recommended: GRANT ALL ON `'.PPDB_TEST_DATABASE.'`.* only.'.PHP_EOL.PHP_EOL);
}

// Select the disposable schema and ask the server to confirm it.
try {
    $probe->exec('USE `'.$testDatabase.'`');
    $live = $probe->query('SELECT DATABASE()');
    $liveDatabase = $live ? (string) $live->fetchColumn() : '';
} catch (Throwable $e) {
    ppdb_test_bootstrap_fail([
        '',
        '  Connected to MySQL but could not select '.$testDatabase.':',
        '    '.$e->getMessage(),
        '',
        '  No database changes were made.',
        '',
    ]);
}

if ($liveDatabase !== $testDatabase) {
    ppdb_test_bootstrap_fail([
        '',
        '  Refusing to run: the live connection resolved to the wrong database.',
        '    expected : '.$testDatabase,
        '    actual   : '.var_export($liveDatabase, true),
        '',
        '  No database changes were made.',
        '',
    ]);
}

if (in_array($liveDatabase, PPDB_FORBIDDEN_DATABASES, true)) {
    ppdb_test_bootstrap_fail([
        '',
        '  Refusing to run: the live connection resolved to a production database:',
        '    '.$liveDatabase,
        '',
        '  No database changes were made.',
        '',
    ]);
}

$probe = null; // close the probe; tests open their own pooled connection

/*
|--------------------------------------------------------------------------
| 5. Hand over to Composer's autoloader
|--------------------------------------------------------------------------
|
| Pinning happened above so that the moment Laravel's LoadEnvironmentVariables
| runs (inside each TestCase's createApplication()), the immutable repository
| already sees these values and leaves them alone.
|
*/

require $backendRoot.'/vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| 6. Refuse to run against a cached config file
|--------------------------------------------------------------------------
|
| THE ONE THING THE PIN ABOVE CANNOT FIX
| --------------------------------------
| Every check so far proves that the *env* resolves to ppdb_sekolah_test. But
| LoadConfiguration::bootstrap() short-circuits when bootstrap/cache/config.php
| exists:
|
|     } elseif (file_exists($cached = $app->getCachedConfigPath())) {
|         $items = require $cached;
|         $loadedFromCache = true;
|     }
|     ...
|     if (! $loadedFromCache) {
|         $this->loadConfigurationFiles($app, $config);   // <- never runs
|     }
|
| When that happens Laravel never calls env(), so the $_SERVER pin is dead
| letter and config('database.connections.mysql.database') is whatever was
| baked into the cache. That cache is built from `.env` -- production.
|
| Reproduced, not theoretical. With `php artisan config:cache` run beforehand:
|
|     config_loaded_from_cache = true
|     $_SERVER['DB_DATABASE']  = 'ppdb_sekolah_test'   <- correctly pinned
|     env('DB_DATABASE')       = 'ppdb_sekolah_test'   <- correctly pinned
|     config('...mysql.database') = 'ppdb_sekolah'     <- PRODUCTION
|
| Note also that the read-only PDO probe in step 4 cannot catch this: it
| connects using $testHost/$testUser/$testDatabase read straight from
| .env.testing, so it verifies that the *credentials* reach the disposable
| schema, not that *Laravel* will connect there. Both checks are needed and
| neither subsumes the other.
|
| We delete nothing and silently fix nothing. Failing loudly is correct: a
| cached config is a developer action, and quietly overriding it would hide
| that the app they are testing is not the app that is configured. The suite
| also runs `config:clear` first via `composer test`, so this only trips when
| someone cached config out-of-band.
|
*/

$cachedConfigPath = $backendRoot.'/bootstrap/cache/config.php';

if (is_file($cachedConfigPath)) {
    $cachedDatabase = null;

    try {
        // Read-only inspection for the diagnostic message. Requiring the file
        // executes no application code -- it is a plain `return [...]` array.
        $cached = require $cachedConfigPath;
        $default = $cached['database']['default'] ?? null;
        $cachedDatabase = $cached['database']['connections'][$default]['database'] ?? null;
    } catch (Throwable $e) {
        $cachedDatabase = null;
    }

    $isProduction = is_string($cachedDatabase)
        && in_array($cachedDatabase, PPDB_FORBIDDEN_DATABASES, true);

    // Build the message as a sequential list. Do NOT use the array union
    // operator (`+`) to splice the conditional lines in: on integer-keyed
    // arrays `+` keeps the LEFT operand's existing keys and silently discards
    // every element of the right operand whose key already exists, so the
    // production warning and the `config:clear` remedy would vanish from the
    // output while the code still looked correct.
    $lines = [
        '',
        '  A cached configuration file exists:',
        '    backend/bootstrap/cache/config.php',
        '',
        '  Cached default database : '.var_export($cachedDatabase, true),
        '  Required test database  : '.$testDatabase,
        '',
        '  When this cache is present, Laravel\'s LoadConfiguration skips',
        '  loadConfigurationFiles() entirely and never calls env(). The DB_*',
        '  values pinned by this bootstrap are therefore ignored, and the suite',
        '  would run `migrate:fresh` against whatever the cache says.',
        '',
    ];

    if ($isProduction) {
        $lines[] = '  It says: '.$cachedDatabase.' -- the PRODUCTION database.';
        $lines[] = '  Every table in it would have been dropped.';
        $lines[] = '';
    }

    $lines[] = '  Clear the cache and re-run:';
    $lines[] = '    php artisan config:clear';
    $lines[] = '';
    $lines[] = '  (composer test does this for you.)';
    $lines[] = '';
    $lines[] = '  No database changes were made.';
    $lines[] = '';

    ppdb_test_bootstrap_fail($lines);
}

fwrite(STDERR, '  [P4-D.1] test database pinned and verified: '.$liveDatabase.PHP_EOL);
