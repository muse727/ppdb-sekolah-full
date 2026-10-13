<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Biodata;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class BiodataController extends Controller
{
    public function show()
    {
        $biodata = Biodata::where('user_id', Auth::id())->first();
        return response()->json($biodata);
    }

    public function store(Request $request)
    {
        $request->validate([
            // E.2: jalur wajib dipilih untuk pendaftar baru; nilai kosong maupun
            // di luar opsi resmi ditolak 422. Kolom tetap nullable — consumer
            // laporan/daftar ulang menangani row legacy null secara aman.
            'jalur_pendaftaran'    => ['required', Rule::in(Biodata::JALUR_PENDAFTARAN)],
            'asal_sekolah'         => 'required|string|max:255',
            'nama_lengkap'         => 'required|string|max:255',
            'nama_panggilan'       => 'nullable|string|max:100',
            'jenis_kelamin'        => 'required|in:L,P',
            'nisn'                 => 'nullable|digits:10',
            'nik_siswa'            => 'required|digits:16',
            'no_kk'                => 'required|digits:16',
            'tempat_lahir'         => 'required|string|max:100',
            'tanggal_lahir'        => 'required|date',
            'anak_ke'              => 'required|integer|min:1',
            'status_peserta_didik' => 'required|string|max:50',
            'nama_ibu'             => 'required|string|max:255',
            'nik_ibu'              => 'required|digits:16',
            'pendidikan_ibu'       => 'required|string|max:100',
            'pekerjaan_ibu'        => 'required|string|max:100',
            'penghasilan_ibu'      => 'required|string|max:100',
            // Data Ayah: wajib — frontend menandai wajib dan kolom DB NOT NULL.
            // Sebelumnya nullable di sini, sehingga nilai kosong lolos validasi
            // lalu gagal di database (500) alih-alih 422.
            'nama_ayah'            => 'required|string|max:255',
            'nik_ayah'             => 'required|digits:16',
            'pendidikan_ayah'      => 'required|string|max:100',
            'pekerjaan_ayah'       => 'required|string|max:100',
            'penghasilan_ayah'     => 'required|string|max:100',
            'nama_wali'            => 'nullable|string|max:255',
            'alamat_lengkap'       => 'required|string|max:500',
            'kota'                 => 'required|string|max:255',
            'kecamatan'            => 'required|string|max:255',
            'kelurahan'            => 'required|string|max:255',
            'rt'                   => 'required|string|max:5',
            'rw'                   => 'required|string|max:5',
            'no_hp_ortu'           => 'required|string|max:20',
            'email_siswa'          => 'nullable|email|max:255',
            'no_hp_wali'           => 'nullable|string|max:20',
        ]);

        // user_id SELALU dari Auth — tidak bisa di-inject dari request
        Biodata::updateOrCreate(
            ['user_id' => Auth::id()],
            $request->only([
                'jalur_pendaftaran', 'asal_sekolah', 'nama_lengkap', 'nama_panggilan', 'jenis_kelamin',
                'nisn', 'nik_siswa', 'no_kk', 'tempat_lahir', 'tanggal_lahir',
                'anak_ke', 'status_peserta_didik', 'nama_ibu', 'nik_ibu',
                'pendidikan_ibu', 'pekerjaan_ibu',                 'penghasilan_ibu',
                'nama_ayah', 'nik_ayah', 'pendidikan_ayah', 'pekerjaan_ayah',
                'penghasilan_ayah', 'nama_wali', 'alamat_lengkap', 'kota',
                'kecamatan', 'kelurahan', 'rt', 'rw', 'no_hp_ortu',
                'email_siswa', 'no_hp_wali',

            ])
        );

        return response()->json(['success' => true, 'message' => 'Biodata berhasil disimpan.']);
    }
}
