<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3-C: explicit expiration semantics for UMUM registration tokens.
 *
 * Policy: tokens issued before this migration keep working (expires_at NULL =
 * no expiry; they were generated under the old no-expiry contract). Every
 * token issued AFTER this change MUST carry expires_at (enforced by
 * UmumTokenService::generateBatch, which always sets it). Expired tokens are
 * rejected at registration time and are NEVER consumed.
 *
 * Chosen lifetime: 30 days (UmumTokenService::TTL_DAYS) — a PPDB window is
 * weeks long, so tokens distributed on paper/WhatsApp must stay valid for the
 * whole window, while still bounded so a leaked code eventually dies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index()->after('is_used');
        });
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
