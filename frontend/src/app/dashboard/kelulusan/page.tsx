// src/app/dashboard/kelulusan/page.tsx
'use client';

import { useState, useEffect, useMemo } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './kelulusan.module.css';

export default function ManajemenKelulusanPage() {
    const [students, setStudents] = useState<any[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    
    // Filter State
    const [searchTerm, setSearchTerm] = useState('');
    const [jenjangFilter, setJenjangFilter] = useState('SD'); // Default SDIT
    const [statusFilter, setStatusFilter] = useState('SEMUA'); 
    
    // Checkbox Massal
    const [selectedIds, setSelectedIds] = useState<number[]>([]);

    // State Pengaturan Waktu Rilis (Sesuai Jenjang)
    const [waktuRilisSD, setWaktuRilisSD] = useState('');
    const [waktuRilisSMP, setWaktuRilisSMP] = useState('');

    const fetchData = async () => {
        try {
            // Ambil data siswa & jadwal rilis asli secara paralel (efisien & anti-lag)
            const [resStudents, resJadwal] = await Promise.all([
                libAxios.get('/api/admin/kelulusan'),
                libAxios.get('/api/admin/kelulusan/setting-jadwal')
            ]);
            
            setStudents(resStudents.data);
            setWaktuRilisSD(resJadwal.data.SD || '');
            setWaktuRilisSMP(resJadwal.data.SMP || '');
        } catch (error) { 
            console.error('Gagal mengambil data sistem:', error); 
        } finally { 
            setIsLoading(false); 
        }
    };

    useEffect(() => { fetchData(); }, []);

    // Gunakan useMemo untuk performa pencarian kilat tanpa membebani re-render komponen
    const filteredStudents = useMemo(() => {
        return students.filter(s => {
            const matchSearch = s.name.toLowerCase().includes(searchTerm.toLowerCase());
            const matchJenjang = s.jenjang === jenjangFilter;
            const matchStatus = statusFilter === 'SEMUA' ? true : (s.status_kelulusan || 'BELUM') === statusFilter;
            return matchSearch && matchJenjang && matchStatus;
        });
    }, [students, searchTerm, jenjangFilter, statusFilter]);

    // === LOGIKA CHECKBOX ===
    const handleSelectAll = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.checked) setSelectedIds(filteredStudents.map(s => s.id));
        else setSelectedIds([]);
    };

    const handleSelect = (id: number) => {
        if (selectedIds.includes(id)) setSelectedIds(selectedIds.filter(i => i !== id));
        else setSelectedIds([...selectedIds, id]);
    };

    const handleTabChange = (jenjang: string) => {
        setJenjangFilter(jenjang);
        setSelectedIds([]);
    };

    // Simpan Pengaturan Waktu Pengumuman
    const handleSaveJadwalRilis = async (e: React.FormEvent) => {
        e.preventDefault();
        const targetWaktu = jenjangFilter === 'SD' ? waktuRilisSD : waktuRilisSMP;
        
        if (!targetWaktu) {
            Swal.fire('Form Kosong', 'Silakan pilih tanggal dan jam terlebih dahulu.', 'warning');
            return;
        }

        Swal.fire({ title: 'Menyimpan Jadwal...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            await libAxios.post('/api/admin/kelulusan/setting-jadwal', {
                jenjang: jenjangFilter,
                waktu_rilis: targetWaktu
            });
            Swal.fire('Jadwal Disimpan!', `Sistem countdown device orang tua ${jenjangFilter}IT telah aktif terproteksi.`, 'success');
            fetchData();
        } catch (error) {
            Swal.fire('Gagal', 'Terjadi kesalahan saat menyimpan jadwal.', 'error');
        }
    };

    // Aksi API Status Kelulusan (Proteksi Transaksi Database Massal)
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
            confirmButtonText: `Ya, Eksekusi Status`,
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
                } catch (error) { 
                    Swal.fire('Sistem Sibuk', 'Gagal memproses permintaan massal. Coba beberapa saat lagi.', 'error'); 
                }
            }
        });
    };

    if (isLoading) return <div className={styles.loading}>Memuat Keamanan Data Siswa...</div>;

    const isSMP = jenjangFilter === 'SMP';

    return (
        <div className={styles.container}>
            <div className={styles.header}>
                <div className={styles.titleArea}>
                    <h1>Manajemen Kelulusan <span>{isSMP ? 'SMPIT' : 'SDIT'}</span></h1>
                    <p>Tentukan hasil akhir seleksi penerimaan peserta didik baru.</p>
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
                    <div className={styles.statBox}>
                        <div className={styles.statVal} style={{color: '#64748b'}}>{students.filter(s => s.jenjang === jenjangFilter && (s.status_kelulusan === 'BELUM' || !s.status_kelulusan)).length}</div>
                        <div className={styles.statLabel}>Belum Ditentukan</div>
                    </div>
                </div>
            </div>

            {/* TAB JENJANG */}
            <div className={styles.tabContainer}>
                <button className={`${styles.tabBtn} ${jenjangFilter === 'SD' ? styles.tabActive : ''}`} onClick={() => handleTabChange('SD')}>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
                    SDIT Al-Mubarak
                </button>
                <button className={`${styles.tabBtn} ${jenjangFilter === 'SMP' ? styles.tabActive : ''}`} onClick={() => handleTabChange('SMP')}>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                    SMPIT Al-Mubarak
                </button>
            </div>

            {/* CARD SETTING WAKTU RILIS */}
            <div className={styles.settingJadwalCard}>
                <div className={styles.settingHeader}>
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    <div>
                        <h3>Pengaturan Countdown Rilis Hasil {isSMP ? 'SMPIT' : 'SDIT'}</h3>
                        <p>Sistem enkripsi backend otomatis menahan data mentah kelulusan sampai timer menyentuh angka nol.</p>
                    </div>
                </div>
                <form onSubmit={handleSaveJadwalRilis} className={styles.settingForm}>
                    <div className={styles.inputWrapperUnified}>
                        <label>Pilih Tanggal & Waktu Rilis</label>
                        <input 
                            type="datetime-local" 
                            className={styles.datetimeInput}
                            value={isSMP ? waktuRilisSMP : waktuRilisSD}
                            onChange={(e) => isSMP ? setWaktuRilisSMP(e.target.value) : setWaktuRilisSD(e.target.value)}
                            required
                        />
                    </div>
                    <button type="submit" className={styles.btnSaveJadwal}>
                        Kunci Waktu Hitung Mundur
                    </button>
                </form>
            </div>

            {/* TABEL UTAMA */}
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
                                <th style={{width: '40px', textAlign: 'center'}}>
                                    <input type="checkbox" className={styles.customCheckbox} checked={selectedIds.length === filteredStudents.length && filteredStudents.length > 0} onChange={handleSelectAll} />
                                </th>
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
                                            <td style={{textAlign: 'center'}}>
                                                <input type="checkbox" className={styles.customCheckbox} checked={selectedIds.includes(siswa.id)} onChange={() => handleSelect(siswa.id)} />
                                            </td>
                                            <td>
                                                <div className={styles.studentName}>{siswa.name}</div>
                                            </td>
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
                                <tr>
                                    <td colSpan={5} style={{textAlign: 'center', padding: '50px', color: '#a0aec0', fontWeight: 600}}>
                                        Tidak ada pendaftar di kategori ini.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}