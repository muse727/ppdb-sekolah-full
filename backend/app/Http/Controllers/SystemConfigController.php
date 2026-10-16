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
        $smpGender = $capacity->getSmpGenderCapacity();

        return response()->json([
            'ppdb_status'          => $capacity->isGlobalOpen() ? 'BUKA' : 'TUTUP',
            'gate_jalur_umum'      => (Setting::where('key', 'gate_jalur_umum')->value('value') ?? 'TUTUP') === 'BUKA' ? 'BUKA' : 'TUTUP',
            'gate_jalur_internal'  => (Setting::where('key', 'gate_jalur_internal')->value('value') ?? 'BUKA') === 'BUKA' ? 'BUKA' : 'TUTUP',
            'kuota_sd'             => $sd['total'],
            'kuota_smp'            => $smp['total'],
            'kuota_smp_ikhwan'     => $smpGender['ikhwan']['total'],
            'kuota_smp_akhwat'     => $smpGender['akhwat']['total'],
            'kuota_sd_terpakai'    => $sd['terpakai'],
            'kuota_smp_terpakai'   => $smp['terpakai'],
            'kuota_smp_ikhwan_terpakai' => $smpGender['ikhwan']['terpakai'],
            'kuota_smp_akhwat_terpakai' => $smpGender['akhwat']['terpakai'],
            'kuota_sd_sisa'        => $sd['sisa'],
            'kuota_smp_sisa'       => $smp['sisa'],
            'kuota_smp_ikhwan_sisa' => $smpGender['ikhwan']['sisa'],
            'kuota_smp_akhwat_sisa' => $smpGender['akhwat']['sisa'],
        ]);
    }

    public function saveSettings(Request $request, PpdbCapacityService $capacity)
    {
        // Admin configures the TOTAL (max_seats) per jenjang + the global and specific gates.
        $request->validate([
            'ppdb_status'          => 'required|in:BUKA,TUTUP',
            'gate_jalur_umum'      => 'nullable|in:BUKA,TUTUP',
            'gate_jalur_internal'  => 'nullable|in:BUKA,TUTUP',
            'kuota_sd'             => 'required|integer|min:0|max:10000',
            'kuota_smp_ikhwan'     => 'nullable|integer|min:0|max:10000',
            'kuota_smp_akhwat'     => 'nullable|integer|min:0|max:10000',
            'kuota_smp'            => 'nullable|integer|min:0|max:10000',
        ]);

        $maxIkhwan = $request->has('kuota_smp_ikhwan') ? (int) $request->kuota_smp_ikhwan : 30;
        $maxAkhwat = $request->has('kuota_smp_akhwat') ? (int) $request->kuota_smp_akhwat : 30;
        $totalSmp  = $request->filled('kuota_smp') ? (int) $request->kuota_smp : ($maxIkhwan + $maxAkhwat);

        try {
            // Writes to ppdb_capacities (the authoritative totals).
            $capacity->increaseMaxCapacity('SD',  (int) $request->kuota_sd);
            $capacity->increaseMaxCapacity('SMP', $totalSmp);
        } catch (CapacityException $e) {
            $status = $e->reasonCode === CapacityException::MAX_BELOW_ACTIVE ? 422 : 422;
            return response()->json(['message' => $e->getMessage()], $status);
        }

        Setting::updateOrCreate(['key' => 'ppdb_status'], ['value' => $request->ppdb_status]);
        if ($request->filled('gate_jalur_umum')) {
            Setting::updateOrCreate(['key' => 'gate_jalur_umum'], ['value' => $request->gate_jalur_umum]);
        }
        if ($request->filled('gate_jalur_internal')) {
            Setting::updateOrCreate(['key' => 'gate_jalur_internal'], ['value' => $request->gate_jalur_internal]);
        }
        Setting::updateOrCreate(['key' => 'kuota_smp_ikhwan'], ['value' => (string) $maxIkhwan]);
        Setting::updateOrCreate(['key' => 'kuota_smp_akhwat'], ['value' => (string) $maxAkhwat]);

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
                    'id'            => $w->id,
                    'nama_siswa'    => $w->nama_siswa,
                    'asal_sekolah'  => $w->asal_sekolah,
                    'jenjang'       => $w->jenjang,
                    'jenis_kelamin' => $w->jenis_kelamin,
                    'has_number'    => $w->registration_number !== null, // boolean only, never the value
                    'is_used'       => $w->is_used,
                    'user_id'       => $w->user_id,
                    'created_at'    => $w->created_at,
                ])
        );
    }

    public function storeWhitelist(Request $request, InternalAccessCodeService $codes)
    {
        // P4-D.2: admin supplies candidate's display data — nama, jenjang,
        // optional asal_sekolah, and optional jenis_kelamin (L/P).
        $request->validate([
            'nama_siswa'    => 'required|string|max:255',
            'jenjang'       => 'required|in:SD,SMP',
            'asal_sekolah'  => 'nullable|string|max:255',
            'jenis_kelamin' => 'nullable|in:L,P',
        ]);

        $candidate = Whitelist::create([
            'nama_siswa'         => $request->nama_siswa,
            'jenjang'            => $request->jenjang,
            'jenis_kelamin'      => $request->jenis_kelamin ?: null,
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

    // === 3. IMPORT WHITELIST (XLSX / CSV / JSON) ===
    public function importWhitelist(Request $request, InternalAccessCodeService $codes)
    {
        $inputRows = [];

        if ($request->has('rows') && is_array($request->rows)) {
            $inputRows = $request->rows;
        } elseif ($request->hasFile('file')) {
            $file = $request->file('file');
            $ext  = strtolower($file->getClientOriginalExtension());
            $path = $file->getRealPath();

            if (in_array($ext, ['xlsx', 'xls'], true)) {
                // Parse file Excel via python script dengan openpyxl
                $escapedPath = escapeshellarg($path);
                $pyCmd = "python3 -c " . escapeshellarg("
import openpyxl, json, sys
wb = openpyxl.load_workbook({$escapedPath}, data_only=True)
sheet = wb.active
rows = []
for row in sheet.iter_rows(values_only=True):
    if not row or all(v is None for v in row):
        continue
    rows.append([str(v) if v is not None else '' for v in row])
print(json.dumps(rows))
");
                $output = shell_exec($pyCmd);
                $rawRows = json_decode($output, true);
                if (is_array($rawRows) && count($rawRows) > 1) {
                    // Skip header
                    array_shift($rawRows);
                    foreach ($rawRows as $r) {
                        if (count($r) >= 2) {
                            $inputRows[] = [
                                'nama_siswa'    => trim($r[0] ?? ''),
                                'jenjang'       => trim($r[1] ?? ''),
                                'jenis_kelamin' => trim($r[2] ?? ''),
                                'asal_sekolah'  => trim($r[3] ?? ''),
                            ];
                        }
                    }
                }
            } else {
                $rawCsv = array_map('str_getcsv', file($path));
                $headerSkipped = false;
                foreach ($rawCsv as $r) {
                    if (!$headerSkipped) {
                        $headerSkipped = true;
                        continue;
                    }
                    if (count($r) >= 2) {
                        $inputRows[] = [
                            'nama_siswa'    => trim($r[0] ?? ''),
                            'jenjang'       => trim($r[1] ?? ''),
                            'jenis_kelamin' => trim($r[2] ?? ''),
                            'asal_sekolah'  => trim($r[3] ?? ''),
                        ];
                    }
                }
            }
        } else {
            return response()->json(['message' => 'File atau baris data tidak ditemukan.'], 422);
        }

        $sukses   = 0;
        $countSD  = 0;
        $countSMP = 0;
        $errors   = [];
        $maxRows  = 1000;
        $rowCount = 0;

        DB::beginTransaction();
        try {
            foreach ($inputRows as $item) {
                if (++$rowCount > $maxRows) {
                    $errors[] = "Import dibatasi maksimal {$maxRows} baris per upload.";
                    break;
                }

                $nama_siswa = trim($item['nama_siswa'] ?? '');
                $colJenjang = trim($item['jenjang'] ?? '');
                $jenjang    = $this->resolveJenjang($colJenjang);
                $asal       = trim($item['asal_sekolah'] ?? '');
                $rawGender  = trim($item['jenis_kelamin'] ?? ($item['gender'] ?? ''));
                $gender     = null;
                $gUpper     = strtoupper($rawGender);
                if (in_array($gUpper, ['L', 'LAKI-LAKI', 'IKHWAN', 'PUTRA', 'LAKI', 'MALE', 'M'], true)) {
                    $gender = 'L';
                } elseif (in_array($gUpper, ['P', 'PEREMPUAN', 'AKHWAT', 'PUTRI', 'FEMALE', 'F'], true)) {
                    $gender = 'P';
                }

                if (empty($nama_siswa)) {
                    continue;
                }

                if ($jenjang === null) {
                    $errors[] = "Baris {$rowCount}: Jenjang '{$colJenjang}' untuk '{$nama_siswa}' tidak dikenal. Harap isi 'SD' atau 'SMP'.";
                    continue;
                }

                // Cek duplikasi
                $exists = Whitelist::where('nama_siswa', $nama_siswa)
                    ->where('jenjang', $jenjang)
                    ->exists();
                if ($exists) {
                    $errors[] = "{$nama_siswa} ({$jenjang}) sudah terdaftar — dilewati.";
                    continue;
                }

                Whitelist::create([
                    'nama_siswa'          => substr($nama_siswa, 0, 255),
                    'jenjang'             => $jenjang,
                    'jenis_kelamin'       => $gender,
                    'asal_sekolah'        => substr($asal, 0, 255),
                    'registration_number' => $codes->generateCode(),
                ]);

                $sukses++;
                if ($jenjang === 'SD') $countSD++;
                if ($jenjang === 'SMP') $countSMP++;
            }

            DB::commit();

            $msg = "{$sukses} data siswa berhasil diimpor ({$countSD} SDIT, {$countSMP} SMPIT).";
            if (count($errors) > 0) {
                $msg .= " (" . count($errors) . " baris dilewati).";
            }

            return response()->json([
                'success' => true,
                'message' => $msg,
                'total_sukses' => $sukses,
                'total_sd' => $countSD,
                'total_smp' => $countSMP,
                'errors'  => $errors,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Terjadi kesalahan saat memproses data: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Resolve the explicit jenjang for an import row. Accepts a direct SD/SMP value
     * or legacy school names. Returns null when unrecognized.
     */
    private function resolveJenjang(string $value): ?string
    {
        $v = strtoupper(trim($value));
        if (in_array($v, ['SD', 'SDIT', 'SD IT', 'TK', 'TKIT', 'TK IT AL-MUBARAK'], true)) {
            return 'SD';
        }
        if (in_array($v, ['SMP', 'SMPIT', 'SMP IT', 'SDIT AL-MUBARAK', 'SD IT AL-MUBARAK'], true)) {
            return 'SMP';
        }
        if (str_contains($v, 'SMP')) return 'SMP';
        if (str_contains($v, 'SD')) return 'SD';
        return null;
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
