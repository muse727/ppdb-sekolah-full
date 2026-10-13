<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;

class JadwalObservasiController extends Controller
{
    public function index()
    {
        // Join tabel payments buat dapet info jenjang
        $users = User::where('users.role', 'orang_tua')
            ->leftJoin('payments', function ($join) {
                // PPDB-scoped join: a DAFTAR_ULANG payment must not supply the
                // jenjang shown in this observasi listing.
                $join->on('users.id', '=', 'payments.user_id')
                    ->where('payments.payment_category', '=', 'PPDB');
            })
            ->select('users.*', 'payments.jenjang')
            ->orderBy('users.created_at', 'desc')
            ->get()
            ->unique('id') // <--- INI OBATNYA BRO BIAR GA DOUBLE
            ->values();    // <--- Rapiin ulang urutan array-nya
                     
        return response()->json($users);
    }

    // Update jadwal observasi
    public function update(Request $request, $id)
    {
        // E.12C: the rules intentionally mirror bulkUpdate() so a single and a
        // bulk schedule assignment cannot diverge. Without validation here an
        // empty/malformed `tanggal` used to reach the `date` column and surface
        // as a 500 under STRICT_TRANS_TABLES.
        $data = $request->validate([
            'tanggal' => 'required|date',
            'jam' => 'required|string',
            'ruangan' => 'required|string',
            'perlengkapan' => 'nullable|string'
        ]);

        // Unchanged behaviour: existence/authorization stays as it was. The
        // route is behind CheckRole:admin, and findOrFail still answers 404 for
        // an unknown id rather than inventing one.
        $user = User::findOrFail($id);

        // E.12C FIX — jadwal_observasi, jam_observasi, ruang_observasi and
        // perlengkapan are all listed in User::$guarded (deliberately, as
        // privileged columns), so the previous $user->update([...]) went through
        // fill() and silently dropped every one of them. save() then reported a
        // clean, successful no-op and this endpoint answered success:true while
        // writing zero columns.
        //
        // forceFill() unguards exactly these four keys. The allowlist is
        // explicit and literal — no other column, and in particular no other
        // $guarded column such as `role` or `status_kelulusan`, can be reached
        // through this request. User::$guarded itself is left untouched.
        $user->forceFill([
            'jadwal_observasi' => $data['tanggal'],
            'jam_observasi' => $data['jam'],
            'ruang_observasi' => $data['ruangan'],
            // ConvertEmptyStringsToNull turns the frontend's '' into null; the
            // column is nullable, so persist null rather than an empty string.
            'perlengkapan' => $data['perlengkapan'] ?? null,
        ]);

        // Only report success when the write actually happened. A failed save
        // previously could not be distinguished from a successful one.
        if (! $user->save()) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui jadwal observasi.'
            ], 500);
        }

        // Response shape for the success path is unchanged.
        return response()->json([
            'success' => true,
            'message' => 'Jadwal berhasil diperbarui'
        ]);
    }

    public function bulkUpdate(Request $request)
    {
        $request->validate([
            'user_ids' => 'required|array',
            'tanggal' => 'required|date',
            'jam' => 'required|string',
            'ruangan' => 'required|string',
            'perlengkapan' => 'nullable|string'
        ]);

        User::whereIn('id', $request->user_ids)->update([
            'jadwal_observasi' => $request->tanggal,
            'jam_observasi' => $request->jam,
            'ruang_observasi' => $request->ruangan,
            'perlengkapan' => $request->perlengkapan
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal massal berhasil disimpan'
        ]);
    }
}