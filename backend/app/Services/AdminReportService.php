<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminReportService
{
    public const PAYMENT_LABELS = [
        'PENDING_MANUAL' => 'Menunggu Verifikasi', 'SUCCESS' => 'Sudah Bayar',
        'REJECTED' => 'Ditolak', 'FAILED' => 'Gagal', 'EXPIRED' => 'Kedaluwarsa',
        'PENDING' => 'Menunggu Pembayaran',
    ];

    public function query(string $kind, array $filters = [], string $category = Payment::CATEGORY_PPDB): Builder
    {
        Payment::validateCategory($category);
        // Latest payment is determined independently per category. Existing HTTP
        // reports always use PPDB; no Daftar Ulang report endpoint is exposed.
        $latest = DB::table('payments')->where('payment_category', $category)
            ->selectRaw('user_id, MAX(id) as payment_id')->groupBy('user_id');
        $biodata = DB::table('biodatas')->selectRaw('user_id, MAX(id) as biodata_id')->groupBy('user_id');
        $query = DB::table('users as u')
            ->leftJoinSub($latest, 'latest', 'latest.user_id', '=', 'u.id')
            ->leftJoin('payments as p', 'p.id', '=', 'latest.payment_id')
            ->leftJoinSub($biodata, 'bio_latest', 'bio_latest.user_id', '=', 'u.id')
            ->leftJoin('biodatas as b', 'b.id', '=', 'bio_latest.biodata_id')
            ->where('u.role', 'orang_tua')
            ->select([
                'u.id', 'u.name as account_name', 'u.email', 'u.status_kelulusan',
                'b.jalur_pendaftaran', 'b.asal_sekolah', 'b.jenis_kelamin',
                'p.id as payment_id', 'p.status as payment_status', 'p.amount',
                'p.invoice_number', 'p.payment_method', 'p.created_at as payment_created_at',
                'p.verified_at', 'p.rejection_reason', 'p.proof_of_payment',
            ])
            ->selectRaw("COALESCE(NULLIF(b.nama_lengkap, ''), u.name) as name")
            ->selectRaw('COALESCE(p.jenjang, u.jenjang) as jenjang');

        if ($kind === 'kelulusan') {
            $query->whereIn('u.status_kelulusan', ['LULUS', 'TIDAK_LULUS']);
        }
        if (! empty($filters['status'])) {
            if ($kind === 'kelulusan') {
                $query->where('u.status_kelulusan', $filters['status']);
            } elseif ($filters['status'] === 'NO_PAYMENT') {
                $query->whereNull('p.id');
            } else {
                $query->where('p.status', $filters['status']);
            }
        }
        if (! empty($filters['jenjang'])) {
            $query->whereRaw('COALESCE(p.jenjang, u.jenjang) = ?', [$filters['jenjang']]);
        }
        if (! empty($filters['jalur_pendaftaran'])) {
            $query->where('b.jalur_pendaftaran', $filters['jalur_pendaftaran']);
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            // Treat LIKE metacharacters as literal search text, not wildcard controls.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $q) use ($pattern) {
                $q->whereRaw("u.name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("u.email LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("b.nama_lengkap LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        return $query->orderByDesc('u.id');
    }

    public function present(object $row, bool $detail = false): array
    {
        $payment = null;
        if ($row->payment_id !== null) {
            $payment = [
                'id' => (int) $row->payment_id,
                'status' => $row->payment_status,
                'status_label' => self::PAYMENT_LABELS[$row->payment_status] ?? 'Status tidak dikenal',
                'amount' => (int) $row->amount,
                'invoice_number' => $row->invoice_number,
                'payment_method' => $row->payment_method,
                'created_at' => $row->payment_created_at,
                'verified_at' => $row->verified_at,
                'rejection_reason' => $row->payment_status === 'REJECTED' ? $row->rejection_reason : null,
                'has_proof' => $this->proofPath($row->proof_of_payment) !== null,
            ];
        }
        $data = [
            'id' => (int) $row->id, 'name' => $row->name, 'email' => $row->email,
            'jenjang' => in_array($row->jenjang, ['SD', 'SMP'], true) ? $row->jenjang : null,
            'jalur_pendaftaran' => $row->jalur_pendaftaran,
            'status_kelulusan' => $row->status_kelulusan,
            'payment' => $payment,
            'payment_label' => $payment['status_label'] ?? 'Belum Bayar',
        ];
        if ($detail) {
            $data['account_name'] = $row->account_name;
            $data['biodata'] = ['asal_sekolah' => $row->asal_sekolah, 'jenis_kelamin' => $row->jenis_kelamin];
        }
        return $data;
    }

    /** Resolve only real receipt files contained within the private proof directory. */
    public function proofPath(?string $relative): ?string
    {
        if (! $relative || ! preg_match('#^bukti_bayar/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png)$#i', $relative)) {
            return null;
        }
        $private = realpath(Storage::disk('private')->path(''));
        $root = realpath(Storage::disk('private')->path('bukti_bayar'));
        $path = realpath(Storage::disk('private')->path($relative));
        if ($private === false || $root === false || $path === false || ! str_starts_with($root, $private.DIRECTORY_SEPARATOR) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            return null;
        }
        $image = @getimagesize($path);
        return $image && in_array($image['mime'], ['image/jpeg', 'image/png'], true) ? $path : null;
    }

    public function availablePaymentStatuses(): array
    {
        $statuses = $this->query('pembayaran')->reorder()->select('p.status')->distinct()->pluck('status')->filter()->all();
        $primary = ['PENDING_MANUAL', 'SUCCESS', 'REJECTED'];
        return array_values(array_unique(array_merge($primary, array_values(array_intersect(array_keys(self::PAYMENT_LABELS), $statuses)))));
    }
}
