'use client';

import { useState, useEffect, useMemo } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import { type KelulusanRow, type Jenjang, type SuratKelulusanFormState } from '@/lib/domain';
import styles from './kelulusan.module.css';

/**
 * Manajemen Kelulusan — strictly per-jenjang (SDIT / SMPIT).
 *
 * P4-CB: the backend treats each jenjang's Surat Keputusan as fully
 * independent — its own settings row, logo, signature and PDF template, with
 * NO cross-jenjang fallback. This page mirrors that contract: SD and SMP never
 * share config state, an unconfigured jenjang shows an empty form (never the
 * sibling school's values), and the preview is always the SERVER-rendered
 * authoritative PDF for the jenjang being edited — never a hardcoded SD mockup.
 */

type SuratFormMap = Record<Jenjang, SuratKelulusanFormState>;

const emptySuratForm = (): SuratKelulusanFormState => ({
    nomor_surat: '',
    tanggal_surat: '',
    nama_kepsek: '',
    nama_sekolah: '',
    tagline: '',
    alamat: '',
    kontak: '',
    isi_lulus: '',
    isi_gagal: '',
    logoB64: '',
    ttdB64: '',
    logoPreview: null,
    ttdPreview: null,
});

/** Merge a server config over an empty form. A null/malformed response
 *  (jenjang not yet configured) yields a clean empty form — never borrowed
 *  values from the other jenjang. */
const mergeSuratConfig = (data: unknown): SuratKelulusanFormState => {
    const base = emptySuratForm();
    if (!data || typeof data !== 'object') return base;
    const d = data as Partial<SuratKelulusanFormState>;
    return {
        nomor_surat: d.nomor_surat ?? '',
        tanggal_surat: d.tanggal_surat ?? '',
        nama_kepsek: d.nama_kepsek ?? '',
        nama_sekolah: d.nama_sekolah ?? '',
        tagline: d.tagline ?? '',
        alamat: d.alamat ?? '',
        kontak: d.kontak ?? '',
        isi_lulus: d.isi_lulus ?? '',
        isi_gagal: d.isi_gagal ?? '',
        // A fresh merge has no pending uploads; only stored previews.
        logoB64: '',
        ttdB64: '',
        logoPreview: d.logoPreview ?? null,
        ttdPreview: d.ttdPreview ?? null,
    };
};

export default function ManajemenKelulusanPage() {
    const [students, setStudents] = useState<KelulusanRow[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    
    // Tab State
    const [activeTab, setActiveTab] = useState<'DATA' | 'SURAT'>('DATA');

    // Filter State
    const [searchTerm, setSearchTerm] = useState('');
    const [jenjangFilter, setJenjangFilter] = useState<Jenjang>('SD');
    const [statusFilter, setStatusFilter] = useState('SEMUA');
    const [selectedIds, setSelectedIds] = useState<number[]>([]);

    // State Pengaturan Waktu Rilis
    const [waktuRilisSD, setWaktuRilisSD] = useState('');
    const [waktuRilisSMP, setWaktuRilisSMP] = useState('');

    // State Konfigurasi Surat Keputusan — strictly per-jenjang, never merged.
    const [suratForms, setSuratForms] = useState<SuratFormMap>({
        SD: emptySuratForm(),
        SMP: emptySuratForm(),
    });

    // State Preview Modal — server-rendered PDF for the edited jenjang.
    const [showPreview, setShowPreview] = useState(false);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [previewLabel, setPreviewLabel] = useState('');

    // The form currently on screen — always the active jenjang's own state.
    const suratForm = suratForms[jenjangFilter];

    const setSuratField = <K extends keyof SuratKelulusanFormState>(
        field: K,
        value: SuratKelulusanFormState[K]
    ) => {
        const jenjang = jenjangFilter;
        setSuratForms(prev => ({
            ...prev,
            [jenjang]: { ...prev[jenjang], [field]: value },
        }));
    };

    const fetchData = async () => {
        try {
            // Each jenjang's letter config is fetched from its OWN endpoint; a
            // missing config returns `null` (a clean empty form), never the
            // sibling school's data.
            const [resStudents, resJadwal, resSuratSD, resSuratSMP] = await Promise.all([
                libAxios.get('/api/admin/kelulusan'),
                libAxios.get('/api/admin/kelulusan/setting-jadwal'),
                libAxios.get('/api/admin/kelulusan/setting-surat/SD').catch(() => ({ data: null })),
                libAxios.get('/api/admin/kelulusan/setting-surat/SMP').catch(() => ({ data: null })),
            ]);
            
            setStudents(resStudents.data);
            setWaktuRilisSD(resJadwal.data.SD || '');
            setWaktuRilisSMP(resJadwal.data.SMP || '');

            setSuratForms({
                SD: mergeSuratConfig(resSuratSD.data),
                SMP: mergeSuratConfig(resSuratSMP.data),
            });
        } catch (error) {
            console.error('Gagal mengambil data sistem:', error);
        } finally {
            setIsLoading(false);
        }
    };

    useEffect(() => { fetchData(); }, []);

    const filteredStudents = useMemo(() => {
        return students.filter(s => {
            const matchSearch = s.name.toLowerCase().includes(searchTerm.toLowerCase());
            const matchJenjang = s.jenjang === jenjangFilter;
            const matchStatus = statusFilter === 'SEMUA' ? true : (s.status_kelulusan || 'BELUM') === statusFilter;
            return matchSearch && matchJenjang && matchStatus;
        });
    }, [students, searchTerm, jenjangFilter, statusFilter]);

    const handleImageUpload = (e: React.ChangeEvent<HTMLInputElement>, field: 'logoB64' | 'ttdB64') => {
        const file = e.target.files?.[0];
        if (!file) return;

        if (file.size > 2 * 1024 * 1024) {
            Swal.fire('Terlalu Besar', 'Maksimal ukuran gambar adalah 2 MB.', 'warning');
            return;
        }

        const reader = new FileReader();
        reader.onloadend = () => {
            const img = new Image();
            img.onload = () => {
                // Compress ke max 800px
                const maxSize = 800;
                let { width, height } = img;
                if (width > maxSize || height > maxSize) {
                    if (width > height) { height = Math.round(height * maxSize / width); width = maxSize; }
                    else { width = Math.round(width * maxSize / height); height = maxSize; }
                }
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d')!;

                // Cek apakah file asli PNG — kalau iya, pertahankan transparansi
                const isPng = file.type === 'image/png';
                if (!isPng) {
                    // Untuk non-PNG, fill background putih dulu biar tidak hitam
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, width, height);
                }
                ctx.drawImage(img, 0, 0, width, height);

                // PNG untuk transparansi, JPEG untuk foto biasa
                const compressed = isPng
                    ? canvas.toDataURL('image/png')
                    : canvas.toDataURL('image/jpeg', 0.8);
                // The fresh upload belongs to THIS jenjang's form only.
                setSuratField(field, compressed);
                // Show the freshly-chosen image immediately in the preview box.
                setSuratField(field === 'logoB64' ? 'logoPreview' : 'ttdPreview', compressed);
            };
            img.src = reader.result as string;
        };
        reader.readAsDataURL(file);
    };

    const handleSelectAll = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.checked) setSelectedIds(filteredStudents.map(s => s.id));
        else setSelectedIds([]);
    };

    const handleSelect = (id: number) => {
        if (selectedIds.includes(id)) setSelectedIds(selectedIds.filter(i => i !== id));
        else setSelectedIds([...selectedIds, id]);
    };

    const handleJenjangChange = (jenjang: Jenjang) => {
        setJenjangFilter(jenjang);
        setSelectedIds([]);
    };

    const handleSaveJadwalRilis = async (e: React.FormEvent) => {
        e.preventDefault();
        const targetWaktu = jenjangFilter === 'SD' ? waktuRilisSD : waktuRilisSMP;
        if (!targetWaktu) { Swal.fire('Form Kosong', 'Silakan pilih tanggal dan jam terlebih dahulu.', 'warning'); return; }

        Swal.fire({ title: 'Menyimpan Jadwal...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            await libAxios.post('/api/admin/kelulusan/setting-jadwal', { jenjang: jenjangFilter, waktu_rilis: targetWaktu });
            Swal.fire('Jadwal Disimpan!', `Sistem countdown device orang tua ${jenjangFilter}IT telah aktif terproteksi.`, 'success');
            fetchData();
        } catch { Swal.fire('Gagal', 'Terjadi kesalahan saat menyimpan jadwal.', 'error'); }
    };

    const setStatus = async (id: number | null, status: string) => {
        const actionText = status === 'LULUS' ? 'meluluskan' : status === 'TIDAK_LULUS' ? 'menggagalkan' : 'mereset status';
        const isBulk = id === null;

        Swal.fire({
            title: `Yakin ingin ${actionText} ${isBulk ? selectedIds.length + ' siswa' : 'siswa ini'}?`,
            text: status === 'LULUS' ? "Siswa pilihan akan mendapatkan akses penuh ke halaman Daftar Ulang." : "Status kelulusan siswa akan diubah.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: status === 'LULUS' ? '#10b981' : status === 'TIDAK_LULUS' ? '#ef4444' : '#64748b',
            cancelButtonColor: '#cbd5e0',
            confirmButtonText: `Ya, Eksekusi`,
            cancelButtonText: 'Batal'
        }).then(async (result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Memproses Data...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                try {
                    if (isBulk) {
                        await libAxios.post(`/api/admin/kelulusan/bulk`, { user_ids: selectedIds, status });
                        setSelectedIds([]);
                    } else {
                        await libAxios.post(`/api/admin/kelulusan/${id}`, { status });
                    }
                    Swal.fire('Selesai!', `Perubahan status berhasil diamankan ke dalam server.`, 'success');
                    fetchData();
                } catch { Swal.fire('Sistem Sibuk', 'Gagal memproses permintaan massal.', 'error'); }
            }
        });
    };

    if (isLoading) return <div className={styles.loading}>Memuat Keamanan Data Siswa...</div>;

    const isSMP = jenjangFilter === 'SMP';

    const handleSaveSurat = async (e: React.FormEvent) => {
        e.preventDefault();
        const jenjang = jenjangFilter;
        Swal.fire({ title: 'Menyimpan Konfigurasi...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            await libAxios.get('/sanctum/csrf-cookie');
            // POST is scoped to THIS jenjang only — never touches the sibling.
            const res = await libAxios.post(`/api/admin/kelulusan/setting-surat/${jenjang}`, suratForm);
            // Re-sync this jenjang's form from the saved record the server
            // echoed back (fresh logo/ttd previews, cleared pending uploads).
            if (res.data && typeof res.data === 'object') {
                setSuratForms(prev => ({ ...prev, [jenjang]: mergeSuratConfig(res.data) }));
            }
            Swal.fire('Berhasil!', `Format Surat Keputusan ${isSMP ? 'SMPIT' : 'SDIT'} berhasil diperbarui.`, 'success');
        } catch (error: unknown) {
            const data = (error as { response?: { data?: { message?: string; errors?: unknown } } }).response?.data;
            // Preserves the original `(message || errors) ? ... : ...` precedence.
            const msg = data?.message || data?.errors
                ? JSON.stringify(data?.errors)
                : 'Gagal menyimpan konfigurasi surat.';
            Swal.fire('Gagal', msg, 'error');
        }
    };

    /**
     * Pratinjau Surat Keputusan — always the AUTHORITATIVE server-rendered PDF.
     *
     * The admin preview is scoped to the jenjang being edited: it asks the
     * backend to render that jenjang's OWN template + stored config. This
     * guarantees SD and SMP each show their correct design and branding, and
     * the preview is byte-for-byte what a parent will eventually download.
     */
    const openPreview = async (type: 'LULUS' | 'TIDAK_LULUS') => {
        const jenjang = jenjangFilter;
        setPreviewLabel(`${isSMP ? 'SMPIT' : 'SDIT'} — ${type === 'LULUS' ? 'DITERIMA' : 'TIDAK DITERIMA'}`);
        setShowPreview(true);
        setPreviewUrl(null);
        try {
            const res = await libAxios.get(
                `/api/admin/kelulusan/setting-surat/${jenjang}/preview`,
                { params: { status: type }, responseType: 'blob' }
            );
            setPreviewUrl(URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' })));
        } catch {
            setShowPreview(false);
            Swal.fire(
                'Pratinjau Gagal',
                `Konfigurasi surat ${isSMP ? 'SMPIT' : 'SDIT'} belum tersimpan. Simpan format terlebih dahulu.`,
                'warning'
            );
        }
    };

    const closePreview = () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        setPreviewUrl(null);
        setShowPreview(false);
    };

    return (
        <div className={styles.container}>
            <div className={styles.header}>
                <div className={styles.titleArea}>
                    <h1>Manajemen Kelulusan <span>{isSMP ? 'SMPIT' : 'SDIT'}</span></h1>
                    <p>Tentukan hasil akhir seleksi dan konfigurasi format cetak pengumuman.</p>
                </div>
                
                <div className={styles.statCards}>
                    <div className={styles.statBox}>
                        <div className={styles.statVal} style={{color: '#10b981'}}>{students.filter(s => s.jenjang === jenjangFilter && s.status_kelulusan === 'LULUS').length}</div>
                        <div className={styles.statLabel}>Lulus</div>
                    </div>
                    <div className={styles.statBox}>
                        <div className={styles.statVal} style={{color: '#ef4444'}}>{students.filter(s => s.jenjang === jenjangFilter && s.status_kelulusan === 'TIDAK_LULUS').length}</div>
                        <div className={styles.statLabel}>Tidak Lulus</div>
                    </div>
                </div>
            </div>

            <div className={styles.mainTabContainer}>
                <button className={`${styles.mainTabBtn} ${activeTab === 'DATA' ? styles.mainTabActive : ''}`} onClick={() => setActiveTab('DATA')}>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    Data Peserta Seleksi
                </button>
                <button className={`${styles.mainTabBtn} ${activeTab === 'SURAT' ? styles.mainTabActive : ''}`} onClick={() => setActiveTab('SURAT')}>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    Konfigurasi Surat Keputusan
                </button>
            </div>

            {/* === KONTEN TAB DATA SISWA === */}
            {activeTab === 'DATA' && (
                <>
                    <div className={styles.tabContainer}>
                        <button className={`${styles.tabBtn} ${jenjangFilter === 'SD' ? styles.tabActive : ''}`} onClick={() => handleJenjangChange('SD')}>SDIT Al-Mubarak</button>
                        <button className={`${styles.tabBtn} ${jenjangFilter === 'SMP' ? styles.tabActive : ''}`} onClick={() => handleJenjangChange('SMP')}>SMPIT Al-Mubarak</button>
                    </div>

                    <div className={styles.settingJadwalCard}>
                        <div className={styles.settingHeader}>
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            <div>
                                <h3>Pengaturan Countdown Rilis Hasil {isSMP ? 'SMPIT' : 'SDIT'}</h3>
                                <p>Sistem enkripsi backend menahan data mentah kelulusan sampai timer menyentuh angka nol.</p>
                            </div>
                        </div>
                        <form onSubmit={handleSaveJadwalRilis} className={styles.settingForm}>
                            <div className={styles.inputWrapperUnified}>
                                <label>Pilih Tanggal & Waktu Rilis</label>
                                <input type="datetime-local" className={styles.datetimeInput} value={isSMP ? waktuRilisSMP : waktuRilisSD} onChange={(e) => isSMP ? setWaktuRilisSMP(e.target.value) : setWaktuRilisSD(e.target.value)} required />
                            </div>
                            <button type="submit" className={styles.btnSaveJadwal}>Kunci Waktu Hitung Mundur</button>
                        </form>
                    </div>

                    <div className={styles.card}>
                        <div className={styles.toolbar}>
                            <div style={{display: 'flex', gap: '15px', flex: 1}}>
                                <div className={styles.searchWrapper}>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a0aec0" strokeWidth="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                    <input type="text" className={styles.searchInput} placeholder="Cari nama calon siswa..." value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} />
                                </div>
                                <select className={styles.statusSelect} value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                                    <option value="SEMUA">Semua Status</option>
                                    <option value="LULUS">Hanya Lulus</option>
                                    <option value="TIDAK_LULUS">Tidak Lulus</option>
                                    <option value="BELUM">Belum Ditentukan</option>
                                </select>
                            </div>

                            {selectedIds.length > 0 && (
                                <div className={styles.bulkActions}>
                                    <span className={styles.selectedCount}>{selectedIds.length} Terpilih</span>
                                    <button className={`${styles.btnAction} ${styles.btnSuccess}`} onClick={() => setStatus(null, 'LULUS')}>Luluskan</button>
                                    <button className={`${styles.btnAction} ${styles.btnDanger}`} onClick={() => setStatus(null, 'TIDAK_LULUS')}>Gagalkan</button>
                                    <button className={`${styles.btnAction} ${styles.btnNeutral}`} onClick={() => setStatus(null, 'BELUM')}>Reset</button>
                                </div>
                            )}
                        </div>

                        <div className={styles.tableWrapper}>
                            <table className={styles.dataTable}>
                                <thead>
                                    <tr>
                                        <th style={{width: '40px', textAlign: 'center'}}><input type="checkbox" className={styles.customCheckbox} checked={selectedIds.length === filteredStudents.length && filteredStudents.length > 0} onChange={handleSelectAll} /></th>
                                        <th>Calon Peserta Didik</th>
                                        <th>Email Pendaftar</th>
                                        <th style={{textAlign: 'center'}}>Status Kelulusan</th>
                                        <th style={{textAlign: 'right'}}>Aksi Cepat</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredStudents.length > 0 ? (
                                        filteredStudents.map((siswa, index) => {
                                            const st = siswa.status_kelulusan || 'BELUM';
                                            return (
                                                <tr key={`${siswa.id}-${index}`} className={selectedIds.includes(siswa.id) ? styles.rowSelected : ''}>
                                                    <td style={{textAlign: 'center'}}><input type="checkbox" className={styles.customCheckbox} checked={selectedIds.includes(siswa.id)} onChange={() => handleSelect(siswa.id)} /></td>
                                                    <td><div className={styles.studentName}>{siswa.name}</div></td>
                                                    <td><div className={styles.submitTime}>{siswa.email}</div></td>
                                                    <td style={{textAlign: 'center'}}>
                                                        <span className={`${styles.badge} ${st === 'LULUS' ? styles.badgeLunas : st === 'TIDAK_LULUS' ? styles.badgeGagal : styles.badgeBelum}`}>
                                                            {st === 'LULUS' ? 'LULUS' : st === 'TIDAK_LULUS' ? 'TIDAK LULUS' : 'BELUM DITENTUKAN'}
                                                        </span>
                                                    </td>
                                                    <td style={{textAlign: 'right'}}>
                                                        {st === 'BELUM' ? (
                                                            <div className={styles.quickActionGroup}>
                                                                <button className={`${styles.btnQuick} ${styles.quickSuccess}`} onClick={() => setStatus(siswa.id, 'LULUS')}>Lulus</button>
                                                                <button className={`${styles.btnQuick} ${styles.quickDanger}`} onClick={() => setStatus(siswa.id, 'TIDAK_LULUS')}>Gagal</button>
                                                            </div>
                                                        ) : (
                                                            <button className={`${styles.btnQuick} ${styles.quickReset}`} onClick={() => setStatus(siswa.id, 'BELUM')}>
                                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg> Batalkan
                                                            </button>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    ) : (
                                        <tr><td colSpan={5} style={{textAlign: 'center', padding: '50px', color: '#a0aec0', fontWeight: 600}}>Tidak ada pendaftar di kategori ini.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </>
            )}

            {/* === KONTEN TAB KONFIGURASI SURAT === */}
            {activeTab === 'SURAT' && (
                <div className={styles.card} style={{padding: '30px'}}>
                    <div className={styles.suratInfoBox}>
                        <div style={{display: 'flex', gap: '15px', alignItems: 'flex-start'}}>
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            <p>
                                Format Surat Keputusan <strong>{isSMP ? 'SMPIT Al Mubarak' : 'SDIT Al Mubarak'}</strong> yang disimpan di sini
                                hanya berlaku untuk jenjang ini — konfigurasi {isSMP ? 'SDIT' : 'SMPIT'} tidak ikut berubah.
                                Gunakan tanda bintang ganda (contoh: <strong>**teks tebal**</strong>) untuk menebalkan tulisan.
                            </p>
                        </div>
                        <div className={styles.previewActionGroup}>
                            <button type="button" className={styles.btnPreview} onClick={() => openPreview('LULUS')}>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg> Preview Surat Lulus
                            </button>
                            <button type="button" className={`${styles.btnPreview} ${styles.btnPreviewDanger}`} onClick={() => openPreview('TIDAK_LULUS')}>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg> Preview Surat Gagal
                            </button>
                        </div>
                    </div>

                    {/* P4-CB — jenjang picker for the letter config. Each button edits that
                        jenjang's OWN config; the other jenjang is never shown or merged. */}
                    <div className={styles.tabContainer}>
                        <button type="button" className={`${styles.tabBtn} ${jenjangFilter === 'SD' ? styles.tabActive : ''}`} onClick={() => handleJenjangChange('SD')}>SDIT Al-Mubarak</button>
                        <button type="button" className={`${styles.tabBtn} ${jenjangFilter === 'SMP' ? styles.tabActive : ''}`} onClick={() => handleJenjangChange('SMP')}>SMPIT Al-Mubarak</button>
                    </div>

                    <form onSubmit={handleSaveSurat} className={styles.suratFormGrid}>
                        <div className={styles.suratColLeft}>
                            <div className={styles.inputGroup}>
                                <label>Nama Sekolah</label>
                                <input type="text" className={styles.formInput} value={suratForm.nama_sekolah} onChange={(e) => setSuratField('nama_sekolah', e.target.value)} placeholder={isSMP ? 'SMPIT Al Mubarak' : 'SDIT Al Mubarak'} />
                            </div>
                            <div className={styles.inputGroup}>
                                <label>Tagline Sekolah</label>
                                <input type="text" className={styles.formInput} value={suratForm.tagline} onChange={(e) => setSuratField('tagline', e.target.value)} placeholder="Contoh: Sekolah Islam Terpadu" />
                            </div>
                            <div className={styles.inputGroup}>
                                <label>Alamat Sekolah</label>
                                <input type="text" className={styles.formInput} value={suratForm.alamat} onChange={(e) => setSuratField('alamat', e.target.value)} placeholder="Contoh: Jl. Pramuka Sari III/24 Rawasari - Jakarta Pusat" />
                            </div>
                            <div className={styles.inputGroup}>
                                <label>Kontak Sekolah</label>
                                <input type="text" className={styles.formInput} value={suratForm.kontak} onChange={(e) => setSuratField('kontak', e.target.value)} placeholder="Contoh: Telp/Faks. 021 - 4269246" />
                            </div>

                            <div className={styles.inputGroup}>
                                <label>Logo Sekolah</label>
                                <div className={styles.uploadBox}>
                                    {/* P4-A.3 Group B — `logoPreview` is a server-inlined data URI (or a
                                        freshly-chosen upload). Next.js forces `unoptimized` for data:
                                        sources, and this file depends on the DOM `new Image()` constructor,
                                        so <img> is kept; .previewImg sizing unchanged. */}
                                    {/* eslint-disable-next-line @next/next/no-img-element */}
                                    {suratForm.logoPreview
                                        ? <img src={suratForm.logoPreview} alt="Logo" className={styles.previewImg} />
                                        : <div className={styles.placeholderImg}>Belum Ada Logo</div>}
                                    <input type="file" accept="image/*" onChange={(e) => handleImageUpload(e, 'logoB64')} />
                                </div>
                            </div>
                            <div className={styles.inputGroup}>
                                <label>Tanda Tangan Kepsek</label>
                                <div className={styles.uploadBox}>
                                    {/* eslint-disable-next-line @next/next/no-img-element */}
                                    {suratForm.ttdPreview
                                        ? <img src={suratForm.ttdPreview} alt="TTD" className={styles.previewImg} />
                                        : <div className={styles.placeholderImg}>Belum Ada TTD</div>}
                                    <input type="file" accept="image/*" onChange={(e) => handleImageUpload(e, 'ttdB64')} />
                                </div>
                            </div>

                            <div className={styles.inputGroup}>
                                <label>Nomor Surat Keputusan</label>
                                <input type="text" className={styles.formInput} value={suratForm.nomor_surat} onChange={(e) => setSuratField('nomor_surat', e.target.value)} required placeholder={isSMP ? 'Contoh: 132/SPMBM/smpitalmubarak/I/26' : 'Contoh: 132/SPMBM/sditalmubarak/I/26'} />
                            </div>
                            <div className={styles.inputGroup}>
                                <label>Tanggal Surat (Penetapan)</label>
                                <input type="text" className={styles.formInput} value={suratForm.tanggal_surat} onChange={(e) => setSuratField('tanggal_surat', e.target.value)} required placeholder="Contoh: Jakarta, 10 Januari 2026" />
                            </div>
                            <div className={styles.inputGroup}>
                                <label>Nama Kepala Sekolah</label>
                                <input type="text" className={styles.formInput} value={suratForm.nama_kepsek} onChange={(e) => setSuratField('nama_kepsek', e.target.value)} required placeholder="Contoh: Amang Suryaman, M.Pd" />
                            </div>

                            <button type="submit" className={styles.btnSaveSuratFull}>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                                Simpan Konfigurasi Surat {isSMP ? 'SMPIT' : 'SDIT'}
                            </button>
                        </div>

                        <div className={styles.suratColRight}>
                            <div className={styles.inputGroup}>
                                <label className={styles.labelSuccess}>Paragraf Kelulusan (DITERIMA)</label>
                                <textarea
                                    className={`${styles.formInput} ${styles.textareaTall}`}
                                    value={suratForm.isi_lulus}
                                    onChange={(e) => setSuratField('isi_lulus', e.target.value)}
                                    required
                                    placeholder="Masukkan teks poin-poin syarat daftar ulang, rincian biaya masuk, dll..."
                                />
                            </div>

                            <div className={styles.inputGroup}>
                                <label className={styles.labelDanger}>Paragraf Penolakan (TIDAK DITERIMA)</label>
                                <textarea
                                    className={`${styles.formInput} ${styles.textareaMedium}`}
                                    value={suratForm.isi_gagal}
                                    onChange={(e) => setSuratField('isi_gagal', e.target.value)}
                                    required
                                    placeholder="Masukkan teks penolakan atau permohonan maaf karena kuota penuh..."
                                />
                            </div>
                        </div>
                    </form>
                </div>
            )}

            {/* === MODAL PREVIEW SURAT — server-rendered authoritative PDF === */}
            {showPreview && (
                <div className={styles.modalOverlay} onClick={closePreview}>
                    <div className={styles.previewWrapper} onClick={(e) => e.stopPropagation()}>
                        <div className={styles.previewHeader}>
                            <h2>Pratinjau Surat {previewLabel}</h2>
                            <button className={styles.closePreviewBtn} onClick={closePreview}>Tutup Pratinjau</button>
                        </div>

                        {/* P4-CB — the preview is the SERVER-rendered PDF (the exact
                            file parents download), so SD and SMP always show their own
                            correct template, branding and ornaments. Never a hardcoded mockup. */}
                        {previewUrl ? (
                            <iframe
                                src={previewUrl}
                                title="Pratinjau Surat Keputusan"
                                style={{ width: '100%', height: '80vh', border: 'none', background: '#525659', borderRadius: '8px' }}
                            />
                        ) : (
                            <div style={{ padding: '60px', textAlign: 'center', color: '#a0aec0', fontWeight: 600 }}>
                                Menyiapkan pratinjau dari server…
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
