<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3-C: SMP email-token issuance/verification ledger.
 *
 * Why a ledger (not a single row per candidate):
 * - Every issuance gets its own row with the HMAC-SHA256 digest of the secret,
 *   so resend/regenerate invalidates the previous active token simply by
 *   issuing a newer one (verify accepts only the LATEST active digest) and the
 *   full history stays auditable.
 * - Only the keyed digest is stored, never the raw secret. Lookup stays an
 *   indexed exact match because the code is random and normalization is
 *   deterministic — no per-row salted password hash needed.
 * - Identity binding (email + nama) is server-owned, fixed at issuance inside
 *   the email's serialization lock; request fields can never rebind it later.
 * - issued_at / expires_at / consumed_at / verified_at make the lifecycle
 *   explicit; used_by_user_id ties the successful registration to exactly one
 *   issuance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smp_email_tokens', function (Blueprint $table) {
            $table->id();
            // HMAC-SHA256 hex digest (64 chars) of the secret. Never raw.
            $table->string('token_digest', 64)->index();
            $table->string('email')->index();
            $table->string('nama');
            $table->string('jenjang', 10)->default('SMP');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('used_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['email', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smp_email_tokens');
    }
};
