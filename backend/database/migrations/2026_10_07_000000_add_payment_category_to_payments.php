<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One atomic MySQL/MariaDB ALTER: existing rows receive PPDB without
        // rewriting ownership, amounts, states, or any seat allocations.
        DB::statement("ALTER TABLE payments
            ADD COLUMN payment_category ENUM('PPDB', 'DAFTAR_ULANG') NOT NULL DEFAULT 'PPDB',
            ADD CONSTRAINT payments_category_check CHECK (payment_category IN ('PPDB', 'DAFTAR_ULANG')),
            ADD UNIQUE KEY payments_category_invoice_unique (payment_category, invoice_number),
            ADD INDEX payments_user_category_id_index (user_id, payment_category, id),
            DROP INDEX payments_invoice_number_unique");
    }

    public function down(): void
    {
        // Dropping the discriminator must not reinterpret Daftar Ulang as PPDB.
        if (DB::table('payments')->where('payment_category', 'DAFTAR_ULANG')->exists()) {
            throw new RuntimeException('Cannot roll back while Daftar Ulang payments exist.');
        }
        // Never silently discard cross-category invoices to restore global uniqueness.
        if (DB::table('payments')->select('invoice_number')->groupBy('invoice_number')
            ->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back category isolation while invoice numbers overlap.');
        }
        $dropCheck = str_contains(DB::selectOne('SELECT VERSION() AS version')->version, 'MariaDB')
            ? 'DROP CONSTRAINT' : 'DROP CHECK';
        DB::statement("ALTER TABLE payments
            ADD UNIQUE KEY payments_invoice_number_unique (invoice_number),
            DROP INDEX payments_category_invoice_unique,
            DROP INDEX payments_user_category_id_index,
            {$dropCheck} payments_category_check,
            DROP COLUMN payment_category");
    }
};
