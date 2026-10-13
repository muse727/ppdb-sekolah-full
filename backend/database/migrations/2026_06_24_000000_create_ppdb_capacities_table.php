<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dedicated capacity table — one row per jenjang (SD / SMP).
     *
     * Stores ONLY the configured TOTAL (max_seats). The "remaining" count is
     * NEVER stored as a writable column; it is always derived as
     *   remaining = max_seats - (active seat allocations)
     * by PpdbCapacityService. This keeps the database authoritative and makes
     * it impossible for a stored "sisa" value to drift out of sync.
     *
     * SD and SMP are independent rows, so each is locked independently and one
     * jenjang's exhaustion can never block or starve the other.
     */
    public function up(): void
    {
        Schema::create('ppdb_capacities', function (Blueprint $table) {
            $table->string('jenjang', 3)->primary(); // 'SD' | 'SMP'
            $table->unsignedInteger('max_seats');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppdb_capacities');
    }
};
