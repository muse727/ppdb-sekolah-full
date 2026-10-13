<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Payment;
use App\Models\Berkas;
use App\Models\PpdbCapacity;
use App\Models\PpdbSeatAllocation;
use App\Models\DaftarUlang\DuInvoice;
use App\Models\CbtSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardStatsController extends Controller
{
    public function getStats()
    {
        $today = Carbon::today();

        // 1. Pendaftar SD & SMP
        $pendaftarSdTotal = User::where('role', 'orang_tua')
            ->where(function($q) {
                $q->where('jenjang', 'SD')
                  ->orWhereHas('payments', fn($p) => $p->where('jenjang', 'SD')->where('payment_category', 'PPDB'));
            })->count();

        $pendaftarSdToday = User::where('role', 'orang_tua')
            ->whereDate('created_at', $today)
            ->where(function($q) {
                $q->where('jenjang', 'SD')
                  ->orWhereHas('payments', fn($p) => $p->where('jenjang', 'SD')->where('payment_category', 'PPDB'));
            })->count();

        $pendaftarSmpTotal = User::where('role', 'orang_tua')
            ->where(function($q) {
                $q->where('jenjang', 'SMP')
                  ->orWhereHas('payments', fn($p) => $p->where('jenjang', 'SMP')->where('payment_category', 'PPDB'));
            })->count();

        $pendaftarSmpToday = User::where('role', 'orang_tua')
            ->whereDate('created_at', $today)
            ->where(function($q) {
                $q->where('jenjang', 'SMP')
                  ->orWhereHas('payments', fn($p) => $p->where('jenjang', 'SMP')->where('payment_category', 'PPDB'));
            })->count();

        // 2. Daftar Ulang (DU)
        $daftarUlangCount = DuInvoice::where('status', 'PAID')->count();
        if ($daftarUlangCount === 0) {
            $daftarUlangCount = User::where('role', 'orang_tua')->where('status_kelulusan', 'LULUS')->count();
        }

        // 3. Kapasitas Kuota
        $capacitySd = PpdbCapacity::where('jenjang', 'SD')->first();
        $capacitySmp = PpdbCapacity::where('jenjang', 'SMP')->first();

        $totalQuotaSd = $capacitySd ? $capacitySd->total_seats : 100;
        $totalQuotaSmp = $capacitySmp ? $capacitySmp->total_seats : 100;

        $allocatedSd = PpdbSeatAllocation::where('jenjang', 'SD')->where('status', 'ALLOCATED')->count();
        $allocatedSmp = PpdbSeatAllocation::where('jenjang', 'SMP')->where('status', 'ALLOCATED')->count();

        if ($allocatedSd === 0 && $pendaftarSdTotal > 0) {
            $allocatedSd = Payment::ppdb()->where('jenjang', 'SD')->where('status', 'SUCCESS')->count();
        }
        if ($allocatedSmp === 0 && $pendaftarSmpTotal > 0) {
            $allocatedSmp = Payment::ppdb()->where('jenjang', 'SMP')->where('status', 'SUCCESS')->count();
        }

        $sisaSd = max(0, $totalQuotaSd - $allocatedSd);
        $sisaSmp = max(0, $totalQuotaSmp - $allocatedSmp);
        $totalQuota = $totalQuotaSd + $totalQuotaSmp;
        $totalTerisi = $allocatedSd + $allocatedSmp;
        $persenTerisi = $totalQuota > 0 ? round(($totalTerisi / $totalQuota) * 100) : 0;

        // 4. Status Pemberkasan
        $verifBerkas = Berkas::whereNotNull('kartu_keluarga')
            ->whereNotNull('akte_kelahiran')
            ->count();
        
        $totalParents = User::where('role', 'orang_tua')->count();
        $menungguBerkas = max(0, $totalParents - $verifBerkas);

        $pctVerif = $totalParents > 0 ? round(($verifBerkas / $totalParents) * 100) : 0;
        $pctMenunggu = $totalParents > 0 ? round(($menungguBerkas / $totalParents) * 100) : 0;
        $pctInvalid = 0;

        // 5. Pembayaran Pertama (Pending Verification)
        $pendingPayments = Payment::with('user:id,name,email,jenjang')
            ->ppdb()
            ->where('status', 'PENDING_MANUAL')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function($p) {
                return [
                    'id' => $p->id,
                    'user_id' => $p->user_id,
                    'name' => $p->user?->name ?? 'Calon Siswa',
                    'email' => $p->user?->email ?? '-',
                    'jenjang' => $p->jenjang ?? ($p->user?->jenjang ?? '-'),
                    'amount' => $p->amount,
                    'invoice_number' => $p->invoice_number,
                    'proof_of_payment' => $p->proof_of_payment,
                    'created_at' => $p->created_at ? $p->created_at->format('d M Y H:i') : '-',
                    'status' => $p->status,
                ];
            });

        // 6. JADWAL TERDEKAT REALTIME DARI DATABASE
        $jadwalList = collect();

        // 6a. Jadwal Observasi dari Calon Siswa
        $observasiSchedules = User::where('role', 'orang_tua')
            ->whereNotNull('jadwal_observasi')
            ->where('jadwal_observasi', '>=', $today->format('Y-m-d'))
            ->orderBy('jadwal_observasi', 'asc')
            ->orderBy('jam_observasi', 'asc')
            ->limit(5)
            ->get();

        foreach ($observasiSchedules as $obs) {
            $timeLabel = $obs->jam_observasi ? date('H:i A', strtotime($obs->jam_observasi)) : '08:00 AM';
            $tglLabel = Carbon::parse($obs->jadwal_observasi)->locale('id')->isoFormat('D MMM');
            $ruang = $obs->ruang_observasi ? "Ruang {$obs->ruang_observasi}" : 'Ruang Observasi';

            $jadwalList->push([
                'id' => 'obs-' . $obs->id,
                'time' => $timeLabel,
                'date' => $obs->jadwal_observasi,
                'date_label' => $tglLabel,
                'title' => "Observasi: {$obs->name}",
                'desc' => "{$ruang} • {$obs->jenjang}",
                'dot_color' => '#1a5f51',
            ]);
        }

        // 6b. Jadwal Sesi CBT
        $cbtSessions = CbtSession::where('tanggal_ujian', '>=', $today->format('Y-m-d'))
            ->orderBy('tanggal_ujian', 'asc')
            ->orderBy('jam_mulai', 'asc')
            ->limit(5)
            ->get();

        foreach ($cbtSessions as $cbt) {
            $timeLabel = $cbt->jam_mulai ? date('H:i A', strtotime($cbt->jam_mulai)) : '08:00 AM';
            $tglLabel = Carbon::parse($cbt->tanggal_ujian)->locale('id')->isoFormat('D MMM');

            $jadwalList->push([
                'id' => 'cbt-' . $cbt->id,
                'time' => $timeLabel,
                'date' => $cbt->tanggal_ujian,
                'date_label' => $tglLabel,
                'title' => "Ujian CBT: {$cbt->nama_ujian}",
                'desc' => "{$cbt->ruangan} • Token: {$cbt->token}",
                'dot_color' => '#4c51bf',
            ]);
        }

        // Urutkan jadwal berdasarkan tanggal dan waktu
        $jadwalList = $jadwalList->sortBy(fn($item) => $item['date'] . ' ' . $item['time'])->values()->take(5);

        return response()->json([
            'stats' => [
                'pendaftar_sd' => [
                    'total' => $pendaftarSdTotal,
                    'today' => $pendaftarSdToday,
                ],
                'pendaftar_smp' => [
                    'total' => $pendaftarSmpTotal,
                    'today' => $pendaftarSmpToday,
                ],
                'daftar_ulang' => [
                    'total' => $daftarUlangCount,
                    'total_quota' => $totalQuota,
                ],
            ],
            'kuota' => [
                'persen_terisi' => $persenTerisi,
                'sisa_sd' => $sisaSd,
                'sisa_smp' => $sisaSmp,
                'total_terisi' => $totalTerisi,
                'total_quota' => $totalQuota,
            ],
            'pemberkasan' => [
                'terverifikasi' => $pctVerif,
                'menunggu' => $pctMenunggu,
                'invalid' => $pctInvalid,
                'total_berkas' => $verifBerkas,
            ],
            'pending_payments' => $pendingPayments,
            'jadwal_terdekat' => $jadwalList,
        ]);
    }
}
