// E.12G Phase 4 — shared error presentation for the observasi submit/edit
// endpoint. Both callers (arena & rekapan editor) use this so the idempotency
// and concurrency contract is presented identically everywhere.
//
// Rules:
//  - 503 idempotency_in_flight / network errors: the pending sessionStorage
//    op is KEPT (the caller decides) — retry must reuse the SAME key.
//  - 403 not_assigned, 409 stale_version, 409 duplicate_hasil_rows,
//    422 idempotency_payload_mismatch: definitive; the pending op is cleared
//    by the caller and the message tells the guru exactly what to do.

import Swal from 'sweetalert2';

interface ApiErrorBody {
    code?: string;
    message?: string;
    current?: { version?: number };
}

export function handleObservasiSubmitError(err: unknown, opts?: { onDefinitive?: () => void }): void {
    const axiosErr = err as { response?: { status?: number; data?: ApiErrorBody } };
    const status = axiosErr?.response?.status;
    const body = axiosErr?.response?.data;

    // Network/timeout: response unknown — do NOT clear anything; the same key
    // must be retried. Retry safely replays the committed result if any.
    if (!status) {
        Swal.fire('Koneksi Terputus', 'Status penyimpanan belum diketahui. Jangan membuat penilaian baru — tekan "Kirim" lagi untuk mengulang dengan kunci yang sama.', 'warning');
        return;
    }

    switch (status) {
        case 403:
            Swal.fire('Tidak Berwenang', body?.message ?? 'Anda tidak ditugaskan sebagai penilai aktif untuk calon murid ini.', 'error');
            opts?.onDefinitive?.();
            return;
        case 409:
            if (body?.code === 'duplicate_hasil_rows') {
                Swal.fire('Data Duplikat', body?.message ?? 'Calon murid ini memiliki lebih dari satu baris hasil. Hubungi admin.', 'error');
            } else { // stale_version
                Swal.fire('Data Sudah Berubah', (body?.message ?? 'Hasil telah diperbarui orang lain.') + (body?.current?.version !== undefined ? ` (versi terbaru: ${body.current.version})` : '') + '. Muat ulang halaman, lalu edit berdasarkan data terbaru.', 'warning');
            }
            opts?.onDefinitive?.();
            return;
        case 422:
            if (body?.code === 'idempotency_payload_mismatch') {
                Swal.fire('Konflik Kunci Operasi', body?.message ?? 'Kunci operasi ini sudah dipakai untuk payload berbeda.', 'error');
            } else {
                Swal.fire('Data Tidak Valid', body?.message ?? 'Periksa kembali isian penilaian.', 'error');
            }
            opts?.onDefinitive?.();
            return;
        case 503:
            // in-flight or lock-wait: KEEP the key; retry with the SAME key shortly.
            Swal.fire('Coba Lagi Sebentar', body?.message ?? 'Operasi sedang berlangsung. Ulangi dengan tombol yang sama beberapa saat lagi.', 'info');
            return;
        default:
            Swal.fire('Gagal', body?.message ?? 'Terjadi kesalahan.', 'error');
            opts?.onDefinitive?.();
    }
}
