<?php

namespace App\Http\Controllers;

use App\Models\Biodata;
use App\Models\Payment;
use App\Services\AdminReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminReportController extends Controller
{
    private function filters(Request $request, string $kind): array
    {
        return $request->validate([
            'status' => ['nullable', Rule::in($kind === 'kelulusan' ? ['LULUS', 'TIDAK_LULUS'] : array_merge(['NO_PAYMENT'], array_keys(AdminReportService::PAYMENT_LABELS)))],
            'jenjang' => ['nullable', Rule::in(['SD', 'SMP'])],
            'jalur_pendaftaran' => ['nullable', Rule::in(Biodata::JALUR_PENDAFTARAN)],
            'search' => 'nullable|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
    }

    public function index(Request $request, string $kind, AdminReportService $reports)
    {
        $filters = $this->filters($request, $kind);
        $page = $reports->query($kind, $filters)->paginate($filters['per_page'] ?? 25);
        return response()->json([
            'data' => $page->getCollection()->map(fn ($row) => $reports->present($row))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'payment_statuses' => $kind === 'pembayaran' ? $reports->availablePaymentStatuses() : [],
        ])->header('Cache-Control', 'no-store');
    }

    public function show(string $kind, int $userId, AdminReportService $reports)
    {
        $row = $reports->query($kind)->where('u.id', $userId)->first();
        abort_unless($row, 404);
        return response()->json($reports->present($row, true))->header('Cache-Control', 'no-store');
    }

    public function export(Request $request, string $kind, AdminReportService $reports)
    {
        $query = $reports->query($kind, $this->filters($request, $kind));
        return response()->streamDownload(function () use ($kind, $query, $reports) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $header = ['Nama', 'Email', 'Jenjang', 'Jalur Pendaftaran', $kind === 'kelulusan' ? 'Status Kelulusan' : 'Status Pembayaran'];
            if ($kind === 'pembayaran') {
                $header = array_merge($header, ['Nominal', 'Tanggal Pencatatan', 'Nomor Referensi']);
            }
            fputcsv($out, $header, ',', '"', '');
            // Bounded chunks; export shares exactly the list's latest-payment/filter semantics.
            foreach ($query->lazy(500) as $row) {
                $data = $reports->present($row);
                $values = [$data['name'], $data['email'], $data['jenjang'], $data['jalur_pendaftaran'], $kind === 'kelulusan' ? ($data['status_kelulusan'] === 'LULUS' ? 'Lulus' : 'Tidak Lulus') : $data['payment_label']];
                if ($kind === 'pembayaran') {
                    $values = array_merge($values, [$data['payment']['amount'] ?? null, $data['payment']['created_at'] ?? null, $data['payment']['invoice_number'] ?? null]);
                }
                $values = array_map(function ($value) {
                    $text = (string) ($value ?? '');
                    return preg_match('/^[\s]*[=+@\-]/u', $text) ? "'".$text : $text;
                }, $values);
                fputcsv($out, $values, ',', '"', '');
            }
            fclose($out);
        }, 'data-'.$kind.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function proof(int $userId, int $paymentId, AdminReportService $reports)
    {
        $payment = Payment::whereKey($paymentId)->where('user_id', $userId)
            ->ppdb()
            ->whereHas('user', fn ($q) => $q->where('role', 'orang_tua'))->firstOrFail();
        $path = $reports->proofPath($payment->proof_of_payment);
        abort_unless($path, 404);
        return response()->file($path, [
            'Content-Type' => getimagesize($path)['mime'],
            'Content-Disposition' => 'inline; filename="bukti-pembayaran"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
