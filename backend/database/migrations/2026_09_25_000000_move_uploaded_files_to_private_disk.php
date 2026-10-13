<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Move identity documents and payment evidence off the public disk onto the
 * private disk, and rewrite the stored paths to match. Existing rows stored
 * files under `berkas/...` and `bukti_bayar/...` on the public disk; those
 * were publicly reachable via the storage symlink (no auth).
 */
return new class extends Migration
{
    private array $moved = []; // [ [relativePath, ...], ... ] for rollback

    public function up(): void
    {
        $public  = Storage::disk('public');
        $private = Storage::disk('private');

        // --- Identity documents (berkas) ---
        DB::table('berkas')->orderBy('id')->each(function ($row) use ($public, $private) {
            $update = [];
            foreach (['kartu_keluarga', 'akte_kelahiran'] as $col) {
                $path = $row->{$col};
                if (! $path) continue;

                $new = $this->relocate($public, $private, $path);
                if ($new !== null) {
                    $update[$col] = $new;
                }
            }
            if ($update) {
                DB::table('berkas')->where('id', $row->id)->update($update);
            }
        });

        // --- Payment proofs ---
        DB::table('payments')->whereNotNull('proof_of_payment')->orderBy('id')->each(function ($row) use ($public, $private) {
            $new = $this->relocate($public, $private, $row->proof_of_payment);
            if ($new !== null) {
                DB::table('payments')->where('id', $row->id)->update(['proof_of_payment' => $new]);
            }
        });
    }

    public function down(): void
    {
        // Move any files we relocated back to the public disk and restore paths.
        $public  = Storage::disk('public');
        $private = Storage::disk('private');

        foreach ($this->moved as [$path]) {
            if ($private->exists($path) && ! $public->exists($path)) {
                $public->writeStream($path, $private->readStream($path));
                $private->delete($path);
            }
        }
    }

    /**
     * Move a file from public to private disk. Returns the path to store, or
     * null when nothing was moved (file already private or missing).
     */
    private function relocate($public, $private, ?string $path): ?string
    {
        // Strip an old absolute URL prefix if one was ever stored.
        $path = preg_replace('#^https?://[^/]+/storage/#', '', (string) $path);
        $path = ltrim($path, '/');

        if ($path === '' || $private->exists($path)) {
            return null; // already private
        }

        if (! $public->exists($path)) {
            Log::warning("storage privacy migration: file not found, skipped", ['path' => $path]);
            return null;
        }

        // Preserve the same relative path on the private disk.
        $stream = $public->readStream($path);
        $private->writeStream($path, $stream);
        if (is_resource($stream)) fclose($stream);

        $public->delete($path);
        $this->moved[] = [$path];

        return $path;
    }
};
