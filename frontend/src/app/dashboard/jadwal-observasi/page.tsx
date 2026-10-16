// src/app/dashboard/jadwal-observasi/page.tsx
'use client';

import { useState, useEffect } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import type { JadwalObservasiRow } from '@/lib/domain';
import styles from './jadwal.module.css'; 

export default function JadwalObservasiAdminPage() {
    const [students, setStudents] = useState<JadwalObservasiRow[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    
    // Fitur Search & Filter
    const [searchTerm, setSearchTerm] = useState('');
    const [jenjangFilter, setJenjangFilter] = useState('SD'); // Default tab SD
    
    // Fitur Checkbox Massal
    const [selectedIds, setSelectedIds] = useState<number[]>([]);

    // Modal State
    const [showModal, setShowModal] = useState(false);
    const [activeStudent, setActiveStudent] = useState<JadwalObservasiRow | null>(null);
    const [form, setForm] = useState({ tanggal: '', jam: '', ruangan: '', perlengkapan: '' });
    const [jamMulai, setJamMulai] = useState('08:00');
    const [jamSelesai, setJamSelesai] = useState('09:30');

    const TIME_PRESETS = [
        { label: 'Sesi 1', start: '08:00', end: '09:30' },
        { label: 'Sesi 2', start: '09:30', end: '11:00' },
        { label: 'Sesi 3', start: '13:00', end: '14:30' },
        { label: 'Sesi 4', start: '14:30', end: '16:00' }
    ];

    const applyTimePreset = (start: string, end: string) => {
        setJamMulai(start);
        setJamSelesai(end);
        setForm(prev => ({ ...prev, jam: `${start} - ${end} WIB` }));
    };

    const handleTimeChange = (newStart: string, newEnd: string) => {
        setJamMulai(newStart);
        setJamSelesai(newEnd);
        setForm(prev => ({ ...prev, jam: `${newStart} - ${newEnd} WIB` }));
    };

    const parseExistingTime = (jamString?: string | null) => {
        if (!jamString) return { start: '08:00', end: '09:30' };
        const match = jamString.match(/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})/);
        if (match) {
            return { start: match[1], end: match[2] };
        }
        return { start: '08:00', end: '09:30' };
    };

    const fetchStudents = async () => {
        try {
            const res = await libAxios.get<JadwalObservasiRow[]>('/api/admin/jadwal-observasi');
            setStudents(res.data);
        } catch (error) { console.error(error); } 
        finally { setIsLoading(false); }
    };

    useEffect(() => { fetchStudents(); }, []);

    // Filter gabungan: Jenjang & Search
    const filteredStudents = students.filter(s => {
        const matchSearch = s.name.toLowerCase().includes(searchTerm.toLowerCase());
        const matchJenjang = s.jenjang === jenjangFilter; // Filter SD / SMP
        return matchSearch && matchJenjang;
    });

    // === LOGIKA CHECKBOX ===
    const handleSelectAll = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.checked) setSelectedIds(filteredStudents.map(s => s.id));
        else setSelectedIds([]);
    };

    const handleSelect = (id: number) => {
        if (selectedIds.includes(id)) setSelectedIds(selectedIds.filter(i => i !== id));
        else setSelectedIds([...selectedIds, id]);
    };

    // Ganti tab reset centangan
    const handleTabChange = (jenjang: string) => {
        setJenjangFilter(jenjang);
        setSelectedIds([]);
    };

    // === BUKA MODAL ===
    const openModalSingle = (siswa: JadwalObservasiRow) => {
        setActiveStudent(siswa);
        const { start, end } = parseExistingTime(siswa.jam_observasi);
        setJamMulai(start);
        setJamSelesai(end);
        const jamFormatted = siswa.jam_observasi || `${start} - ${end} WIB`;
        setForm({ 
            tanggal: siswa.jadwal_observasi || '', 
            jam: jamFormatted, 
            ruangan: siswa.ruang_observasi || '',
            perlengkapan: siswa.perlengkapan || (jenjangFilter === 'SD' ? 'Alat Tulis & Meja Lipat' : 'Alat Tulis & Papan Ujian')
        });
        setShowModal(true);
    };

    const openModalBulk = () => {
        if (selectedIds.length === 0) {
            Swal.fire('Pilih Siswa', 'Centang minimal satu siswa untuk di-set jadwalnya.', 'warning');
            return;
        }
        setActiveStudent(null);
        setJamMulai('08:00');
        setJamSelesai('09:30');
        setForm({ 
            tanggal: '', 
            jam: '08:00 - 09:30 WIB', 
            ruangan: '', 
            perlengkapan: jenjangFilter === 'SD' ? 'Alat Tulis & Meja Lipat' : 'Alat Tulis & Papan Ujian' 
        });
        setShowModal(true);
    };

    // === SIMPAN KE BACKEND ===
    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        Swal.fire({ title: 'Menyimpan...', didOpen: () => Swal.showLoading() });
        try {
            if (activeStudent) {
                await libAxios.post(`/api/admin/jadwal-observasi/${activeStudent.id}`, form);
            } else {
                await libAxios.post(`/api/admin/jadwal-observasi/bulk`, { ...form, user_ids: selectedIds });
            }
            Swal.fire('Berhasil', 'Jadwal telah dikirim ke dashboard orang tua.', 'success');
            setShowModal(false);
            setSelectedIds([]); 
            fetchStudents();
        } catch { Swal.fire('Gagal', 'Terjadi kesalahan sistem.', 'error'); }
    };

    if (isLoading) return <div className={styles.loading}>Memuat Data Siswa...</div>;

    const isSMP = jenjangFilter === 'SMP';

    return (
        <div className={styles.container}>
            <div className={styles.header}>
                <div className={styles.title}>
                    <h1>Penjadwalan {isSMP ? 'Ujian CBT (SMP)' : 'Observasi (SD)'}</h1>
                    <p>Atur jadwal kedatangan calon peserta didik untuk {isSMP ? 'tes CBT' : 'tes observasi fisik/motorik'}.</p>
                </div>
            </div>

            {/* TAB JENJANG (SDIT / SMPIT) */}
            <div className={styles.tabContainer}>
                <button 
                    className={`${styles.tabBtn} ${jenjangFilter === 'SD' ? styles.tabActive : ''}`} 
                    onClick={() => handleTabChange('SD')}
                >
                    SDIT (Observasi)
                </button>
                <button 
                    className={`${styles.tabBtn} ${jenjangFilter === 'SMP' ? styles.tabActive : ''}`} 
                    onClick={() => handleTabChange('SMP')}
                >
                    SMPIT (Ujian CBT)
                </button>
            </div>

            <div className={styles.card}>
                <div className={styles.toolbar}>
                    <div className={styles.searchWrapper}>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a0aec0" strokeWidth="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input type="text" className={styles.searchInput} placeholder="Cari nama calon siswa..." value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} />
                    </div>
                    
                    {selectedIds.length > 0 && (
                        <div className={styles.actionGroup}>
                            <div className={styles.selectedCount}>{selectedIds.length} Siswa Terpilih</div>
                            <button className={styles.bulkBtn} onClick={openModalBulk}>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                Set Jadwal Massal
                            </button>
                        </div>
                    )}
                </div>

                <div className={styles.tableWrapper}>
                    <table className={styles.dataTable}>
                        <thead>
                            <tr>
                                <th style={{width: '40px', textAlign: 'center'}}>
                                    <input 
                                        type="checkbox" 
                                        className={styles.customCheckbox}
                                        checked={selectedIds.length === filteredStudents.length && filteredStudents.length > 0} 
                                        onChange={handleSelectAll} 
                                    />
                                </th>
                                <th>Calon Peserta Didik</th>
                                <th>Status Berkas</th>
                                <th>Jadwal {isSMP ? 'CBT' : 'Observasi'}</th>
                                <th style={{textAlign: 'right'}}>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filteredStudents.length > 0 ? (
                                // Tambahin "index" di sini bro
                                filteredStudents.map((siswa, index) => (
                                    // Gabungin siswa.id sama index biar key-nya 100% unik
                                    <tr key={`${siswa.id}-${index}`} className={selectedIds.includes(siswa.id) ? styles.rowSelected : ''}>
                                        <td style={{textAlign: 'center'}}>
                                            <input 
                                                type="checkbox" 
                                                className={styles.customCheckbox}
                                                checked={selectedIds.includes(siswa.id)} 
                                                onChange={() => handleSelect(siswa.id)} 
                                            />
                                        </td>
                                        <td>
                                            <div className={styles.studentName}>{siswa.name}</div>
                                            <div className={styles.submitTime}>{siswa.email}</div>
                                        </td>
                                        <td><span className={styles.badgeClean}>Lengkap</span></td>
                                        <td>
                                            {siswa.jadwal_observasi ? (
                                                <div>
                                                    <div style={{fontWeight: 800, color: '#1a5f51'}}>{new Date(siswa.jadwal_observasi).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</div>
                                                    <div style={{fontSize: '12px', color: '#718096', marginTop: '4px'}}>Pukul {siswa.jam_observasi} • {siswa.ruang_observasi}</div>
                                                    {!isSMP && <div style={{fontSize: '11px', color: '#a0aec0', marginTop: '4px', fontStyle: 'italic'}}>Bawa: {siswa.perlengkapan}</div>}
                                                </div>
                                            ) : (
                                                <span className={styles.badgeCheat}>Belum Dijadwalkan</span>
                                            )}
                                        </td>
                                        <td style={{textAlign: 'right'}}>
                                            <button className={styles.exportBtn} onClick={() => openModalSingle(siswa)}>
                                                {siswa.jadwal_observasi ? 'Ubah Jadwal' : 'Set Satuan'}
                                            </button>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={5} style={{textAlign: 'center', padding: '40px', color: '#a0aec0', fontWeight: 600}}>
                                        Tidak ada siswa di jenjang ini.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* MODAL SETTING JADWAL */}
            {showModal && (
                <div className={styles.modalOverlay}>
                    <div className={styles.modalContent} style={{ maxWidth: '500px' }}>
                        <div className={styles.modalHeaderFixed}>
                            <h2 className={styles.modalTitle}>{activeStudent ? 'Set Jadwal Siswa' : 'Set Jadwal Massal'}</h2>
                            <p>
                                {activeStudent 
                                    ? <span>Siswa: <strong>{activeStudent.name}</strong></span> 
                                    : <span style={{color: '#e53e3e', fontWeight: 800}}>Mengubah jadwal untuk {selectedIds.length} siswa terpilih.</span>
                                }
                            </p>
                        </div>
                        <form onSubmit={handleSubmit} className={styles.formPenilaian}>
                            <div className={styles.formGroup} style={{marginBottom: '15px'}}>
                                <label style={{fontSize: '14px', marginBottom: '8px'}}>Tanggal Kedatangan</label>
                                <input type="date" className={styles.formInputLarge} style={{background: 'white', fontSize: '16px', maxWidth: '100%'}} value={form.tanggal} onChange={(e) => setForm({...form, tanggal: e.target.value})} required />
                            </div>
                            <div className={styles.formGroup} style={{marginBottom: '18px'}}>
                                <label style={{fontSize: '14px', marginBottom: '8px'}}>Jam / Sesi</label>
                                
                                {/* Tombol Cepat Pilihan Sesi Standar */}
                                <div className={styles.presetList}>
                                    {TIME_PRESETS.map((p) => {
                                        const isActive = jamMulai === p.start && jamSelesai === p.end;
                                        return (
                                            <button
                                                key={p.label}
                                                type="button"
                                                className={`${styles.presetBtn} ${isActive ? styles.presetBtnActive : ''}`}
                                                onClick={() => applyTimePreset(p.start, p.end)}
                                            >
                                                {p.label}: {p.start} - {p.end}
                                            </button>
                                        );
                                    })}
                                </div>

                                {/* Time Picker: Jam Mulai & Jam Selesai */}
                                <div className={styles.timePickerContainer}>
                                    <input
                                        type="time"
                                        className={styles.timeInput}
                                        value={jamMulai}
                                        onChange={(e) => handleTimeChange(e.target.value, jamSelesai)}
                                        required
                                    />
                                    <span className={styles.timeSeparator}>sampai</span>
                                    <input
                                        type="time"
                                        className={styles.timeInput}
                                        value={jamSelesai}
                                        onChange={(e) => handleTimeChange(jamMulai, e.target.value)}
                                        required
                                    />
                                    <span className={styles.timeZoneBadge}>WIB</span>
                                </div>
                                <div className={styles.timePreview}>
                                    Format tersimpan: <strong>{form.jam || `${jamMulai} - ${jamSelesai} WIB`}</strong>
                                </div>
                            </div>
                            <div className={styles.formGroup} style={{marginBottom: '15px'}}>
                                <label style={{fontSize: '14px', marginBottom: '8px'}}>Ruangan / Lokasi</label>
                                <input type="text" className={styles.formInputLarge} style={{background: 'white', fontSize: '16px', maxWidth: '100%'}} placeholder={isSMP ? "Lab Komputer 1" : "Ruang Kelas 1A"} value={form.ruangan} onChange={(e) => setForm({...form, ruangan: e.target.value})} required />
                            </div>
                            {!isSMP && (
                                <div className={styles.formGroup}>
                                    <label style={{fontSize: '14px', marginBottom: '8px'}}>Perlengkapan Dibawa</label>
                                    <input type="text" className={styles.formInputLarge} style={{background: 'white', fontSize: '16px', maxWidth: '100%'}} placeholder="Cth: Alat tulis, Meja Lipat" value={form.perlengkapan} onChange={(e) => setForm({...form, perlengkapan: e.target.value})} required />
                                </div>
                            )}
                            
                            <div className={styles.modalActions}>
                                <button type="button" className={styles.cancelBtn} onClick={() => setShowModal(false)}>Batal</button>
                                <button type="submit" className={styles.saveBtn}>Simpan Jadwal</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}