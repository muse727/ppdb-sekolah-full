<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E.12G — idempotency_keys: 24h-retention replay records for observasi
 * submit/rekapan-edit/override.
 *
 * Lifecycle (see IdempotencyKey model):
 *  - INSERT (claim) happens inside the business transaction, after all
 *    business-row locks.
 *  - response_code/response_body are completed in that same transaction.
 *  - UNIQUE(key_hash) is the serialization point for concurrent same-key
 *    requests; the loser lockForUpdate-waits on the winner's row.
 *
 * No secrets, no jawaban_json — only hashes and the stored RESPONSE (which is
 * what the same actor would receive on replay anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->char('key_hash', 64);                       // SHA-256(endpoint_class|actor_id|client_key)
            $table->string('endpoint_class', 50);               // observasi_submit | observasi_override
            $table->unsignedBigInteger('actor_id');
            $table->char('payload_hash', 64);                   // SHA-256(canonical payload), fixed at claim
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('users')->onDelete('cascade');

            $table->unique('key_hash', 'uq_key_hash');
            $table->index(['expires_at'], 'idx_expires_at');    // retention purge
            $table->index(['endpoint_class', 'actor_id'], 'idx_class_actor');
        });
    }

    public function down(): void
    {
        // Dropping loses replay ability only (hasil data untouched). After drop,
        // retries fall back to optimistic concurrency (409 on stale version).
        Schema::dropIfExists('idempotency_keys');
    }
};
