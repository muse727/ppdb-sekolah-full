// src/app/guru/cbt/page.tsx
'use client';

import { useState, useEffect } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './cbt.module.css';

export default function PengaturanCBTPage() {
    const [sessions, setSessions] = useState<any[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [showModal, setShowModal] = useState(false);

    const [activeSesi, setActiveSesi] = useState<any>(null);
    const [questions, setQuestions] = useState<any[]>([]);
    const [showSoalModal, setShowSoalModal] = useState(false);

    const [form, setForm] = useState({ id: '', nama_ujian: '', tanggal_ujian: '', jam_mulai: '', jam_selesai: '', ruangan: '', token: '' });
    const [formSoal, setFormSoal] = useState({ id: '', jenis_soal: 'PG', pertanyaan: '', gambar: '', opsi_a: '', opsi_b: '', opsi_c: '', opsi_d: '', kunci_jawaban: 'A' });

    const fetchSessions = async () => {
        try {
            const res = await libAxios.get('/api/guru/cbt-sessions');
            setSessions(res.data);
        } catch (error) { console.error(error); } 
        finally { setIsLoading(false); }
    };

    useEffect(() => { fetchSessions(); }, []);

    const generateToken = () => {
        const char = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let result = '';
        for (let i = 0; i < 6; i++) result += char.charAt(Math.floor(Math.random() * char.length));
        setForm({ ...form, token: result });
    };

    const handleEdit = (sesi: any) => {
        setForm({ id: sesi.id, nama_ujian: sesi.nama_ujian, tanggal_ujian: sesi.tanggal_ujian, jam_mulai: sesi.jam_mulai, jam_selesai: sesi.jam_selesai, ruangan: sesi.ruangan, token: sesi.token });
        setShowModal(true);
    };

    const handleSubmitSesi = async (e: React.FormEvent) => {
        e.preventDefault();
        Swal.fire({ title: 'Menyimpan...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            await libAxios.post('/api/guru/cbt-sessions', form);
            Swal.fire('Berhasil', 'Sesi ujian telah dijadwalkan.', 'success');
            setShowModal(false); fetchSessions();
        } catch (error) { Swal.fire('Gagal', 'Cek kembali inputan Anda.', 'error'); }
    };

    const handleDeleteSesi = async (id: number, nama: string) => {
        Swal.fire({ title: `Hapus ${nama}?`, text: "Semua soal di sesi ini juga akan terhapus.", icon: 'warning', showCancelButton: true, confirmButtonColor: '#e53e3e', confirmButtonText: 'Ya, Hapus!'
        }).then(async (result) => {
            if (result.isConfirmed) {
                try {
                    await libAxios.delete(`/api/guru/cbt-sessions/${id}`);
                    Swal.fire('Terhapus!', 'Sesi ujian dihapus.', 'success');
                    fetchSessions();
                } catch (error) { Swal.fire('Gagal', 'Terjadi kesalahan sistem.', 'error'); }
            }
        });
    };

    const openBankSoal = async (sesi: any) => {
        setActiveSesi(sesi);
        fetchQuestions(sesi.id);
    };

    const fetchQuestions = async (sesiId: number) => {
        try {
            const res = await libAxios.get(`/api/guru/cbt-sessions/${sesiId}/questions`);
            setQuestions(res.data);
        } catch (error) { console.error(error); }
    };

    // FIX: KOMPRES GAMBAR LEBIH AMAN (Pakai prev state)
    const handleUploadGambarSoal = (e: any) => {
        const file = e.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = (event) => {
            const img = new Image();
            img.src = event.target?.result as string;
            img.onload = () => {
                const canvas = document.createElement('canvas');
                let width = img.width;
                let height = img.height;
                const MAX_WIDTH = 800;

                if (width > MAX_WIDTH) {
                    height = Math.round((height * MAX_WIDTH) / width);
                    width = MAX_WIDTH;
                }

                canvas.width = width;
                canvas.height = height;

                const ctx = canvas.getContext('2d');
                ctx?.drawImage(img, 0, 0, width, height);
                
                const compressedBase64 = canvas.toDataURL('image/jpeg', 0.7); 
                setFormSoal(prev => ({ ...prev, gambar: compressedBase64 }));
            };
        };
    };

    const handleSubmitSoal = async (e: React.FormEvent) => {
        e.preventDefault();
        Swal.fire({ title: 'Menyimpan...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            await libAxios.post(`/api/guru/cbt-sessions/${activeSesi.id}/questions`, formSoal);
            Swal.fire('Berhasil', 'Soal disimpan.', 'success');
            setShowSoalModal(false);
            fetchQuestions(activeSesi.id);
        } catch (error) { Swal.fire('Gagal', 'Terjadi kesalahan.', 'error'); }
    };

    const handleDeleteSoal = async (id: number) => {
        Swal.fire({ title: `Hapus Soal?`, icon: 'warning', showCancelButton: true, confirmButtonColor: '#e53e3e', confirmButtonText: 'Ya, Hapus!'
        }).then(async (result) => {
            if (result.isConfirmed) {
                try {
                    await libAxios.delete(`/api/guru/cbt-questions/${id}`);
                    fetchQuestions(activeSesi.id);
                } catch (error) { Swal.fire('Gagal', 'Terjadi kesalahan.', 'error'); }
            }
        });
    };

    if (isLoading) return <div className={styles.loading}>Memuat Konfigurasi CBT...</div>;

    if (activeSesi) {
        return (
            <div className={styles.container}>
                <div className={styles.editorHeader}>
                    <button className={styles.backBtn} onClick={() => setActiveSesi(null)}>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        Kembali
                    </button>
                    <div className={styles.editorTitle}>
                        <h2>Bank Soal: <span>{activeSesi.nama_ujian}</span></h2>
                        <p>Total: {questions.length} Soal Tersimpan</p>
                    </div>
                    <button className={styles.addBtn} onClick={() => {
                        setFormSoal({ id: '', jenis_soal: 'PG', pertanyaan: '', gambar: '', opsi_a: '', opsi_b: '', opsi_c: '', opsi_d: '', kunci_jawaban: 'A' });
                        setShowSoalModal(true);
                    }}>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        Tambah Soal Baru
                    </button>
                </div>

                <div className={styles.soalList}>
                    {questions.length === 0 ? (
                        <div className={styles.emptyState}>Belum ada soal. Klik Tambah Soal Baru untuk memulai.</div>
                    ) : (
                        questions.map((soal, index) => (
                            <div key={soal.id} className={styles.soalCard}>
                                <div className={styles.soalHeader}>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                                        <span className={styles.soalNumber}>No. {index + 1}</span>
                                        <span className={soal.jenis_soal === 'PG' ? styles.badgePG : styles.badgeEsai}>{soal.jenis_soal === 'PG' ? 'Pilihan Ganda' : 'Esai'}</span>
                                    </div>
                                    <div className={styles.soalActions}>
                                        <button className={styles.editBtnSmall} onClick={() => { setFormSoal(soal); setShowSoalModal(true); }}>Edit</button>
                                        <button className={styles.deleteBtnSmall} onClick={() => handleDeleteSoal(soal.id)}>Hapus</button>
                                    </div>
                                </div>
                                {soal.gambar && <img src={soal.gambar} alt="Preview Soal" style={{maxHeight: '150px', borderRadius: '10px', marginBottom: '15px'}} />}
                                <p className={styles.soalText}>{soal.pertanyaan}</p>
                                
                                {soal.jenis_soal === 'PG' && (
                                    <div className={styles.opsiGrid}>
                                        <div className={`${styles.opsiItem} ${soal.kunci_jawaban === 'A' ? styles.correctOpsi : ''}`}><strong>A.</strong> {soal.opsi_a}</div>
                                        <div className={`${styles.opsiItem} ${soal.kunci_jawaban === 'B' ? styles.correctOpsi : ''}`}><strong>B.</strong> {soal.opsi_b}</div>
                                        <div className={`${styles.opsiItem} ${soal.kunci_jawaban === 'C' ? styles.correctOpsi : ''}`}><strong>C.</strong> {soal.opsi_c}</div>
                                        <div className={`${styles.opsiItem} ${soal.kunci_jawaban === 'D' ? styles.correctOpsi : ''}`}><strong>D.</strong> {soal.opsi_d}</div>
                                    </div>
                                )}
                            </div>
                        ))
                    )}
                </div>

                {showSoalModal && (
                    <div className={styles.modalOverlay}>
                        <div className={styles.modalContent} style={{ maxWidth: '700px', maxHeight: '90vh', overflowY: 'auto' }}>
                            <h2 className={styles.modalTitle}>{formSoal.id ? 'Edit Soal' : 'Tulis Soal Baru'}</h2>
                            <form onSubmit={handleSubmitSoal}>
                                <div className={styles.formGroup}>
                                    <label>Jenis Pertanyaan</label>
                                    <select className={styles.formInput} value={formSoal.jenis_soal} onChange={(e) => setFormSoal({...formSoal, jenis_soal: e.target.value})}>
                                        <option value="PG">Pilihan Ganda (Auto-Koreksi)</option>
                                        <option value="ESAI">Esai (Koreksi Manual)</option>
                                    </select>
                                </div>
                                <div className={styles.formGroup}>
                                    <label>Teks Pertanyaan</label>
                                    <textarea className={styles.formTextarea} value={formSoal.pertanyaan} onChange={(e) => setFormSoal({...formSoal, pertanyaan: e.target.value})} placeholder="Tuliskan soal disini..." required />
                                </div>
                                <div className={styles.formGroup}>
                                    <label>Gambar Tambahan (Opsional)</label>
                                    <input type="file" accept="image/*" className={styles.formInput} onChange={handleUploadGambarSoal} />
                                    {formSoal.gambar && <img src={formSoal.gambar} alt="Preview" style={{maxHeight: '100px', marginTop: '10px', borderRadius: '10px'}} />}
                                </div>

                                {formSoal.jenis_soal === 'PG' && (
                                    <>
                                        <div className={styles.grid2}>
                                            <div className={styles.formGroup}><label>Opsi A</label><input type="text" className={styles.formInput} value={formSoal.opsi_a} onChange={(e) => setFormSoal({...formSoal, opsi_a: e.target.value})} required /></div>
                                            <div className={styles.formGroup}><label>Opsi B</label><input type="text" className={styles.formInput} value={formSoal.opsi_b} onChange={(e) => setFormSoal({...formSoal, opsi_b: e.target.value})} required /></div>
                                            <div className={styles.formGroup}><label>Opsi C</label><input type="text" className={styles.formInput} value={formSoal.opsi_c} onChange={(e) => setFormSoal({...formSoal, opsi_c: e.target.value})} required /></div>
                                            <div className={styles.formGroup}><label>Opsi D</label><input type="text" className={styles.formInput} value={formSoal.opsi_d} onChange={(e) => setFormSoal({...formSoal, opsi_d: e.target.value})} required /></div>
                                        </div>
                                        <div className={styles.formGroup}>
                                            <label>Kunci Jawaban Benar</label>
                                            <select className={styles.formInput} style={{fontWeight: '800', color: '#1a5f51'}} value={formSoal.kunci_jawaban} onChange={(e) => setFormSoal({...formSoal, kunci_jawaban: e.target.value})}>
                                                <option value="A">Jawaban A</option><option value="B">Jawaban B</option><option value="C">Jawaban C</option><option value="D">Jawaban D</option>
                                            </select>
                                        </div>
                                    </>
                                )}

                                <div className={styles.modalActions}>
                                    <button type="button" className={styles.cancelBtn} onClick={() => setShowSoalModal(false)}>Batal</button>
                                    <button type="submit" className={styles.saveBtn}>Simpan Soal</button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className={styles.container}>
            <div className={styles.header}>
                <div className={styles.title}>
                    <h1>Manajemen CBT (SMP)</h1>
                    <p>Atur jadwal ujian akademik, ruangan, dan token akses siswa.</p>
                </div>
                <button className={styles.addBtn} onClick={() => { setForm({ id: '', nama_ujian: '', tanggal_ujian: '', jam_mulai: '', jam_selesai: '', ruangan: '', token: '' }); setShowModal(true); }}>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Buat Sesi Ujian
                </button>
            </div>

            <div className={styles.gridSessions}>
                {sessions.map((sesi) => (
                    <div key={sesi.id} className={styles.sessionCard}>
                        <div className={styles.sessionInfo}>
                            <span className={styles.badgeRuang}>
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                                {sesi.ruangan}
                            </span>
                            <h3>{sesi.nama_ujian}</h3>
                            <div className={styles.metaData}>
                                <div className={styles.metaItem}><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> {new Date(sesi.tanggal_ujian).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })}</div>
                                <div className={styles.metaItem}><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> {sesi.jam_mulai} - {sesi.jam_selesai}</div>
                            </div>
                        </div>
                        <div className={styles.tokenBox}>
                            <label>TOKEN UJIAN</label>
                            <div className={styles.tokenCode}>{sesi.token}</div>
                        </div>
                        
                        <button className={styles.manageSoalBtn} onClick={() => openBankSoal(sesi)}>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            Kelola Bank Soal
                        </button>

                        <div className={styles.cardActions}>
                            <button className={styles.editBtn} onClick={() => handleEdit(sesi)}>Edit Jadwal</button>
                            <button className={styles.deleteBtn} onClick={() => handleDeleteSesi(sesi.id, sesi.nama_ujian)}>Hapus</button>
                        </div>
                    </div>
                ))}
            </div>

            {showModal && (
                <div className={styles.modalOverlay}>
                    <div className={styles.modalContent}>
                        <h2 className={styles.modalTitle}>{form.id ? 'Edit Sesi Ujian' : 'Konfigurasi Sesi Ujian'}</h2>
                        <form onSubmit={handleSubmitSesi}>
                            <div className={styles.formGroup}><label>Nama Sesi Ujian</label><input type="text" className={styles.formInput} value={form.nama_ujian} onChange={(e) => setForm({...form, nama_ujian: e.target.value})} required /></div>
                            <div className={styles.grid2}>
                                <div className={styles.formGroup}><label>Tanggal</label><input type="date" className={styles.formInput} value={form.tanggal_ujian} onChange={(e) => setForm({...form, tanggal_ujian: e.target.value})} required /></div>
                                <div className={styles.formGroup}><label>Ruangan / Lab</label><input type="text" className={styles.formInput} value={form.ruangan} onChange={(e) => setForm({...form, ruangan: e.target.value})} required /></div>
                            </div>
                            <div className={styles.grid2}>
                                <div className={styles.formGroup}><label>Jam Mulai</label><input type="time" className={styles.formInput} value={form.jam_mulai} onChange={(e) => setForm({...form, jam_mulai: e.target.value})} required /></div>
                                <div className={styles.formGroup}><label>Jam Selesai</label><input type="time" className={styles.formInput} value={form.jam_selesai} onChange={(e) => setForm({...form, jam_selesai: e.target.value})} required /></div>
                            </div>
                            <div className={styles.formGroup}>
                                <label>Token Akses Siswa</label>
                                <div className={styles.tokenInputWrapper}>
                                    <input type="text" className={styles.formInput} value={form.token} readOnly required />
                                    <button type="button" className={styles.magicBtn} onClick={generateToken}>Generate Token</button>
                                </div>
                            </div>
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