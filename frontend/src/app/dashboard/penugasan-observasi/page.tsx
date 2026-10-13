// src/app/dashboard/penugasan-observasi/page.tsx
// E.12G Phase 6A — Admin: Penugasan Guru Observasi SD.
//
// Kontrak API diverifikasi langsung terhadap ObservasiAssignmentController:
//   GET  /api/admin/observasi-assignments          -> array baris kandidat
//   GET  /api/admin/observasi-assignments/options  -> { gurus: [{id,name}] }
//   GET  /api/admin/observasi-assignments/history/{userId} -> { kandidat, history }
//   POST /api/admin/observasi-assignments          -> 201 {success,message,data} | 409 assignment_conflict | 422 invalid_guru/invalid_candidate
//   POST /api/admin/observasi-assignments/{id}/ganti -> id = expected_assignment_id; 422 same_guru
//   POST /api/admin/observasi-assignments/{id}/cabut -> reason nullable (prompt SweetAlert)
// Otorisasi sebenarnya ada di backend (CheckRole:admin). UI hanya mengikuti.

'use client';

import { useCallback, useEffect, useState } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import axios from 'axios';
import styles from './penugasan.module.css';

// === Bentuk respons aktual (index) ===
interface KandidatRow {
    id: number;
    name: string;
    jadwal_observasi: string | null;
    assignment_id: number | null;
    guru_id: number | null;
    guru_name: string | null;
    started_at: string | null;
}

interface GuruOption { id: number; name: string; }

interface HistoryRow {
    id: number;
    guru_id: number;
    guru_name: string | null;
    status: 'AKTIF' | 'DIGANTI' | 'DICABUT';
    revoked_reason: string | null;
    assigned_by_name: string | null;
    started_at: string | null;
    ended_at: string | null;
}

type Mode = 'assign' | 'ganti';

type LoadState = { loading: boolean; error: string | null };

export default function PenugasanObservasiPage() {
    const [kandidat, setKandidat] = useState<KandidatRow[]>([]);
    const [gurus, setGurus] = useState<GuruOption[]>([]);
    const [load, setLoad] = useState<LoadState>({ loading: true, error: null });

    const [search, setSearch] = useState('');
    const [onlyUnassigned, setOnlyUnassigned] = useState(false);

    // Modal create/ganti
    const [modal, setModal] = useState<{ mode: Mode; row: KandidatRow } | null>(null);
    const [selectedGuru, setSelectedGuru] = useState('');
    const [saving, setSaving] = useState(false);
    // Counter to request a reload from effects/callbacks that run before doRefresh exists.
    const [reloadTick, setReloadTick] = useState(0);

    const closeModal = () => { setModal(null); setSelectedGuru(''); setSaving(false); };

    const showApiError = useCallback((err: unknown, fallback: string) => {
        if (axios.isAxiosError(err)) {
            const st = err.response?.status;
            const data = err.response?.data as { message?: string } | undefined;
            if (st === 401) {
                Swal.fire({ icon: 'error', title: 'Sesi Berakhir', text: 'Silakan login kembali.', confirmButtonColor: '#1a5f51' })
                    .then(() => { window.location.href = '/login'; });
                return;
            }
            if (st === 403) {
                Swal.fire({ icon: 'error', title: 'Akses Ditolak', text: data?.message || 'Anda tidak memiliki izin untuk aksi ini.', confirmButtonColor: '#1a5f51' });
                return;
            }
            if (st === 404) {
                Swal.fire({ icon: 'error', title: 'Tidak Ditemukan', text: data?.message || fallback, confirmButtonColor: '#1a5f51' });
                return;
            }
            if (st === 422) {
                Swal.fire({ icon: 'warning', title: 'Data Tidak Valid', text: data?.message || fallback, confirmButtonColor: '#1a5f51' });
                return;
            }
        }
        Swal.fire({ icon: 'error', title: 'Terjadi Kesalahan', text: fallback, confirmButtonColor: '#1a5f51' });
    }, []);

    // 409 assignment_conflict: data sudah stale — wajib reload sebelum retry.
    const handleConflict = useCallback(async (err: unknown) => {
        const data = axios.isAxiosError(err) ? err.response?.data as { message?: string } | undefined : undefined;
        await Swal.fire({
            icon: 'warning',
            title: 'Penugasan Sudah Berubah',
            text: (data?.message || 'Penugasan telah berubah sejak dimuat. Data terbaru akan dimuat ulang.') + ' Muat ulang lalu ulangi aksi bila diperlukan.',
            confirmButtonColor: '#1a5f51',
        });
        closeModal();
        setReloadTick((t) => t + 1);
    }, []);

    const doRefresh = useCallback(async () => {
        setLoad({ loading: true, error: null });
        try {
            const [res, opt] = await Promise.all([
                libAxios.get<KandidatRow[]>('/api/admin/observasi-assignments'),
                libAxios.get<{ gurus: GuruOption[] }>('/api/admin/observasi-assignments/options'),
            ]);
            setKandidat(res.data);
            setGurus(opt.data.gurus ?? []);
            setLoad({ loading: false, error: null });
        } catch (err) {
            if (axios.isAxiosError(err) && err.response?.status === 401) {
                window.location.href = '/login';
                return;
            }
            setLoad({ loading: false, error: 'Gagal memuat data penugasan. Coba lagi.' });
        }
    }, []);

    useEffect(() => {
        // Async data fetch from the API. The setState calls in doRefresh run in
        // the async continuation (after the first await), but the rule cannot
        // prove that statically — this matches the fetch-on-mount pattern used
        // across the rest of this dashboard (see jadwal-observasi, settings).
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void doRefresh();
    }, [doRefresh, reloadTick]);

    // === TULIS: buat penugasan pertama ===
    const submitAssign = async () => {
        if (!modal) return;
        const guruId = parseInt(selectedGuru, 10);
        if (!Number.isInteger(guruId)) {
            Swal.fire({ icon: 'warning', title: 'Pilih Guru', text: 'Pilih guru penilai terlebih dahulu.', confirmButtonColor: '#1a5f51' });
            return;
        }
        setSaving(true);
        try {
            const res = await libAxios.post('/api/admin/observasi-assignments', {
                user_id: modal.row.id,
                guru_id: guruId,
            });
            // Hanya anggap sukses setelah server mengonfirmasi (status 2xx).
            Swal.fire({ icon: 'success', title: 'Berhasil', text: res.data?.message || 'Penugasan berhasil dibuat.', confirmButtonColor: '#1a5f51', timer: 1800, showConfirmButton: false });
            closeModal();
            await doRefresh();
        } catch (err) {
            setSaving(false);
            if (axios.isAxiosError(err) && err.response?.status === 409) { await handleConflict(err); return; }
            showApiError(err, 'Gagal membuat penugasan.');
        }
    };

    // === TULIS: ganti guru (expected_assignment_id = assignment_id dari baris) ===
    const submitGanti = async () => {
        if (!modal || !modal.row.assignment_id) return;
        const guruId = parseInt(selectedGuru, 10);
        if (!Number.isInteger(guruId)) {
            Swal.fire({ icon: 'warning', title: 'Pilih Guru', text: 'Pilih guru pengganti terlebih dahulu.', confirmButtonColor: '#1a5f51' });
            return;
        }
        setSaving(true);
        try {
            const res = await libAxios.post(`/api/admin/observasi-assignments/${modal.row.assignment_id}/ganti`, {
                guru_id: guruId,
            });
            Swal.fire({ icon: 'success', title: 'Berhasil', text: res.data?.message || 'Penugasan berhasil diganti.', confirmButtonColor: '#1a5f51', timer: 1800, showConfirmButton: false });
            closeModal();
            await doRefresh();
        } catch (err) {
            setSaving(false);
            if (axios.isAxiosError(err) && err.response?.status === 409) { await handleConflict(err); return; }
            showApiError(err, 'Gagal mengganti penugasan.');
        }
    };

    // === TULIS: cabut penugasan (alasan diwajibkan UI; backend nullable string max 255) ===
    const handleCabut = async (row: KandidatRow) => {
        if (!row.assignment_id) return;
        const { value: reason, isConfirmed } = await Swal.fire({
            title: 'Cabut Penugasan?',
            html: `Guru <b>${row.guru_name ?? '-'}</b> akan dicabut dari penugasan <b>${row.name}</b>.<br/>Histori tetap tersimpan permanen.`,
            icon: 'warning',
            input: 'textarea',
            inputPlaceholder: 'Alasan pencabutan (wajib diisi)...',
            inputValidator: (v) => (!v || !v.trim()) ? 'Alasan wajib diisi.' : (v.length > 255 ? 'Maksimal 255 karakter.' : null),
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#718096',
            confirmButtonText: 'Ya, Cabut',
            cancelButtonText: 'Batal',
        });
        if (!isConfirmed) return;

        try {
            const res = await libAxios.post(`/api/admin/observasi-assignments/${row.assignment_id}/cabut`, {
                reason: reason.trim(),
            });
            Swal.fire({ icon: 'success', title: 'Berhasil', text: res.data?.message || 'Penugasan berhasil dicabut.', confirmButtonColor: '#1a5f51', timer: 1800, showConfirmButton: false });
            await doRefresh();
        } catch (err) {
            if (axios.isAxiosError(err) && err.response?.status === 409) { await handleConflict(err); return; }
            showApiError(err, 'Gagal mencabut penugasan.');
        }
    };

    // === BACA: histori kandidat ===
    const handleHistory = async (row: KandidatRow) => {
        try {
            const res = await libAxios.get<{ kandidat: { id: number; name: string }; history: HistoryRow[] }>(
                `/api/admin/observasi-assignments/history/${row.id}`
            );
            const items = (res.data.history ?? []).map((h) => {
                const chip = h.status === 'AKTIF' ? '🟢' : h.status === 'DIGANTI' ? '🔵' : '🔴';
                const reason = h.revoked_reason ? `<div class="${styles.historyReason}">Alasan cabut: ${h.revoked_reason}</div>` : '';
                return `<li class="${styles.historyItem}">
                    <div class="${styles.historyTop}">
                        <span class="${styles.historyGuru}">${h.guru_name ?? `Guru #${h.guru_id}`}</span>
                        <span class="${styles.statusChip} ${h.status === 'AKTIF' ? styles.chipAktif : h.status === 'DIGANTI' ? styles.chipDiganti : styles.chipDicabut}">${chip} ${h.status}</span>
                    </div>
                    <div class="${styles.historyMeta}">
                        Mulai: ${h.started_at ? new Date(h.started_at).toLocaleString('id-ID') : '-'}<br/>
                        Selesai: ${h.ended_at ? new Date(h.ended_at).toLocaleString('id-ID') : '-'}<br/>
        Oleh: ${h.assigned_by_name ?? '-'}
                    </div>
                    ${reason}
                </li>`;
            }).join('');
            Swal.fire({
                title: `Histori — ${res.data.kandidat?.name ?? row.name}`,
                html: items
                    ? `<ul class="${styles.historyList}">${items}</ul>`
                    : '<p style="color:#718096;">Belum ada riwayat penugasan.</p>',
                width: 560,
                confirmButtonColor: '#1a5f51',
                confirmButtonText: 'Tutup',
            });
        } catch (err) {
            showApiError(err, 'Gagal memuat histori penugasan.');
        }
    };

    const filtered = kandidat.filter((k) => {
        const m = k.name.toLowerCase().includes(search.toLowerCase());
        const u = onlyUnassigned ? k.assignment_id === null : true;
        return m && u;
    });

    if (load.loading) {
        return (
            <div className={styles.container}>
                <div className={styles.loading}>Memuat Data Penugasan...</div>
            </div>
        );
    }

    return (
        <div className={styles.container}>
            <div className={styles.header}>
                <div className={styles.title}>
                    <h1>Penugasan Guru Observasi</h1>
                    <p>Kelola guru penilai observasi untuk setiap calon murid SD. Satu guru aktif per kandidat; histori permanen.</p>
                </div>
            </div>

            {load.error && (
                <div className={styles.errorBanner}>
                    <span>{load.error}</span>
                    <button className={styles.retryBtn} onClick={doRefresh}>Coba Lagi</button>
                </div>
            )}

            <div className={styles.card}>
                <div className={styles.toolbar}>
                    <div className={styles.searchWrapper}>
                        <input
                            type="text"
                            className={styles.searchInput}
                            placeholder="Cari nama calon murid SD..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                    <label style={{ fontSize: '14px', fontWeight: 700, color: '#4a5568', display: 'flex', alignItems: 'center', gap: '8px', cursor: 'pointer' }}>
                        <input type="checkbox" className={styles.filterSelect} style={{ width: '18px', height: '18px', accentColor: '#1a5f51', padding: 0, cursor: 'pointer' }} checked={onlyUnassigned} onChange={(e) => setOnlyUnassigned(e.target.checked)} />
                        Belum ditugaskan
                    </label>
                </div>

                <div className={styles.tableWrapper}>
                    <table className={styles.dataTable}>
                        <thead>
                            <tr>
                                <th>Calon Murid SD</th>
                                <th>Jadwal Observasi</th>
                                <th>Guru Penilai Aktif</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filtered.length === 0 && (
                                <tr>
                                    <td colSpan={4} className={styles.emptyState}>
                                        {kandidat.length === 0 ? 'Belum ada calon murid SD.' : 'Tidak ada data yang cocok dengan filter.'}
                                    </td>
                                </tr>
                            )}
                            {filtered.map((k) => (
                                <tr key={k.id}>
                                    <td>
                                        <div className={styles.studentName}>{k.name}</div>
                                        <div className={styles.subText}>ID #{k.id}</div>
                                    </td>
                                    <td className={styles.subText}>{k.jadwal_observasi || 'Belum dijadwalkan'}</td>
                                    <td>
                                        {k.assignment_id ? (
                                            <>
                                                <div className={styles.guruAktif}>{k.guru_name ?? `Guru #${k.guru_id}`}</div>
                                                <div className={styles.subText}>Sejak {k.started_at ? new Date(k.started_at).toLocaleDateString('id-ID') : '-'}</div>
                                            </>
                                        ) : (
                                            <span className={styles.badgeBelum}>Belum Ditugaskan</span>
                                        )}
                                    </td>
                                    <td className={styles.aksiCol}>
                                        {k.assignment_id ? (
                                            <>
                                                <button className={`${styles.btnAksi} ${styles.btnGanti}`} onClick={() => { setModal({ mode: 'ganti', row: k }); setSelectedGuru(''); }}>Ganti Guru</button>
                                                <button className={`${styles.btnAksi} ${styles.btnCabut}`} onClick={() => handleCabut(k)}>Cabut</button>
                                            </>
                                        ) : (
                                            <button className={`${styles.btnAksi} ${styles.btnAssign}`} onClick={() => { setModal({ mode: 'assign', row: k }); setSelectedGuru(''); }}>Tugaskan</button>
                                        )}
                                        <button className={`${styles.btnAksi} ${styles.btnHistory}`} onClick={() => handleHistory(k)}>Histori</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Modal buat / ganti */}
            {modal && (
                <div className={styles.modalOverlay} onClick={(e) => { if (e.target === e.currentTarget) closeModal(); }}>
                    <div className={styles.modalBox}>
                        <h2 className={styles.modalTitle}>{modal.mode === 'assign' ? 'Buat Penugasan Baru' : 'Ganti Guru Penilai'}</h2>
                        <p className={styles.modalSub}>
                            Kandidat: <b>{modal.row.name}</b>
                            {modal.mode === 'ganti' && (
                                <> — penilai saat ini: <b>{modal.row.guru_name ?? '-'}</b></>
                            )}
                        </p>
                        <div className={styles.formGroup}>
                            <label className={styles.formLabel} htmlFor="guru-select">Guru Penilai</label>
                            <select
                                id="guru-select"
                                className={styles.formSelect}
                                value={selectedGuru}
                                onChange={(e) => setSelectedGuru(e.target.value)}
                                disabled={saving}
                            >
                                <option value="">— Pilih Guru —</option>
                                {gurus.map((g) => (
                                    <option key={g.id} value={g.id}>
                                        {g.name}{modal.mode === 'ganti' && g.id === modal.row.guru_id ? ' (saat ini)' : ''}
                                    </option>
                                ))}
                            </select>
                            {modal.mode === 'ganti' && <div className={styles.formHint}>Penugasan lama menjadi DIGANTI (histori permanen), guru baru menjadi AKTIF.</div>}
                        </div>
                        <div className={styles.modalActions}>
                            <button type="button" className={styles.cancelBtn} onClick={closeModal} disabled={saving}>Batal</button>
                            <button
                                type="button"
                                className={styles.saveBtn}
                                onClick={modal.mode === 'assign' ? submitAssign : submitGanti}
                                disabled={saving || !selectedGuru}
                            >
                                {saving ? 'Menyimpan...' : modal.mode === 'assign' ? 'Tugaskan Guru' : 'Simpan Perubahan'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
