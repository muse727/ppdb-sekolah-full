'use client';

import { useState, useEffect } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import type { HasilObservasiRecord, JawabanObservasi, RekomendasiAkhir } from '@/lib/domain';
import { beginObservasiIdempotentOp, clearObservasiIdempotentOp } from '@/lib/observasiIdempotency';
import { handleObservasiSubmitError } from '@/lib/observasiSubmitErrors';
import styles from './rekapan.module.css';

/**
 * Persisted to localStorage under `goSkool_printSettings` and read back by
 * /guru/rekapan/cetak, so every field must round-trip as a string.
 */
interface PrintSettings {
    tanggal: string;
    namaKepsek: string;
    nipKepsek: string;
    logoB64: string;
    ttdB64: string;
}

export default function RekapanObservasiPage() {
    const [hasilList, setHasilList] = useState<HasilObservasiRecord[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    
    const [activeTab, setActiveTab] = useState('REKAPAN');
    const [searchTerm, setSearchTerm] = useState('');
    const [filterStatus, setFilterStatus] = useState('SEMUA'); 

    const [printSettings, setPrintSettings] = useState<PrintSettings>({
        tanggal: 'Jakarta, 15 November 2025',
        namaKepsek: 'Amang Suryaman, M.Pd.',
        nipKepsek: '',
        logoB64: '',
        ttdB64: ''
    });

    /** Report currently open in the editor, or null while listing. */
    const [editingData, setEditingData] = useState<HasilObservasiRecord | null>(null);
    /** Working copy of `editingData.jawaban_json`, keyed by component code. */
    const [editedJson, setEditedJson] = useState<Record<string, JawabanObservasi>>({});
    const [rekomendasiAkhir, setRekomendasiAkhir] = useState<RekomendasiAkhir>('LULUS');

    const fetchHasil = async () => {
        try {
            const res = await libAxios.get<HasilObservasiRecord[]>('/api/guru/rekapan-observasi');
            setHasilList(res.data);
        } catch (error) {
            console.error(error);
        } finally {
            setIsLoading(false);
        }
    };

    useEffect(() => { 
        fetchHasil(); 
        const savedSettings = localStorage.getItem('goSkool_printSettings');
        if(savedSettings) setPrintSettings(JSON.parse(savedSettings));
    }, []);

    const handleImageUpload = (e: React.ChangeEvent<HTMLInputElement>, field: 'logoB64' | 'ttdB64') => {
        const file = e.target.files?.[0];
        if (file) {
            const reader = new FileReader();
            reader.onloadend = () => {
                setPrintSettings(prev => ({ ...prev, [field]: reader.result as string }));
            };
            reader.readAsDataURL(file);
        }
    };

    const handleSaveSettings = (e: React.FormEvent) => {
        e.preventDefault();
        localStorage.setItem('goSkool_printSettings', JSON.stringify(printSettings));
        Swal.fire('Tersimpan', 'Pengaturan dokumen siap digunakan untuk cetak PDF.', 'success');
    };

    const openEditor = (hasil: HasilObservasiRecord) => {
        setEditingData(hasil);
        // Deep clone so edits stay local until saved. Falls back to {} when the
        // decoded json column is null — otherwise Object.keys() below throws.
        setEditedJson(JSON.parse(JSON.stringify(hasil.jawaban_json ?? {})));
        setRekomendasiAkhir(hasil.rekomendasi_akhir || 'LULUS');
    };

    const generateAutoSaran = (kodeSoal: string) => {
        const data = editedJson[kodeSoal];
        const kat = data.kategori ? data.kategori.toLowerCase() : '';
        const ket = data.keterampilan ? data.keterampilan.toLowerCase() : '';
        
        let kegiatan = '';
        if (ket.includes('berlari')) {
            kegiatan = 'bermain kejar-kejaran, estafet memindahkan barang, atau berlari bebas di luar ruangan';
        } else if (ket.includes('melompat')) {
            kegiatan = 'bermain engklek (sunda manda), melompat dari pijakan rendah, atau bermain lompat tali';
        } else if (ket.includes('keseimbangan') || ket.includes('berdiri') || ket.includes('satu kaki')) {
            kegiatan = 'berjalan meniti di atas garis lakban lurus, atau berlatih keseimbangan dengan permainan berdiri satu kaki menirukan pesawat';
        } else if (ket.includes('genggaman') || ket.includes('pensil')) {
            kegiatan = 'bermain plastisin (meremas/memotong), memeras spons air, atau meronce manik-manik untuk menguatkan otot jari';
        } else if (ket.includes('menggambar') || ket.includes('mewarnai')) {
            kegiatan = 'mencoret-coret bebas di kertas besar atau bermain finger painting';
        } else if (kat.includes('kemandirian') && (ket.includes('makan') || ket.includes('minum') || ket.includes('kebersihan'))) {
            kegiatan = 'membuka dan menutup kotak bekal sendiri, serta membiasakan merapikan alat makan tanpa disuapi';
        } else if (kat.includes('kemandirian') && (ket.includes('pakaian') || ket.includes('kaus kaki') || ket.includes('sepatu'))) {
            kegiatan = 'latihan memakai dan melepas kaus kaki/sepatu sendiri, serta berlatih mengancingkan baju pada boneka';
        } else if (kat.includes('literasi')) {
            kegiatan = "bermain mencari benda yang diawali bunyi huruf tertentu dan rutin membacakan buku cerita bergambar";
        } else if (kat.includes('numerasi')) {
            kegiatan = 'menghitung benda di sekitar rumah dan mengelompokkan mainan berdasarkan warna, bentuk, atau ukuran';
        } else {
            kegiatan = `melatih keterampilan ${ket || 'tersebut'} secara rutin di rumah`;
        }

        let saranText = '';
        if (data.jawaban === 'YA') {
            saranText = `Ananda telah menguasai keterampilan ini dengan sangat baik. Terus berikan apresiasi dan motivasi agar kemampuannya semakin berkembang, misalnya dengan rutin mengajak ananda ${kegiatan}.`;
        } else if (data.jawaban === 'TIDAK KONSISTEN') {
            saranText = `Ananda mulai menunjukkan perkembangan namun belum konsisten. Mohon bantuan Ayah/Bunda untuk terus melatih ananda di rumah melalui kegiatan menyenangkan seperti ${kegiatan}.`;
        } else {
            saranText = `Ananda masih membutuhkan bimbingan intensif pada aspek ini. Sangat disarankan bagi Ayah/Bunda untuk mendampingi ananda berlatih perlahan di rumah, contohnya dengan ${kegiatan}.`;
        }

        setEditedJson({ ...editedJson, [kodeSoal]: { ...data, saran: saranText } });
    };

    const saveEditorChanges = async () => {
        // The save button only renders inside the `if (editingData)` editor
        // view, so this is unreachable in practice; the guard keeps the null
        // case from producing a request with an undefined user_id.
        if (!editingData) return;
        Swal.fire({ title: 'Menyimpan...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            // E.12C: SIAP_CETAK only flips on AFTER the server save succeeds — it
            // stays inside the try, after the awaited POST. On failure the catch
            // runs and localStorage is never touched, so a failed save can never
            // mark a report "ready to print".
            //
            // E.12G Phase 4: idempotent EDIT — expected_version from the loaded
            // record (server truth; NOT auto-bumped on 409 — the guru must
            // reload and re-edit from newest data), plus an Idempotency-Key
            // persisted in sessionStorage before the request so a refresh
            // mid-save replays with the same key instead of double-writing.
            const payload = {
                user_id: editingData.user_id,
                jawaban_json: editedJson,
                rekomendasi_akhir: rekomendasiAkhir,
                expected_version: editingData.version,
            };
            const idemKey = beginObservasiIdempotentOp('observasi_submit', editingData.user_id, payload);
            await libAxios.post('/api/guru/observasi/submit', payload, {
                headers: { 'Idempotency-Key': idemKey },
            });
            clearObservasiIdempotentOp('observasi_submit', editingData.user_id);
            localStorage.setItem(`status_cetak_${editingData.id}`, 'SIAP_CETAK');
            Swal.fire('Berhasil!', 'Laporan telah dirapikan dan siap dicetak.', 'success');
            setEditingData(null); 
            fetchHasil(); 
        } catch (err: unknown) {
            handleObservasiSubmitError(err, {
                onDefinitive: () => {
                    if (editingData) clearObservasiIdempotentOp('observasi_submit', editingData.user_id);
                },
            });
        } 
    };

    const handleCetakPDF = (hasil: HasilObservasiRecord) => {
        localStorage.setItem('goSkool_printData', JSON.stringify(hasil));
        window.open('/guru/rekapan/cetak', '_blank');
    };

    const getScoreData = (jawabanJson: Record<string, JawabanObservasi> | null | undefined) => {
        if (!jawabanJson) return { total: 0, max: 0, warning: false };
        let total = 0;
        let max = 0;
        Object.keys(jawabanJson).forEach(key => {
            max += 2;
            const jawaban = jawabanJson[key]?.jawaban;
            if (jawaban === 'YA') total += 2;
            else if (jawaban === 'TIDAK KONSISTEN') total += 1;
        });
        const percentage = max > 0 ? (total / max) * 100 : 0;
        return { total, max, warning: percentage < 60 };
    };

    const filteredHasil = hasilList.filter(hasil => {
        const isSiapCetak = localStorage.getItem(`status_cetak_${hasil.id}`) === 'SIAP_CETAK';
        if (filterStatus === 'DRAFT' && isSiapCetak) return false;
        if (filterStatus === 'SIAP_CETAK' && !isSiapCetak) return false;
        if (searchTerm && !hasil.nama_siswa.toLowerCase().includes(searchTerm.toLowerCase())) return false;
        return true;
    });

    if (isLoading) return <div style={{ padding: '50px', textAlign: 'center', fontFamily: 'Poppins', color: '#1a5f51', fontWeight: 'bold' }}>Memuat Data...</div>;

    if (editingData) {
        return (
            <div className={styles.container}>
                <div className={styles.editorHeader}>
                    <button className={styles.backBtn} onClick={() => setEditingData(null)}>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Batal
                    </button>
                    <div className={styles.editorTitle}><h2>Editor Laporan: <span>{editingData.nama_siswa}</span></h2></div>
                    <button className={styles.saveBtn} onClick={saveEditorChanges}>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Simpan & Siap Cetak
                    </button>
                </div>

                <div className={styles.documentCanvas}>
                    {Object.keys(editedJson).map((kodeSoal) => {
                        const data = editedJson[kodeSoal];
                        return (
                            <div key={kodeSoal} className={styles.editSection}>
                                <div className={styles.editSectionHeader}>
                                    <span className={styles.badgeKode}>{kodeSoal}</span>
                                    <span className={`${styles.badgeNilai} ${data.jawaban === 'YA' ? styles.bgBaik : data.jawaban === 'TIDAK' ? styles.bgPerlu : styles.bgCukup}`}>{data.jawaban === 'YA' ? 'BAIK' : data.jawaban === 'TIDAK' ? 'PERLU BIMBINGAN' : 'CUKUP'}</span>
                                </div>
                                <div className={styles.editorGrid}>
                                    <div className={styles.formGroup}><label>Catatan Guru</label><textarea className={styles.editorTextarea} value={data.catatan || ''} onChange={(e) => setEditedJson({...editedJson, [kodeSoal]: {...data, catatan: e.target.value}})} /></div>
                                    <div className={styles.formGroup}>
                                        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '8px' }}>
                                            <label style={{ margin: 0 }}>Saran Kegiatan (Untuk Laporan PDF)</label>
                                            <button className={styles.magicBtn} onClick={() => generateAutoSaran(kodeSoal)}>
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M21 16.05A3.75 3.75 0 1 0 16.05 21L4.81 9.77a3.75 3.75 0 1 0 4.95-4.95z"></path><path d="M14 6l4-4"></path><path d="M18 10l4-4"></path><path d="M6 14l-4 4"></path><path d="M10 18l-4 4"></path></svg> Auto Saran
                                            </button>
                                        </div>
                                        <textarea className={`${styles.editorTextarea} ${styles.textareaSaran}`} value={data.saran || ''} onChange={(e) => setEditedJson({...editedJson, [kodeSoal]: {...data, saran: e.target.value}})} />
                                    </div>
                                </div>
                            </div>
                        );
                    })}

                    <div className={styles.formGroup} style={{ marginTop: '30px', padding: '20px', background: '#f8fafc', borderRadius: '12px', border: '1px solid #edf2f7' }}>
                        <label style={{ fontSize: '15px', fontWeight: 800, color: '#1a5f51', display: 'block', marginBottom: '10px' }}>Keputusan Rekomendasi Akhir Sekolah</label>
                        <select 
                            className={styles.editorTextarea} 
                            style={{ minHeight: 'auto', padding: '15px', fontWeight: 700, border: '2px solid #cbd5e1' }}
                            value={rekomendasiAkhir} 
                            onChange={(e) => setRekomendasiAkhir(e.target.value as RekomendasiAkhir)}
                        >
                            <option value="LULUS">Lulus (Direkomendasikan Penuh)</option>
                            <option value="LULUS BERSYARAT">Lulus Bersyarat (Diterima dengan Syarat/Kesepakatan)</option>
                            <option value="TIDAK LULUS">Tidak Lulus (Belum Direkomendasikan)</option>
                        </select>
                        {rekomendasiAkhir === 'LULUS BERSYARAT' && (
                            <p style={{ fontSize: '13px', color: '#e53e3e', marginTop: '12px', fontWeight: 600 }}>Peringatan: Calon siswa ini akan memerlukan surat kesepakatan/pernyataan khusus dari pihak sekolah dan orang tua mengenai syarat kelulusannya.</p>
                        )}
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className={styles.container}>
            <div className={styles.header}>
                <div className={styles.title}>
                    <h1>Rekapan Hasil Observasi</h1>
                    <p>Daftar nilai calon peserta didik dan pengaturan cetak.</p>
                </div>
            </div>

            <div className={styles.tabContainer}>
                <button className={`${styles.tabBtn} ${activeTab === 'REKAPAN' ? styles.activeTab : ''}`} onClick={() => setActiveTab('REKAPAN')}>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> Data Rekapan Siswa
                </button>
                <button className={`${styles.tabBtn} ${activeTab === 'PENGATURAN' ? styles.activeTab : ''}`} onClick={() => setActiveTab('PENGATURAN')}>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg> Pengaturan Dokumen
                </button>
            </div>

            {activeTab === 'REKAPAN' && (
                <div className={styles.card}>
                    <div className={styles.toolbar}>
                        <div className={styles.searchWrapper}>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a0aec0" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className={styles.searchIcon}><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input type="text" className={styles.searchInput} placeholder="Cari nama siswa..." value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} />
                        </div>
                        <div className={styles.filterGroup}>
                            <button className={`${styles.filterBtn} ${filterStatus === 'SEMUA' ? styles.filterActive : ''}`} onClick={() => setFilterStatus('SEMUA')}>Semua</button>
                            <button className={`${styles.filterBtn} ${filterStatus === 'DRAFT' ? styles.filterActive : ''}`} onClick={() => setFilterStatus('DRAFT')}>Belum Direview</button>
                            <button className={`${styles.filterBtn} ${filterStatus === 'SIAP_CETAK' ? styles.filterActive : ''}`} onClick={() => setFilterStatus('SIAP_CETAK')}>Siap Cetak</button>
                        </div>
                    </div>

                    {filteredHasil.length === 0 ? (
                        <div className={styles.emptyState}>Tidak ada data yang sesuai dengan pencarian/filter.</div>
                    ) : (
                        <div className={styles.tableWrapper}>
                            <table className={styles.dataTable}>
                                <thead><tr><th>Siswa</th><th>Total Skor</th><th>Status Dokumen</th><th style={{ textAlign: 'right' }}>Aksi Laporan</th></tr></thead>
                                <tbody>
                                    {filteredHasil.map((hasil) => {
                                        const isSiapCetak = localStorage.getItem(`status_cetak_${hasil.id}`) === 'SIAP_CETAK';
                                        const scoreData = getScoreData(hasil.jawaban_json);
                                        return (
                                            <tr key={hasil.id}>
                                                <td>
                                                    <div style={{ fontWeight: '800', color: '#1a5f51', fontSize: '15px' }}>{hasil.nama_siswa}</div>
                                                    <div style={{ fontSize: '12px', color: '#718096', marginTop: '4px' }}>No. Reg: 00{hasil.user_id} • Tes: {new Date(hasil.updated_at).toLocaleDateString('id-ID')}</div>
                                                </td>
                                                <td>
                                                    <div style={{ fontWeight: '800', color: scoreData.warning ? '#e53e3e' : '#1a5f51', fontSize: '15px' }}>
                                                        {scoreData.total} / {scoreData.max}
                                                    </div>
                                                    {scoreData.warning && (
                                                        <div style={{ fontSize: '11px', color: '#e53e3e', fontWeight: 600, marginTop: '4px' }}>Butuh Perhatian Khusus</div>
                                                    )}
                                                </td>
                                                <td>
                                                    {isSiapCetak ? (
                                                        <span className={styles.badgeSiapCetak}>
                                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Siap Cetak
                                                        </span>
                                                    ) : (
                                                        <span className={styles.badgeDraft}>
                                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg> Perlu Review
                                                        </span>
                                                    )}
                                                </td>
                                                <td style={{ textAlign: 'right' }}>
                                                    <div className={styles.actionGroup}>
                                                        <button className={styles.editBtnIcon} onClick={() => openEditor(hasil)} title="Edit Laporan">
                                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                                        </button>
                                                        <button className={styles.printBtnIcon} onClick={() => handleCetakPDF(hasil)} disabled={!isSiapCetak} title="Cetak PDF">
                                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg> Cetak
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}

            {activeTab === 'PENGATURAN' && (
                <div className={styles.card} style={{padding: '35px'}}>
                    <h2 style={{marginTop: 0, color: '#1a5f51', fontSize: '20px', marginBottom: '25px'}}>Atur Kop, Tanda Tangan & Tanggal Dokumen</h2>
                    <form onSubmit={handleSaveSettings} className={styles.settingsGrid}>
                        <div className={styles.settingsLeft}>
                            <div className={styles.formGroup}><label>Tempat & Tanggal</label><input type="text" className={styles.formInput} value={printSettings.tanggal} onChange={(e) => setPrintSettings({...printSettings, tanggal: e.target.value})} required /></div>
                            <div className={styles.formGroup}><label>Nama Kepala Sekolah</label><input type="text" className={styles.formInput} value={printSettings.namaKepsek} onChange={(e) => setPrintSettings({...printSettings, namaKepsek: e.target.value})} required /></div>
                            <div className={styles.formGroup}><label>NIP Kepsek (Opsional)</label><input type="text" className={styles.formInput} value={printSettings.nipKepsek} onChange={(e) => setPrintSettings({...printSettings, nipKepsek: e.target.value})} /></div>
                            <button type="submit" className={styles.saveSettingsBtn}>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Simpan Pengaturan Cetak
                            </button>
                        </div>
                        <div className={styles.settingsRight}>
                            <div className={styles.formGroup}>
                                <label>Logo Sekolah</label>
                                <div className={styles.uploadBox}>
                                    {/* P4-A.3 Group B — `logoB64` is an inline base64 data URI
                                        (FileReader.readAsDataURL in handleImageUpload), later persisted
                                        to localStorage for the print document. Next.js forces
                                        `unoptimized` for data:/blob: sources, so <Image> renders the
                                        identical payload with no compression benefit.
                                        .previewImg { max-width: 100%; max-height: 120px;
                                        object-fit: contain } unchanged. */}
                                    {/* eslint-disable-next-line @next/next/no-img-element */}
                                    {printSettings.logoB64 ? <img src={printSettings.logoB64} alt="Logo" className={styles.previewImg} /> : <div className={styles.placeholderImg}>Pilih Gambar</div>}
                                    <input type="file" accept="image/*" onChange={(e) => handleImageUpload(e, 'logoB64')} />
                                </div>
                            </div>
                            <div className={styles.formGroup}>
                                <label>Tanda Tangan Kepsek</label>
                                <div className={styles.uploadBox}>
                                    {/* P4-A.3 Group B — inline base64 data URI (see the logo preview
                                        above); <Image> would pass it through unoptimized.
                                        .previewImg sizing unchanged. */}
                                    {/* eslint-disable-next-line @next/next/no-img-element */}
                                    {printSettings.ttdB64 ? <img src={printSettings.ttdB64} alt="TTD" className={styles.previewImg} /> : <div className={styles.placeholderImg}>Pilih Gambar</div>}
                                    <input type="file" accept="image/*" onChange={(e) => handleImageUpload(e, 'ttdB64')} />
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}