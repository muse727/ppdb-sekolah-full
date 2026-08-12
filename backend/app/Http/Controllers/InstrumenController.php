<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\KategoriObservasi;
use App\Models\KomponenObservasi;

class InstrumenController extends Controller
{
    /**
     * AMBIL SEMUA DATA INSTRUMEN
     * (Ditambah fitur filter jenjang biar SD dan SMP ga nyampur)
     */
    public function getKategori(Request $request)
    {
        // Tarik kategori sekalian bawa anak-anaknya (soal/komponen)
        $query = KategoriObservasi::with('komponen')->orderBy('urutan', 'asc');
        
        // Kalau guru minta khusus SD atau SMP aja
        if ($request->has('jenjang')) {
            $query->where('jenjang', $request->jenjang);
        }
        
        return response()->json($query->get());
    }

    /**
     * BIKIN ATAU UPDATE KATEGORI
     * (Misal: Motorik Kasar, Literasi, Tahsin)
     */
    public function storeKategori(Request $request)
    {
        $request->validate([
            'nama_kategori' => 'required|string|max:255',
            'jenjang' => 'required|in:SD,SMP',
            'urutan' => 'nullable|integer'
        ]);

        // Pakai updateOrCreate: Kalau request bawa 'id', dia nge-update. Kalau nggak, dia bikin baru.
        $kategori = KategoriObservasi::updateOrCreate(
            ['id' => $request->id], 
            [
                'nama_kategori' => $request->nama_kategori,
                'jenjang' => $request->jenjang,
                'urutan' => $request->urutan ?? 1
            ]
        );

        return response()->json([
            'success' => true, 
            'message' => 'Kategori berhasil disimpan.', 
            'data' => $kategori
        ]);
    }

    /**
     * BIKIN ATAU UPDATE KOMPONEN (PERTANYAAN/TUGAS)
     * (Misal: K1. Berlari, K2. Melompat)
     */
    public function storeKomponen(Request $request)
    {
        $request->validate([
            'kategori_id' => 'required|exists:kategori_observasis,id',
            'kode' => 'required|string|max:10', // Contoh: K1, L5
            'keterampilan' => 'required|string|max:255', // <-- UPDATE: Tambah Keterampilan
            'deskripsi' => 'required|string',   // Contoh: Berlari tanpa jatuh
            'saran_jika_kurang' => 'nullable|string', // Saran buat dimasukin ke PDF
            'petunjuk' => 'nullable|string',
            'lampiran' => 'nullable|string',
        ]);

        $komponen = KomponenObservasi::updateOrCreate(
            ['id' => $request->id],
            [
                'kategori_id' => $request->kategori_id,
                'kode' => $request->kode,
                'keterampilan' => $request->keterampilan, // <-- UPDATE: Simpan Keterampilan
                'deskripsi' => $request->deskripsi,
                'saran_jika_kurang' => $request->saran_jika_kurang,
                'petunjuk' => $request->petunjuk,
                'lampiran' => $request->lampiran,
            ]
        );

        return response()->json([
            'success' => true, 
            'message' => 'Komponen soal berhasil disimpan.', 
            'data' => $komponen
        ]);
    }
    
    // FUNGSI HAPUS KATEGORI
    public function destroyKategori($id)
    {
        $kategori = KategoriObservasi::findOrFail($id);
        // Hapus semua soal anak-anaknya dulu biar bersih
        $kategori->komponen()->delete(); 
        $kategori->delete();

        return response()->json(['success' => true, 'message' => 'Kategori berhasil dihapus.']);
    }

    // FUNGSI HAPUS SOAL (KOMPONEN)
    public function destroyKomponen($id)
    {
        $komponen = KomponenObservasi::findOrFail($id);
        $komponen->delete();

        return response()->json(['success' => true, 'message' => 'Komponen soal berhasil dihapus.']);
    }
}