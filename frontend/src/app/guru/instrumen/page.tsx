// src/app/guru/instrumen/page.tsx
'use client';

import { useState, useEffect } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import type { KategoriObservasi, KomponenObservasi } from '@/lib/domain';
import styles from './instrumen.module.css';

/**
 * Modal form state. Ids are strings because the same shape represents both a
 * blank "new" record (`''`) and an existing one; the backend accepts either.
 */
interface FormKategori {
    id: string;
    nama_kategori: string;
    jenjang: 'SD' | 'SMP';
    urutan: string;
}

interface FormKomponen {
    id: string;
    kategori_id: string;
    kode: string;
    keterampilan: string;
    deskripsi: string;
    petunjuk: string;
    lampiran: string;
    saran_jika_kurang: string;
}

export default function MasterInstrumenPage() {
    const [kategoriList, setKategoriList] = useState<KategoriObservasi[]>([]);
    const [isLoading, setIsLoading] = useState(true);

    const [showKategoriModal, setShowKategoriModal] = useState(false);
    const [formKategori, setFormKategori] = useState<FormKategori>({ id: '', nama_kategori: '', jenjang: 'SD', urutan: '1' });

    const [showKomponenModal, setShowKomponenModal] = useState(false);
    const [formKomponen, setFormKomponen] = useState<FormKomponen>({ id: '', kategori_id: '', kode: '', keterampilan: '', deskripsi: '', petunjuk: '', lampiran: '', saran_jika_kurang: '' });

    const fetchInstrumen = async () => {
        try {
            const res = await libAxios.get<KategoriObservasi[]>('/api/guru/instrumen/kategori?jenjang=SD');
            setKategoriList(res.data);
        } catch (error) {
            console.error(error);
        } finally {
            setIsLoading(false);
        }
    };

    useEffect(() => { fetchInstrumen(); }, []);

    const handleSubmitKategori = async (e: React.FormEvent) => {
        e.preventDefault();
        Swal.fire({ title: 'Menyimpan...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            await libAxios.post('/api/guru/instrumen/kategori', formKategori);
            Swal.fire('Berhasil!', 'Kategori tersimpan.', 'success');
            setShowKategoriModal(false);
            setFormKategori({ id: '', nama_kategori: '', jenjang: 'SD', urutan: '1' });
            fetchInstrumen();
        } catch {
            Swal.fire('Gagal', 'Terjadi kesalahan sistem.', 'error');
        }
    };

    const hapusKategori = async (id: string | number, nama: string) => {
        Swal.fire({
            title: `Hapus Kategori ${nama}?`,
            text: "Semua instrumen di dalamnya akan terhapus!",
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#e53e3e', confirmButtonText: 'Ya, Hapus!'
        }).then(async (result) => {
            if (result.isConfirmed) {
                await libAxios.delete(`/api/guru/instrumen/kategori/${id}`);
                Swal.fire('Terhapus!', 'Kategori dihapus.', 'success');
                fetchInstrumen();
            }
        });
    };

    const handleSubmitKomponen = async (e: React.FormEvent) => {
        e.preventDefault();
        Swal.fire({ title: 'Menyimpan...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            await libAxios.post('/api/guru/instrumen/komponen', formKomponen);
            Swal.fire('Berhasil!', 'Instrumen soal tersimpan.', 'success');
            setShowKomponenModal(false);
            setFormKomponen({ id: '', kategori_id: '', kode: '', keterampilan: '', deskripsi: '', petunjuk: '', lampiran: '', saran_jika_kurang: '' });
            fetchInstrumen();
        } catch {
            Swal.fire('Gagal', 'Terjadi kesalahan sistem.', 'error');
        }
    };

    const hapusKomponen = async (id: string | number, kode: string) => {
        Swal.fire({
            title: `Hapus Instrumen ${kode}?`,
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#e53e3e', confirmButtonText: 'Ya, Hapus!'
        }).then(async (result) => {
            if (result.isConfirmed) {
                await libAxios.delete(`/api/guru/instrumen/komponen/${id}`);
                Swal.fire('Terhapus!', 'Instrumen dihapus.', 'success');
                fetchInstrumen();
            }
        });
    };

    const openTambahSoal = (kategoriId: string | number) => {
        setFormKomponen({ id: '', kategori_id: String(kategoriId), kode: '', keterampilan: '', deskripsi: '', petunjuk: '', lampiran: '', saran_jika_kurang: '' });
        setShowKomponenModal(true);
    };

    const openEditSoal = (soal: KomponenObservasi, kategoriId: string | number) => {
        setFormKomponen({ 
            id: String(soal.id), kategori_id: String(kategoriId), kode: soal.kode, keterampilan: soal.keterampilan || '', deskripsi: soal.deskripsi, 
            petunjuk: soal.petunjuk || '', lampiran: soal.lampiran || '', saran_jika_kurang: soal.saran_jika_kurang || '' 
        });
        setShowKomponenModal(true);
    };

    const generateAutoSaran = () => {
        if (!formKomponen.keterampilan) {
            Swal.fire('Isi Keterampilan', 'Isi Nama Keterampilan terlebih dahulu.', 'info'); return;
        }
        const target = formKomponen.keterampilan.toLowerCase();
        setFormKomponen({ ...formKomponen, saran_jika_kurang: `Terus latih dan bimbing ananda di rumah untuk ${target}.` });
    };

    if (isLoading) return <div style={{ padding: '50px', textAlign: 'center', fontFamily: 'Poppins', color: '#1a5f51', fontWeight: 'bold' }}>Memuat Master Instrumen...</div>;

    return (
        <div className={styles.container}>
            <div className={styles.header}>
                <div className={styles.title}>
                    <h1>Master Instrumen (SD)</h1>
                    <p>Atur kategori, rubrik penilaian, petunjuk pelaksanaan, dan referensi alat peraga.</p>
                </div>
                <button className={styles.addBtn} onClick={() => { setFormKategori({ id: '', nama_kategori: '', jenjang: 'SD', urutan: '1' }); setShowKategoriModal(true); }}>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    Kategori Baru
                </button>
            </div>

            {kategoriList.length === 0 ? (
                <div className={styles.emptyState}>Belum ada data instrumen. Silakan buat kategori pertama.</div>
            ) : (
                kategoriList.map((kat) => (
                    <div key={kat.id} className={styles.kategoriCard}>
                        <div className={styles.kategoriHeader}>
                            <div className={styles.kategoriInfo}>
                                <h3>{kat.nama_kategori} <span className={styles.badgeJenjang}>{kat.jenjang}</span></h3>
                                <p>Urutan Tampil: Ke-{kat.urutan}</p>
                            </div>
                            <div style={{display: 'flex', gap: '10px'}}>
                                <button className={styles.addSoalBtn} onClick={() => { setFormKategori({ id: String(kat.id), nama_kategori: kat.nama_kategori, jenjang: kat.jenjang, urutan: String(kat.urutan) }); setShowKategoriModal(true); }}>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                                    Edit
                                </button>
                                <button className={styles.addSoalBtn} style={{color: '#e53e3e', borderColor: '#feb2b2'}} onClick={() => hapusKategori(kat.id, kat.nama_kategori)}>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                    Hapus
                                </button>
                                <button className={styles.addSoalBtn} style={{background: '#1a5f51', color: 'white', borderColor: '#1a5f51'}} onClick={() => openTambahSoal(kat.id)}>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                    Tambah Instrumen
                                </button>
                            </div>
                        </div>
                        
                        <div className={styles.soalList}>
                            {kat.komponen && kat.komponen.length > 0 ? (
                                <div className={styles.tableWrapper}>
                                    <table className={styles.soalTable}>
                                        <thead>
                                            <tr>
                                                <th style={{ width: '15%' }}>Keterampilan</th>
                                                <th style={{ width: '35%' }}>Deskripsi & Petunjuk</th>
                                                <th style={{ width: '15%' }}>Alat Peraga</th>
                                                <th style={{ width: '25%' }}>Auto-Saran</th>
                                                <th style={{ width: '10%', textAlign: 'center' }}>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {kat.komponen.map((soal) => (
                                                <tr key={soal.id}>
                                                    <td>
                                                        <span className={styles.soalKode}>{soal.kode}</span>
                                                        <div style={{marginTop: '8px', fontWeight: '800', color: '#1a5f51'}}>{soal.keterampilan}</div>
                                                    </td>
                                                    <td>
                                                        <div className={styles.soalDeskripsi}>{soal.deskripsi}</div>
                                                        {soal.petunjuk && (
                                                            <div className={styles.petunjukBox}><strong>Petunjuk Guru:</strong> {soal.petunjuk}</div>
                                                        )}
                                                    </td>
                                                    <td>
                                                        {soal.lampiran ? (
                                                            <span className={styles.badgeLampiran}>
                                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" style={{marginRight: '4px'}}><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                                                {soal.lampiran}
                                                            </span>
                                                        ) : <em className={styles.emptyText}>-</em>}
                                                    </td>
                                                    <td>{soal.saran_jika_kurang ? <div className={styles.saranText}>{soal.saran_jika_kurang}</div> : <em className={styles.emptyText}>-</em>}</td>
                                                    <td>
                                                        <div style={{display: 'flex', gap: '5px', justifyContent: 'center'}}>
                                                            <button className={styles.actionBtn} onClick={() => openEditSoal(soal, kat.id)}>
                                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4a5568" strokeWidth="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                                                            </button>
                                                            <button className={`${styles.actionBtn} ${styles.actionBtnDel}`} onClick={() => hapusKomponen(soal.id, soal.kode)}>
                                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#e53e3e" strokeWidth="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className={styles.emptyTable}>Belum ada instrumen di kategori ini.</div>
                            )}
                        </div>
                    </div>
                ))
            )}

            {/* MODAL KATEGORI */}
            {showKategoriModal && (
                <div className={styles.modalOverlay}>
                    <div className={styles.modalContent}>
                        <h2 className={styles.modalTitle}>{formKategori.id ? 'Edit Kategori' : 'Kategori Baru'}</h2>
                        <form onSubmit={handleSubmitKategori}>
                            <div className={styles.formGroup}>
                                <label>Nama Kategori</label>
                                <input type="text" className={styles.formInput} required placeholder="Cth: Motorik Kasar" value={formKategori.nama_kategori} onChange={(e) => setFormKategori({...formKategori, nama_kategori: e.target.value})} />
                            </div>
                            <div className={styles.formGroup}>
                                <label>Urutan Tampil</label>
                                <input type="number" className={styles.formInput} required value={formKategori.urutan} onChange={(e) => setFormKategori({...formKategori, urutan: e.target.value})} />
                            </div>
                            <div className={styles.modalActions}>
                                <button type="button" className={styles.cancelBtn} onClick={() => setShowKategoriModal(false)}>Batal</button>
                                <button type="submit" className={styles.saveBtn}>Simpan</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL KOMPONEN */}
            {showKomponenModal && (
                <div className={styles.modalOverlay}>
                    <div className={styles.modalContent} style={{ maxWidth: '650px' }}>
                        <h2 className={styles.modalTitle}>{formKomponen.id ? 'Edit Instrumen' : 'Setup Instrumen Baru'}</h2>
                        <form onSubmit={handleSubmitKomponen}>
                            
                            <div className={styles.grid2}>
                                <div className={styles.formGroup}>
                                    <label>Kode</label>
                                    <input type="text" className={styles.formInput} required placeholder="Cth: K1" value={formKomponen.kode} onChange={(e) => setFormKomponen({...formKomponen, kode: e.target.value})} />
                                </div>
                                <div className={styles.formGroup}>
                                    <label>Nama Keterampilan</label>
                                    <input type="text" className={styles.formInput} required placeholder="Cth: Berlari, Genggaman Pensil" value={formKomponen.keterampilan} onChange={(e) => setFormKomponen({...formKomponen, keterampilan: e.target.value})} />
                                </div>
                            </div>

                            <div className={styles.formGroup}>
                                <label>Deskripsi Tugas</label>
                                <textarea className={`${styles.formInput} ${styles.formTextarea}`} style={{minHeight: '50px'}} required placeholder="Cth: Berlari dengan lancar tanpa tersandung..." value={formKomponen.deskripsi} onChange={(e) => setFormKomponen({...formKomponen, deskripsi: e.target.value})} />
                            </div>

                            <div className={styles.formGroup}>
                                <label>Alat Peraga / Lampiran (Opsional)</label>
                                <input type="text" className={styles.formInput} placeholder="Cth: Lampiran 2 - Gambar Katak" value={formKomponen.lampiran} onChange={(e) => setFormKomponen({...formKomponen, lampiran: e.target.value})} />
                            </div>

                            <div className={styles.formGroup}>
                                <label>Petunjuk Pelaksanaan Guru (Opsional)</label>
                                <textarea className={`${styles.formInput} ${styles.formTextarea}`} style={{minHeight: '50px'}} placeholder="Instruksi untuk guru..." value={formKomponen.petunjuk} onChange={(e) => setFormKomponen({...formKomponen, petunjuk: e.target.value})} />
                            </div>

                            <div className={styles.formGroup}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', marginBottom: '10px' }}>
                                    <label style={{ margin: 0 }}>Auto-Saran di Raport PDF (Jika TIDAK)</label>
                                    <button type="button" onClick={generateAutoSaran} className={styles.magicBtn}>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M21 16.05A3.75 3.75 0 1 0 16.05 21L4.81 9.77a3.75 3.75 0 1 0 4.95-4.95z"></path><path d="M14 6l4-4"></path><path d="M18 10l4-4"></path><path d="M6 14l-4 4"></path><path d="M10 18l-4 4"></path></svg>
                                        Generate Otomatis
                                    </button>
                                </div>
                                <textarea className={`${styles.formInput} ${styles.formTextarea}`} style={{minHeight: '50px'}} value={formKomponen.saran_jika_kurang} onChange={(e) => setFormKomponen({...formKomponen, saran_jika_kurang: e.target.value})} />
                            </div>
                            
                            <div className={styles.modalActions}>
                                <button type="button" className={styles.cancelBtn} onClick={() => setShowKomponenModal(false)}>Batal</button>
                                <button type="submit" className={styles.saveBtn}>Simpan Instrumen</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

        </div>
    );
}