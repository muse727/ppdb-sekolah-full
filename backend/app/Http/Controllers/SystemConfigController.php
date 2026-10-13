<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use App\Models\Whitelist;
use App\Services\PpdbCapacityService;
use App\Services\InternalAccessCodeService;
use App\Exceptions\CapacityException;
use Illuminate\Support\Facades\DB;

class SystemConfigController extends Controller
{
    // === 1. PENGATURAN UMUM ===
    public function getSettings(PpdbCapacityService $capacity)
    {
        $sd  = $capacity->getCapacity('SD');
        $smp = $capacity->getCapacity('SMP');

        return response()->json([
            'ppdb_status' => $capacity->isGlobalOpen() ? 'BUKA' : 'TUTUP',
            'kuota_sd'    => $sd['total'],
            'kuota_smp'   => $smp['total'],
            'kuota_sd_terpakai'  => $sd['terpakai'],
            'kuota_smp_terpakai' => $smp['terpakai'],
            'kuota_sd_sisa'      => $sd['sisa'],
            'kuota_smp_sisa'     => $smp['sisa'],
        ]);
    }

    public function saveSettings(Request $request, PpdbCapacityService $capacity)
    {
        // Admin configures the TOTAL (max_seats) per jenjang + the global gate.
        // "Remaining" is never accepted/stored — it is always derived.
        $request->validate([
            'ppdb_status' => 'required|in:BUKA,TUTUP',
            'kuota_sd'    => 'required|integer|min:0|max:10000',
            'kuota_smp'   => 'required|integer|min:0|max:10000',
        ]);

        try {
            // Writes to ppdb_capacities (the authoritative totals). Lowering below
            // the currently-allocated active seat count is REJECTED (no silent clamp).
            $capacity->increaseMaxCapacity('SD',  (int) $request->kuota_sd);
            $capacity->increaseMaxCapacity('SMP', (int) $request->kuota_smp);
        } catch (CapacityException $e) {
            $status = $e->reasonCode === CapacityException::MAX_BELOW_ACTIVE ? 422 : 422;
            return response()->json(['message' => $e->getMessage()], $status);
        }

        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => $request->ppdb_status]);

        return response()->json(['success' => true]);
    }

    // === 2. MANAJEMEN WHITELIST (INTERNAL) ===
    public function getWhitelists()
    {
        // Lists expose only has_number; credentials are disclosed on creation,
        // regeneration, or an authorized single-candidate read.
        return response()->json(
            Whitelist::orderBy('created_at', 'desc')
                ->get()
                ->map(fn ($w) => [
                    'id'          => $w->id,
                    'nama_siswa'  => $w->nama_siswa,
                    'asal_sekolah'=> $w->asal_sekolah,
                    'jenjang'     => $w->jenjang,
                    'has_number'  => $w->registration_number !== null, // boolean only, never the value
                    'is_used'     => $w->is_used,
                    'user_id'     => $w->user_id,
                    'created_at'  => $w->created_at,
                ])
        );
    }

    public function storeWhitelist(Request $request, InternalAccessCodeService $codes)
    {
        // P4-D.2: admin supplies ONLY the candidate's display data — nama, jenjang,
        // optional asal_sekolah. NO NISN, NO NIK. The server auto-issues the sole
        // credential: a 10-digit CSPRNG registration_number. Client can never
        // choose the number.
        $request->validate([
            'nama_siswa'   => 'required|string|max:255',
            'jenjang'      => 'required|in:SD,SMP',
            'asal_sekolah' => 'nullable|string|max:255',
        ]);

        $candidate = Whitelist::create([
            'nama_siswa'         => $request->nama_siswa,
            'jenjang'            => $request->jenjang,
            'asal_sekolah'       => $request->asal_sekolah,
            'registration_number'=> $codes->generateCode(), // auto-issue bearer credential
        ]);

        return response()->json([
            'success'             => true,
            'registration_number' => $candidate->registration_number, // shown to admin once
        ]);
    }

    /**
     * READ-ONLY disclosure of the candidate's CURRENT registration number.
     *
     * This backs the "Salin Nomor" action. It is deliberately a GET and it
     * performs ZERO mutation:
     *   - registration_number is never regenerated
     *   - is_used / user_id are never touched
     *   - updated_at is not bumped
     *
     * Repeated calls always return the identical stored value, so copying any
     * number of times is safe and the credential stays fixed until an admin
     * explicitly chooses generateWhitelistCode().
     *
     * The number is disclosed ONLY here, on an authorized single-candidate
     * read — never in getWhitelists(), which exposes has_number alone.
     *
     * If the candidate has no number yet this is an ERROR (409), never an
     * implicit generation.
     */
    public function showWhitelistRegistrationNumber($id, InternalAccessCodeService $codes)
    {
        $candidate = Whitelist::find($id);
        if (!$candidate) {
            return response()->json(['message' => 'Kandidat tidak ditemukan.'], 404);
        }

        try {
            $number = $codes->currentNumber($candidate);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'success'             => true,
            'registration_number' => $number,
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Generate / regenerate the 10-digit registration number for an UNUSED
     * candidate. Regeneration immediately invalidates the old number. Used
     * candidates are rejected. The number is returned to the authorized admin
     * only — never exposed in list payloads.
     */
    public function generateWhitelistCode($id, InternalAccessCodeService $codes)
    {
        $candidate = Whitelist::find($id);
        if (!$candidate) {
            return response()->json(['message' => 'Kandidat tidak ditemukan.'], 404);
        }

        try {
            $updated = $codes->regenerate($candidate);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json([
            'success'             => true,
            'registration_number' => $updated->registration_number,
        ]);
    }

    /**
     * Delete an UNUSED internal candidate.
     *
     * The row is locked FOR UPDATE inside a transaction — the SAME row and the
     * SAME lock that register() takes when consuming the credential. That
     * serialises delete-vs-register so the two can never interleave badly:
     *
     *   DELETE wins        -> row gone; register()'s locked lookup finds no row
     *                         and rejects with a generic 422. No user is ever
     *                         created from a deleted credential.
     *   REGISTRATION wins  -> the row is re-read under the lock with is_used=1
     *                         and user_id set, so delete returns 409. A used
     *                         candidate can never be deleted (no orphan user).
     *
     * Note the re-read: the state check happens on the locked row, never on the
     * stale model instance fetched before the transaction.
     *
     * Statuses: 204 deleted, 409 already used, 404 missing. A business-state
     * rejection is never a 500.
     */
    public function deleteWhitelist($id)
    {
        return DB::transaction(function () use ($id) {
            $locked = Whitelist::whereKey($id)->lockForUpdate()->first();

            if (!$locked) {
                return response()->json(['message' => 'Kandidat tidak ditemukan.'], 404);
            }

            // is_used / user_id are the authoritative consumption markers.
            // Either one being set means a real account already depends on this
            // row, so deletion would orphan that user.
            if ($locked->is_used || $locked->user_id !== null) {
                return response()->json(['message' => 'Sudah dipakai mendaftar.'], 409);
            }

            $locked->delete();

            return response()->noContent();
        });
    }

    // === 3. IMPORT CSV WHITELIST ===
    public function importWhitelist(Request $request, InternalAccessCodeService $codes)
    {
        $request->validate(['file' => 'required|mimes:csv,txt|max:2048']);

        $path          = $request->file('file')->getRealPath();
        $rows          = array_map('str_getcsv', file($path));
        $headerSkipped = false;
        $sukses        = 0;
        $errors        = [];
        $maxRows       = 1000; // Cegah upload CSV raksasa yang freeze server
        $rowCount      = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $row) {
                // Skip header
                if (!$headerSkipped) {
                    $headerSkipped = true;
                    continue;
                }

                // Batasi jumlah baris
                if (++$rowCount > $maxRows) {
                    $errors[] = "Import dibatasi {$maxRows} baris per upload.";
                    break;
                }

                // P4-D.2 CSV columns: nama_siswa, jenjang, asal_sekolah(optional).
                // NO NISN, NO NIK. Each new row auto-issues one registration_number.
                if (count($row) < 2 || empty(trim($row[0]))) continue;

                $nama_siswa = trim($row[0]);
                $colJenjang = trim($row[1] ?? '');
                $jenjang    = $this->resolveJenjang($colJenjang);
                $asal       = trim($row[2] ?? '');

                // Jenjang yang tidak dikenal DITOLAK — jangan pernah buat kandidat
                // dengan jenjang NULL.
                if ($jenjang === null) {
                    $errors[] = "Jenjang tidak dikenal di baris {$rowCount} ('{$colJenjang}'). Gunakan SD/SMP.";
                    continue;
                }

                // DUPLICATE: SKIP + REPORT (tidak pernah menimpa nama/jenjang/nomor
                // kandidat yang sudah ada secara diam-diam). Tidak ada NISN lagi, jadi
                // duplikat dideteksi via nama+jenjang yang persis sama.
                $exists = Whitelist::where('nama_siswa', $nama_siswa)
                    ->where('jenjang', $jenjang)
                    ->exists();
                if ($exists) {
                    $errors[] = "{$nama_siswa} ({$jenjang}) sudah ada — dilewati (tidak diubah).";
                    continue;
                }

                Whitelist::create([
                    'nama_siswa'         => substr($nama_siswa, 0, 255),
                    'jenjang'            => $jenjang,
                    // asal_sekolah column is NOT NULL — store empty string, not null.
                    'asal_sekolah'       => substr($asal, 0, 255),
                    'registration_number'=> $codes->generateCode(),
                ]);
                $sukses++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$sukses} data berhasil di-import. " . count($errors) . " dilewati/ditolak.",
                'errors'  => $errors,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Terjadi kesalahan format CSV.'], 500);
        }
    }

    /**
     * Resolve the explicit jenjang for an import row. Accepts a direct SD/SMP value
     * or the documented legacy asal_sekolah source. Returns null when unrecognized
     * (caller must reject the row).
     */
    private function resolveJenjang(string $value): ?string
    {
        $v = strtoupper(trim($value));
        return match (true) {
            $v === 'SD'  => 'SD',
            $v === 'SMP' => 'SMP',
            $value === 'TK IT Al-Mubarak' => 'SD',
            $value === 'SD IT Al-Mubarak' => 'SMP',
            default => null,
        };
    }

    // === 4. MANAJEMEN TOKEN (JALUR UMUM) ===
    public function getTokens()
    {
        // P3-C: token.code is a live SECRET credential for unused tokens.
        // Admin list payloads expose only masked metadata: a short prefix for
        // operational reconciliation plus state. A used token is no longer a
        // credential, so its full code is safe to disclose for audit. The
        // plaintext code is only ever disclosed at generation time
        // (generateTokens) to the authorized admin who prints/distributes it.
        $tokens = DB::table('tokens')
            ->leftJoin('users', 'tokens.user_id', '=', 'users.id')
            ->select('tokens.id', 'tokens.code', 'tokens.is_used', 'tokens.expires_at', 'tokens.created_at', 'users.name as nama_pendaftar')
            ->orderBy('tokens.created_at', 'desc')
            ->get()
            ->map(function ($t) {
                $used = (bool) $t->is_used;
                return [
                    'id'             => $t->id,
                    // Masked while unused (still a live credential).
                    'code'           => $used ? $t->code : (substr($t->code, 0, 7) . '••••••••'),
                    'is_used'        => $used,
                    'is_expired'     => $t->expires_at !== null && \Carbon\Carbon::parse($t->expires_at)->isPast(),
                    'expires_at'     => $t->expires_at,
                    'created_at'     => $t->created_at,
                    'nama_pendaftar' => $t->nama_pendaftar,
                ];
            });

        return response()->json($tokens);
    }

    public function generateTokens(Request $request)
    {
        $request->validate([
            'jumlah' => 'required|integer|min:1|max:100',
        ]);

        // P3-C: CSPRNG generation (>=64-bit entropy, unambiguous alphabet,
        // rejection sampling) via UmumTokenService. The DB UNIQUE index on
        // `code` is the authoritative collision backstop (the service retries
        // on the rare collision instead of trusting an app-side existence
        // check alone). Every new token carries expires_at (30-day policy).
        $created = app(\App\Services\UmumTokenService::class)->generateBatch((int) $request->jumlah);

        return response()->json([
            'success' => true,
            'message' => count($created) . " Token PPDB baru berhasil diproduksi.",
            // Secrets are disclosed exactly ONCE, here, to the authorized admin
            // who is expected to print/distribute them. Never in list views.
            'tokens'  => collect($created)->map(fn ($t) => [
                'code'       => $t->code,
                'expires_at' => $t->expires_at->toIso8601String(),
            ])->values(),
        ]);
    }

    public function deleteToken($id)
    {
        $token = \App\Models\Token::findOrFail($id);
        if ($token->is_used) {
            return response()->json(['message' => 'Token sudah dipakai pendaftar.'], 403);
        }
        $token->delete();
        return response()->json(['success' => true]);
    }
}
