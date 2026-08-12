// src/app/guru/rekapan-cbt/page.tsx
'use client';

import { useState, useEffect } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './hasil.module.css';

export default function RekapanCBTPage() {
    const [results, setResults] = useState<any[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [searchTerm, setSearchTerm] = useState('');
    const [filterSesi, setFilterSesi] = useState('SEMUA');

    // State untuk Modal Koreksi Esai
    const [showKoreksiModal, setShowKoreksiModal] = useState(false);
    const [activeResult, setActiveResult] = useState<any>(null);
    const [esaiQuestions, setEsaiQuestions] = useState<any[]>([]);
    const [skorEsaiInput, setSkorEsaiInput] = useState<number | string>(0);

    const fetchResults = async () => {
        try {
            const res = await libAxios.get('/api/guru/cbt-results');
            setResults(res.data);
        } catch (error) { console.error(error); } 
        finally { setIsLoading(false); }
    };

    useEffect(() => { fetchResults(); }, []);

    const uniqueSessions = Array.from(new Set(results.map(r => r.nama_ujian)));

    const filteredResults = results.filter(r => {
        const matchSearch = r.nama_siswa.toLowerCase().includes(searchTerm.toLowerCase()) || r.nama_ujian.toLowerCase().includes(searchTerm.toLowerCase());
        const matchSesi = filterSesi === 'SEMUA' ? true : r.nama_ujian === filterSesi;
        return matchSearch && matchSesi;
    });

    const handleExportCSV = () => {
        if (filteredResults.length === 0) { Swal.fire('Data Kosong', 'Tidak ada data untuk diekspor.', 'warning'); return; }
        const headers = ['Nama Peserta', 'Waktu Submit', 'Sesi Ujian', 'Skor PG', 'Skor Esai', 'Nilai Akhir', 'Jumlah Pelanggaran'];
        const csvRows = [headers.join(',')];

        filteredResults.forEach(r => {
            const finalScore = r.is_koreksi_esai ? ((parseFloat(r.skor) + parseFloat(r.skor_esai)) / 2).toFixed(1) : parseFloat(r.skor).toFixed(1);
            const row = [ `"${r.nama_siswa}"`, `"${new Date(r.created_at).toLocaleString('id-ID')}"`, `"${r.nama_ujian}"`, parseFloat(r.skor).toFixed(1), r.is_koreksi_esai ? r.skor_esai : '0', finalScore, r.jumlah_pelanggaran ];
            csvRows.push(row.join(','));
        });

        const csvString = csvRows.join('\n');
        const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url;
        a.download = `Rekap_CBT_${filterSesi === 'SEMUA' ? 'Semua_Sesi' : filterSesi.replace(/\s+/g, '_')}.csv`;
        a.click(); window.URL.revokeObjectURL(url);
    };

    // === FITUR BUKA KOREKSI ESAI ===
    const openKoreksiEsai = async (hasil: any) => {
        Swal.fire({ title: 'Menyiapkan Lembar Jawaban...', didOpen: () => Swal.showLoading() });
        try {
            // Ambil semua soal dari sesi ini
            const res = await libAxios.get(`/api/guru/cbt-sessions/${hasil.cbt_session_id}/questions`);
            const soalEsai = res.data.filter((q: any) => q.jenis_soal === 'ESAI');
            
            setEsaiQuestions(soalEsai);
            setActiveResult(hasil);
            setSkorEsaiInput(hasil.skor_esai || 0);
            
            if(soalEsai.length === 0) {
                Swal.fire('Info', 'Tidak ada soal esai pada ujian ini.', 'info');
            } else {
                setShowKoreksiModal(true);
                Swal.close();
            }
        } catch (error) { Swal.fire('Gagal', 'Terjadi kesalahan sistem', 'error'); }
    };

    // === FITUR SIMPAN NILAI ESAI ===
    const submitKoreksi = async (e: React.FormEvent) => {
        e.preventDefault();
        Swal.fire({ title: 'Menyimpan Nilai...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            await libAxios.post(`/api/guru/cbt-results/${activeResult.id}/esai`, { skor_esai: skorEsaiInput });
            Swal.fire('Berhasil', 'Nilai esai tersimpan dan nilai akhir telah diupdate.', 'success');
            setShowKoreksiModal(false);
            fetchResults();
        } catch (error) { Swal.fire('Gagal', 'Cek koneksi Anda', 'error'); }
    };

    if (isLoading) return <div className={styles.loading}>Memuat Hasil Ujian...</div>;

    return (
        <div className={styles.container}>
            <div className={styles.header}>
                <div className={styles.title}>
                    <h1>Rekapan Hasil CBT (SMP)</h1>
                    <p>Daftar nilai ujian akademik peserta didik dan deteksi kecurangan.</p>
                </div>
            </div>

            <div className={styles.card}>
                <div className={styles.toolbar}>
                    <div className={styles.searchWrapper}>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a0aec0" strokeWidth="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input type="text" className={styles.searchInput} placeholder="Cari nama siswa..." value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} />
                    </div>
                    
                    <div className={styles.actionGroup}>
                        <div className={styles.filterWrapper}>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                            <select className={styles.filterSelect} value={filterSesi} onChange={(e) => setFilterSesi(e.target.value)}>
                                <option value="SEMUA">Semua Sesi Ujian</option>
                                {uniqueSessions.map((sesi, idx) => (
                                    <option key={idx} value={sesi as string}>{sesi as string}</option>
                                ))}
                            </select>
                        </div>
                        <button className={styles.exportBtn} onClick={handleExportCSV}>Export CSV</button>
                    </div>
                </div>

                {filteredResults.length === 0 ? (
                    <div className={styles.emptyState}>Belum ada data yang sesuai.</div>
                ) : (
                    <div className={styles.tableWrapper}>
                        <table className={styles.dataTable}>
                            <thead>
                                <tr>
                                    <th>Peserta & Waktu Submit</th>
                                    <th>Sesi Ujian</th>
                                    <th style={{textAlign: 'center'}}>Skor PG</th>
                                    <th style={{textAlign: 'center'}}>Nilai Akhir (Total)</th>
                                    <th style={{textAlign: 'center'}}>Aksi Lanjutan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredResults.map((hasil) => {
                                    const skorPG = parseFloat(hasil.skor);
                                    const skorEsai = parseFloat(hasil.skor_esai || 0);
                                    // Hitung Rata-rata PG & Esai (Atau bisa disesuaikan kalau bobotnya beda)
                                    const nilaiAkhir = hasil.is_koreksi_esai ? ((skorPG + skorEsai) / 2).toFixed(1) : skorPG.toFixed(1);

                                    return (
                                        <tr key={hasil.id}>
                                            <td>
                                                <div className={styles.studentName}>{hasil.nama_siswa}</div>
                                                <div className={styles.submitTime}>{new Date(hasil.created_at).toLocaleString('id-ID')}</div>
                                                {hasil.jumlah_pelanggaran > 0 && <span className={styles.badgeCheatTable}>⚠️ {hasil.jumlah_pelanggaran} Pelanggaran</span>}
                                            </td>
                                            <td>
                                                <div className={styles.sessionName}>{hasil.nama_ujian}</div>
                                                <div className={styles.sessionToken}>Token: {hasil.token}</div>
                                            </td>
                                            <td style={{textAlign: 'center'}}>
                                                <span style={{fontWeight: '800', color: '#4a5568'}}>{skorPG.toFixed(1)}</span>
                                            </td>
                                            <td style={{textAlign: 'center'}}>
                                                <div className={styles.scoreBadge}>{nilaiAkhir}</div>
                                                {hasil.is_koreksi_esai && <div className={styles.koreksiStatus}>Telah Dikoreksi</div>}
                                            </td>
                                            <td style={{textAlign: 'center'}}>
                                                <button className={styles.koreksiBtn} onClick={() => openKoreksiEsai(hasil)}>
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                                    Koreksi Esai
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* MODAL KOREKSI ESAI */}
            {showKoreksiModal && activeResult && (
                <div className={styles.modalOverlay}>
                    <div className={styles.modalContent} style={{ maxWidth: '800px', maxHeight: '90vh', overflowY: 'auto' }}>
                        <div className={styles.modalHeaderFixed}>
                            <h2 className={styles.modalTitle}>Koreksi Jawaban Esai</h2>
                            <p>Siswa: <strong>{activeResult.nama_siswa}</strong></p>
                        </div>
                        
                        <div className={styles.esaiListContainer}>
                            {esaiQuestions.map((q, idx) => (
                                <div key={q.id} className={styles.esaiBox}>
                                    <div className={styles.esaiQuestion}>
                                        <span className={styles.qNum}>Soal {idx + 1}</span>
                                        <p>{q.pertanyaan}</p>
                                    </div>
                                    <div className={styles.esaiAnswer}>
                                        <strong>Jawaban Siswa:</strong>
                                        <div className={styles.answerText}>
                                            {activeResult.jawaban_json[q.id] ? activeResult.jawaban_json[q.id] : <em style={{color: '#a0aec0'}}>Tidak ada jawaban yang diisi.</em>}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>

                        <form onSubmit={submitKoreksi} className={styles.formPenilaian}>
                            <div className={styles.formGroup}>
                                <label>Berikan Skor Esai (0 - 100)</label>
                                <p style={{fontSize:'12px', color:'#718096', margin:'0 0 10px 0'}}>Skor ini akan digabungkan dan dirata-rata dengan Skor Pilihan Ganda.</p>
                                <input type="number" min="0" max="100" step="0.1" className={styles.formInputLarge} value={skorEsaiInput} onChange={(e) => setSkorEsaiInput(e.target.value)} required />
                            </div>
                            <div className={styles.modalActions}>
                                <button type="button" className={styles.cancelBtn} onClick={() => setShowKoreksiModal(false)}>Batal</button>
                                <button type="submit" className={styles.saveBtn}>Simpan & Hitung Nilai Akhir</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}