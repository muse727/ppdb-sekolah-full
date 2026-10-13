<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Production mail delivery

Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` and `MAIL_MAILER` to a real transport (for example `smtp` with `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, and `MAIL_FROM_ADDRESS`). Never deploy with `MAIL_MAILER=log` or `array`: these expose or discard SMP registration codes. The app fails startup if a production mail transport logs/discards mail, including failover paths. Local log-mail is development-only; do not ship local mail logs. Configure a real `SESSION_DOMAIN` and HTTPS for your deployed host. Rebuild config cache after changing environment values.

## Running the test suite (read this first)

The test suite is **destructive**: `RefreshDatabase` runs `migrate:fresh` against
the default connection, and the `tests/Concurrency` harnesses execute unscoped
`DELETE` statements. The suite must therefore never resolve to the production
database `ppdb_sekolah`.

Setup, once per machine:

```bash
cd backend
cp .env.testing.example .env.testing   # then edit DB_USERNAME / DB_PASSWORD
```

Create the disposable database and grant a least-privilege user for it:

```sql
CREATE DATABASE ppdb_sekolah_test
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ppdb_sekolah_test'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON `ppdb_sekolah_test`.* TO
  'ppdb_sekolah_test'@'localhost';
FLUSH PRIVILEGES;
```

Then run `composer test` (or `php vendor/bin/phpunit`).

**If `.env.testing` is missing, the suite refuses to start.** That is intentional.
The file is gitignored, so it does not exist on a fresh clone or in CI; without
it Laravel falls back to `.env`, which points at `ppdb_sekolah`.

Containment is enforced in three independent layers, because no single one is
sufficient on its own:

1. `tests/bootstrap.php` pins `DB_*` into `$_SERVER` before Laravel bootstraps.
   Laravel's Dotenv repository is immutable, so no env file can override those
   values afterwards. `<env force="true">` in `phpunit.xml` cannot achieve this
   on its own, because PHPUnit writes only `putenv()`/`$_ENV` while Dotenv reads
   `$_SERVER` first.
2. The bootstrap hard-codes the target schema, verifies it against a live
   `SELECT DATABASE()`, and aborts the run if it disagrees.
3. `tests/TestCase.php` re-asserts the live connection before every test, in
   `setUpTraits()` — which runs *before* `migrate:fresh`, unlike `setUp()`.

There is one case layers 1–2 cannot cover: a **cached config**
(`bootstrap/cache/config.php`). When that file exists, Laravel's
`LoadConfiguration` loads it directly and never calls `env()`, so the
`$_SERVER` pin is bypassed and the connection resolves to whatever was baked
into the cache — which is built from the production `.env`. The bootstrap
therefore refuses to start if a cached config is present; run
`php artisan config:clear` first. (`composer test` does this automatically.)

The concurrency harnesses have their own equivalent guard in
`tests/Concurrency/ConcurrencySafetyGuard.php`; run them with
`APP_ENV=testing php tests/Concurrency/<harness>.php`.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
