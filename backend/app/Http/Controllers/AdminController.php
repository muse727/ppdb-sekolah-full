<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;

class AdminController extends Controller
{
    public function getAllPendaftar()
    {
        // Kueri dioptimalkan tanpa memanggil kolom 'jenjang' dari tabel users
        $users = User::select(
                'id', 'name', 'email', 'created_at', 'role', 
                'jadwal_observasi', 'jam_observasi', 'ruang_observasi', 'perlengkapan', 'status_kelulusan'
            )
            ->where('role', 'orang_tua')
            ->with([
                'payments' => function($query) { 
                    $query->select('id', 'user_id', 'jenjang', 'status', 'proof_of_payment', 'payment_method')->latest(); 
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
                    $status_bayar = 'Pending DOKU';
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
                'step' => $step,
                'biodata' => $biodata,
                'berkas' => $berkas,
                'jadwal_observasi' => $user->jadwal_observasi,
                'jam_observasi' => $user->jam_observasi,
                'ruang_observasi' => $user->ruang_observasi,
                'perlengkapan' => $user->perlengkapan,
                'hasil_observasi' => $hasilObs ? true : false,
                'status_kelulusan' => $user->status_kelulusan
            ];
        });

        return response()->json($data);
    }
}