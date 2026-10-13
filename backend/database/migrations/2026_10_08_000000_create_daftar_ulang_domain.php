<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('du_pricing_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('pricing_key', 40);
            $table->unsignedInteger('revision');
            $table->enum('source', ['DEFAULT', 'CUSTOM']);
            $table->string('jenjang', 3);
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('items');
            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('discount_nominal');
            $table->unsignedInteger('total');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['pricing_key', 'revision']);
        });
        Schema::create('du_default_pricings', function (Blueprint $table) {
            $table->string('jenjang', 3)->primary();
            $table->foreignId('pricing_revision_id')->nullable()->constrained('du_pricing_revisions')->restrictOnDelete();
            $table->timestamps();
        });
        // Only lockable heads are seeded, never invented financial amounts.
        \Illuminate\Support\Facades\DB::table('du_default_pricings')->insert([
            ['jenjang' => 'SD'], ['jenjang' => 'SMP'],
        ]);
        Schema::create('du_individual_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->enum('mode', ['DEFAULT', 'CUSTOM'])->default('DEFAULT');
            $table->boolean('active')->default(false);
            $table->foreignId('pricing_revision_id')->nullable()->constrained('du_pricing_revisions')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('du_pricing_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('jenjang', 3);
            $table->json('data');
            $table->timestamp('created_at');
        });
        Schema::create('du_invoices', function (Blueprint $table) {
            $table->id();
            // B.5 policy: one canonical invoice per student; retries return it.
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->string('invoice_number')->unique();
            $table->string('jenjang', 3);
            $table->string('student_name');
            $table->string('jalur_pendaftaran')->nullable();
            $table->foreignId('pricing_revision_id')->constrained('du_pricing_revisions')->restrictOnDelete();
            $table->json('pricing_snapshot');
            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('discount_nominal');
            $table->unsignedInteger('total');
            $table->timestamp('snapshot_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });
        Schema::create('du_agreement_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('du_invoices')->restrictOnDelete();
            $table->string('agreement_version', 50);
            $table->string('agreement_sha256', 64);
            $table->timestamp('agreed_at');
            $table->unique(['invoice_id', 'agreement_version'], 'du_agreement_invoice_version_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('du_agreement_acceptances');
        Schema::dropIfExists('du_invoices');
        Schema::dropIfExists('du_pricing_audits');
        Schema::dropIfExists('du_individual_pricings');
        Schema::dropIfExists('du_default_pricings');
        Schema::dropIfExists('du_pricing_revisions');
    }
};
