<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PpdbSeatAllocation;
use App\Models\User;

class AdminController extends Controller
{
    public function getAllPendaftar(Request $request)
    {
        $jalurPendaftaran = $request->query('jalur_pendaftaran');
        $allowedJalur = \App\Models\Biodata::JALUR_PENDAFTARAN;

        if ($jalurPendaftaran !== null && ! in_array($jalurPendaftaran, $allowedJalur, true)) {
            return response()->json(['message' => 'Jalur pendaftaran tidak valid.'], 422);
        }

        // Filter administratif ini hanya membaca biodata; users.jalur tetap tidak disentuh.
        $users = User::select(
                'id', 'name', 'email', 'created_at', 'role', 
                'jadwal_observasi', 'jam_observasi', 'ruang_observasi', 'perlengkapan', 'status_kelulusan'
            )
            ->where('role', 'orang_tua')
            ->when($jalurPendaftaran !== null, fn ($query) => $query->whereHas(
                'biodata', fn ($biodataQuery) => $biodataQuery->where('jalur_pendaftaran', $jalurPendaftaran)
            ))
            ->with([
                'payments' => function($query) { 
                    // latest('id') — id is a monotonic auto-increment, so the
                    // "newest" payment is deterministic even when two rows share
                    // the same second-resolution created_at. Must also match
                    // PaymentController::checkStatus()/manualPayment() ordering.
                    // PPDB-scoped: a later DAFTAR_ULANG payment must never
                    // replace the PPDB row shown in this admin listing.
                    $query->where('payment_category', 'PPDB')
                        ->select('id', 'user_id', 'jenjang', 'status', 'proof_of_payment', 'payment_method', 'verified_at', 'rejection_reason')
                        ->latest('id');
                }, 
                'biodata', 
                'berkas',
                'hasilObservasi' 
            ]) 
            ->orderBy('created_at', 'desc')
            ->get(); 
            
        $data = $users->map(function($user) {
            $payment = $user->payments->first();
            $biodata = $user->biodata;
            $berkas = $user->berkas; 
            $hasilObs = $user->hasilObservasi; 
            
            $jenjang = '-';
            $step = 1;
            $status_bayar = 'Belum Bayar';
            
            if ($payment) {
                // Mengambil data jenjang murni dari tabel payments
                if ($payment->jenjang) {
                    $jenjang = $payment->jenjang;
                }

                if ($payment->status === 'SUCCESS') {
                    $status_bayar = 'Lunas';
                    $step = 2; 
                    
                    if ($biodata) {
                        $step = 3; 
                        
                        if ($berkas && $berkas->kartu_keluarga && $berkas->akte_kelahiran) {
                            $step = 4; 
                            
                            if ($hasilObs) {
                                $step = 5; 
                                
                                if ($user->status_kelulusan === 'LULUS') {
                                    $step = 6; 
                                }
                            }
                        }
                    }
                    
                } else if ($payment->status === 'PENDING_MANUAL') {
                    $status_bayar = 'Menunggu Verifikasi';
                } else {
                    // Manual-transfer only — surface each real state.
                    $status_bayar = match ($payment->status) {
                        'REJECTED' => 'Ditolak',
                        'FAILED'   => 'Gagal',
                        'EXPIRED'  => 'Kedaluwarsa',
                        default    => 'Belum Lunas',
                    };
                }
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'join_date' => $user->created_at->format('d M Y'),
                'payment_id' => $payment ? $payment->id : null,
                'jenjang' => $jenjang,
                'status_bayar' => $status_bayar,
                'proof_of_payment' => $payment ? $payment->proof_of_payment : null,
                // Rejection metadata for the admin list/detail: when a payment
                // was rejected the admin needs the recorded reason. Deliberately
                // NOT exposed: verifier_id (internal audit key) — admins see
                // which record was rejected, not who keyed the decision.
                'rejection_reason' => ($payment && $payment->status === 'REJECTED') ? $payment->rejection_reason : null,
                'verified_at' => $payment ? $payment->verified_at : null,
                'step' => $step,
                'biodata' => $biodata,
                'berkas' => $berkas,
                'jadwal_observasi' => $user->jadwal_observasi,
                'jam_observasi' => $user->jam_observasi,
                'ruang_observasi' => $user->ruang_observasi,
                'perlengkapan' => $user->perlengkapan,
                'hasil_observasi' => $hasilObs ? true : false,
                'status_kelulusan' => $user->status_kelulusan,
                'seat_allocation' => PpdbSeatAllocation::where('user_id', $user->id)
                    ->latest('id')
                    ->first(['id', 'jenjang', 'status', 'allocated_at', 'released_at', 'release_reason']),
            ];
        });

        return response()->json($data);
    }
}