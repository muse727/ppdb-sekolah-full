<?php

namespace Tests\Feature;

use Tests\TestCase;

class CredentialLoggingPolicyTest extends TestCase
{
    public function test_application_source_contains_no_credential_logging_calls(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $source = file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression('/\b(?:Log::(?:info|debug|error|warning|notice|critical|alert|emergency)|logger|error_log)\s*\(/i', $source, $file->getPathname());
        }
    }

    public function test_production_rejects_discard_and_unsafe_failover(): void
    {
        $provider = new \App\Providers\AppServiceProvider($this->app);
        config(['app.env' => 'production', 'mail.mailers.null.transport' => 'null']);
        foreach (['log', 'array', 'null', 'failover'] as $mailer) {
            config(['mail.default' => $mailer, 'mail.mailers.failover.mailers' => ['smtp', 'log']]);
            try {
                $provider->boot();
                $this->fail("Production accepted unsafe mailer {$mailer}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Production mail transport', $e->getMessage());
            }
        }
        config(['mail.default' => 'smtp']);
        $provider->boot();
    }
}
