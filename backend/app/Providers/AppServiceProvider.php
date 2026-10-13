<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // B.7.4-E.2 (F-3) — consultation rate limiting. Keyed by the
        // AUTHENTICATED user id (never by IP: a Sanctum SPA behind a school
        // NAT/proxy would let one shared IP throttle every staff member, and
        // home families share mobile IPs). Separate buckets per operation
        // class so a burst of admin queue browsing can never exhaust the
        // schedule budget, and one parent's activity never throttles another.
        // Thresholds chosen generously above legitimate workflows:
        //   - parent writes (create/cancel) 10/min: a real parent submits one
        //     request and at most one cancel; 10 covers retries and double
        //     clicks while still stopping scripted spam.
        //   - parent reads (history) 30/min: a page render plus background
        //     refreshes after mutations.
        //   - admin reads (list + filters) 60/min: filter clicks are chatty by
        //     design (tab, jenjang, jalur, search debounce each fire a request).
        //   - admin writes (schedule/reschedule/resolve) 30/min: a staff
        //     member working a queue schedules/resolves in seconds, not in
        //     bursts of dozens.
        RateLimiter::for('du-consult-parent-write', function (Request $request) {
            return self::consultationLimit(10)->by('du-consult-pw:' . $request->user()?->id);
        });
        RateLimiter::for('du-consult-parent-read', function (Request $request) {
            return self::consultationLimit(30)->by('du-consult-pr:' . $request->user()?->id);
        });
        RateLimiter::for('du-consult-admin-read', function (Request $request) {
            return self::consultationLimit(60)->by('du-consult-ar:' . $request->user()?->id);
        });
        RateLimiter::for('du-consult-admin-write', function (Request $request) {
            return self::consultationLimit(30)->by('du-consult-aw:' . $request->user()?->id);
        });

        if (config('app.env') === 'production') {
            $mailer = config('mail.default');
            $unsafe = ['log', 'array', 'null'];
            $transport = config("mail.mailers.{$mailer}.transport");
            $fallbacks = in_array($transport, ['failover', 'roundrobin'], true)
                ? config("mail.mailers.{$mailer}.mailers", [])
                : [];
            foreach ($fallbacks as $fallback) {
                if (in_array(config("mail.mailers.{$fallback}.transport"), $unsafe, true)) {
                    throw new \RuntimeException('Production mail transport must deliver mail, not log or discard it.');
                }
            }
            if (in_array($transport, $unsafe, true) || !$transport) {
                throw new \RuntimeException('Production mail transport must deliver mail, not log or discard it.');
            }
        }
    }

    /**
     * B.7.4-E.2 (F-3) — one per-minute Limit whose 429 body follows the
     * application's normal JSON error convention ({"message": ...} in
     * Indonesian, like SmpEmailTokenController) so the existing frontend
     * httpErrorMessage helper renders it as-is. Standard Retry-After /
     * RateLimit headers are preserved from the middleware.
     */
    private static function consultationLimit(int $maxAttempts): Limit
    {
        return Limit::perMinute($maxAttempts)->response(function (Request $request, array $headers) {
            $seconds = $headers['Retry-After'] ?? 60;
            return response()->json([
                'message' => "Terlalu banyak permintaan. Coba lagi dalam {$seconds} detik.",
            ], 429, $headers);
        });
    }
}
