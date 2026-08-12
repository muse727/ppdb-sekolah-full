'use client';

import React, { useState, useEffect } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './pendaftar.module.css';

const STEP_NAMES = [
    'Pembayaran', 
    'Biodata', 
    'Berkas', 
    'Observasi', 
    'Pengumuman', 
    'Daftar Ulang'
];

export default function MasterPendaftar() {
    const [pendaftar, setPendaftar] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    
    const [searchTerm, setSearchTerm] = useState('');
    const [filterStatus, setFilterStatus] = useState('Semua');
    const [jenjangFilter, setJenjangFilter] = useState('SEMUA'); 

    const [selectedUser, setSelectedUser] = useState<any>(null);
    const [activeTab, setActiveTab] = useState<number>(1);

    const API_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

    const fetchData = async () => {
        try {
            const res = await libAxios.get('/api/admin/pendaftar');
            setPendaftar(res.data);
        } catch (error) {
            console.error('Gagal memuat data', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { fetchData(); }, []);

    const filteredData = pendaftar.filter((item) => {
        const matchSearch = item.name.toLowerCase().includes(searchTerm.toLowerCase()) || item.email.toLowerCase().includes(searchTerm.toLowerCase());
        
        let currentStatus = item.status_bayar;
        if (item.step === 2) currentStatus = 'Isi Biodata';
        if (item.step === 3) currentStatus = 'Upload Berkas';
        if (item.step === 4) currentStatus = 'Observasi';
        if (item.step >= 5) currentStatus = item.status_kelulusan === 'LULUS' ? 'Lulus' : 'Pengumuman';

        const matchStatus = filterStatus === 'Semua' || 
                            item.status_bayar === filterStatus || 
                            currentStatus.includes(filterStatus);
        
        let matchJenjang = true;
        if (jenjangFilter === 'SD') matchJenjang = item.jenjang === 'SD';
        if (jenjangFilter === 'SMP') matchJenjang = item.jenjang === 'SMP';

        return matchSearch && matchStatus && matchJenjang;
    });

    const handleVerify = (id: number, imageUrl: string, name: string) => {
        if (!imageUrl) {
            Swal.fire({ icon: 'error', title: 'Data Kosong', text: 'Foto bukti transfer tidak ditemukan.', confirmButtonColor: '#1a5f51' });
            return;
        }

        const fullImageUrl = `${API_URL}/storage/${imageUrl}`;
        
        Swal.fire({
            title: `Verifikasi Transfer: ${name}`,
            text: "Pastikan nominal dan tanggal transfer valid.",
            imageUrl: fullImageUrl,
            imageHeight: 350,
            imageAlt: 'Bukti Transfer',
            showCancelButton: true,
            confirmButtonColor: '#1a5f51',
            cancelButtonColor: '#e53e3e',
            confirmButtonText: 'Ya, Verifikasi (Lunas)',
            cancelButtonText: 'Tutup'
        }).then(async (result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Memproses...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                try {
                    await libAxios.post(`/api/admin/payments/${id}/verify`);
                    Swal.fire('Terverifikasi!', 'Status pendaftar menjadi Lunas.', 'success');
                    fetchData(); 
                } catch (error) {
                    Swal.fire('Gagal', 'Terjadi kesalahan sistem saat memverifikasi.', 'error');
                }
            }
        });
    };

    const openDetail = (user: any) => {
        setSelectedUser(user);
        setActiveTab(1); 
    };

    const getStatusBadge = (user: any) => {
        if (user.status_bayar === 'Menunggu Verifikasi') return <span className={`${styles.badge} ${styles.badgeWait}`}>Verifikasi Bayar</span>;
        if (user.status_bayar === 'Pending DOKU') return <span className={`${styles.badge} ${styles.badgePending}`}>Pending DOKU</span>;
        if (user.step === 1) return <span className={`${styles.badge} ${styles.badgeNull}`}>Belum Bayar</span>;
        if (user.step === 2) return <span className={`${styles.badge} ${styles.badgePending}`}>Melengkapi Biodata</span>;
        if (user.step === 3) return <span className={`${styles.badge} ${styles.badgePending}`}>Melengkapi Berkas</span>;
        if (user.step === 4) return <span className={`${styles.badge} ${styles.badgeWait}`}>Tahap Observasi</span>;
        if (user.step >= 5 && user.status_kelulusan === 'LULUS') return <span className={`${styles.badge} ${styles.badgeLunas}`}>Lulus Seleksi</span>;
        if (user.step >= 5 && user.status_kelulusan === 'TIDAK_LULUS') return <span className={`${styles.badge} ${styles.badgeNull}`}>Tidak Lulus</span>;
        return <span className={`${styles.badge} ${styles.badgeLunas}`}>Tahap Validasi</span>;
    };

    return (
        <div className={styles.container}>
            <div className={styles.headerCard}>
                <div className={styles.headerTitle}>
                    <h2>Pusat Data Pendaftar</h2>
                    <p>Pantau seluruh progress calon siswa di satu layar</p>
                </div>
                
                <div className={styles.filterSection}>
                    <input type="text" placeholder="Cari nama / email..." className={styles.searchInput} value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} />
                    <select className={styles.selectFilter} value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)}>
                        <option value="Semua">Semua Status</option>
                        <option value="Menunggu Verifikasi">Perlu Verifikasi Transfer</option>
                        <option value="Pending DOKU">Pending DOKU</option>
                        <option value="Belum Bayar">Belum Bayar</option>
                        <option value="Lulus">Lulus Seleksi</option>
                    </select>
                </div>
            </div>

            <div className={styles.tabContainer}>
                <button className={`${styles.tabBtn} ${jenjangFilter === 'SEMUA' ? styles.tabActive : ''}`} onClick={() => setJenjangFilter('SEMUA')}>Semua Jenjang</button>
                <button className={`${styles.tabBtn} ${jenjangFilter === 'SD' ? styles.tabActive : ''}`} onClick={() => setJenjangFilter('SD')}>SDIT Al-Mubarak</button>
                <button className={`${styles.tabBtn} ${jenjangFilter === 'SMP' ? styles.tabActive : ''}`} onClick={() => setJenjangFilter('SMP')}>SMPIT Al-Mubarak</button>
            </div>

            <div className={styles.tableCard}>
                {loading ? (
                    <div style={{ padding: '50px', textAlign: 'center', color: '#a0aec0', fontWeight: 'bold' }}>Memuat data sistem...</div>
                ) : filteredData.length === 0 ? (
                    <div style={{ padding: '50px', textAlign: 'center', color: '#a0aec0', fontWeight: 'bold' }}>Tidak ada pendaftar yang cocok.</div>
                ) : (
                    <table className={styles.dataTable}>
                        <thead>
                            <tr>
                                <th style={{width: '25%'}}>DATA ORANG TUA</th>
                                <th style={{width: '10%'}}>JENJANG</th>
                                <th style={{width: '25%'}}>PROGRESS TAHAPAN</th>
                                <th style={{width: '20%'}}>STATUS TERKINI</th>
                                <th style={{width: '20%'}}>AKSI ADMIN</th>
                            </tr>
                        </thead>
                        <tbody>
                            {filteredData.map((item) => (
                                <tr key={item.id}>
                                    <td>
                                        <div className={styles.userName}>{item.name}</div>
                                        <div className={styles.userEmail}>{item.email}</div>
                                    </td>
                                    <td><span style={{ fontWeight: 800 }}>{item.jenjang !== '-' ? `${item.jenjang}` : '-'}</span></td>
                                    
                                    <td>
                                        <div className={styles.stepperWrapper} onClick={() => openDetail(item)} title="Klik untuk lihat detail">
                                            <div className={styles.stepperText}>
                                                <span>Tahap {item.step}: {STEP_NAMES[(item.step || 1) - 1]}</span>
                                                <span style={{color: '#a0aec0'}}>Detail</span>
                                            </div>
                                            <div className={styles.miniStepper}>
                                                {[1, 2, 3, 4, 5, 6].map((s, index) => (
                                                    <React.Fragment key={s}>
                                                        <div className={`${styles.stepDot} ${s < item.step ? styles.stepDotCompleted : s === item.step ? styles.stepDotActive : ''}`}></div>
                                                        {index < 5 && (
                                                            <div className={`${styles.stepLine} ${s < item.step ? styles.stepLineCompleted : ''}`}></div>
                                                        )}
                                                    </React.Fragment>
                                                ))}
                                            </div>
                                        </div>
                                    </td>

                                    <td>{getStatusBadge(item)}</td>
                                    
                                    <td>
                                        {item.status_bayar === 'Menunggu Verifikasi' ? (
                                            <button className={styles.actionBtn} onClick={() => handleVerify(item.payment_id, item.proof_of_payment, item.name)}>
                                                Verifikasi
                                            </button>
                                        ) : (
                                            <button className={styles.viewBtnOutline} onClick={() => openDetail(item)}>Buka Berkas</button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {selectedUser && (
                <div className={styles.modalOverlay} onClick={() => setSelectedUser(null)}>
                    <div className={styles.modalBox} onClick={(e) => e.stopPropagation()}>
                        <div className={styles.modalHeader}>
                            <div className={styles.modalHeaderInfo}>
                                <h3>Detail Progress: {selectedUser.name}</h3>
                                <p>{selectedUser.email} • Calon Siswa {selectedUser.jenjang !== '-' ? selectedUser.jenjang : 'Belum Memilih'}</p>
                            </div>
                            <button className={styles.closeBtn} onClick={() => setSelectedUser(null)}>
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            </button>
                        </div>

                        <div className={styles.modalBody}>
                            <div className={styles.stepperNav}>
                                {[
                                    { step: 1, title: 'Akun & Bayar', desc: 'Registrasi & Formulir' },
                                    { step: 2, title: 'Biodata', desc: 'Data lengkap siswa' },
                                    { step: 3, title: 'Berkas', desc: 'KK, Akte, dll' },
                                    { step: 4, title: 'Observasi', desc: 'Jadwal & Hasil tes' },
                                    { step: 5, title: 'Pengumuman', desc: 'Hasil seleksi akhir' },
                                ].map((s) => (
                                    <div 
                                        key={s.step} 
                                        className={`${styles.stepItem} ${activeTab === s.step ? styles.stepItemActive : ''} ${s.step > selectedUser.step ? styles.stepItemLocked : ''}`}
                                        onClick={() => { if (s.step <= selectedUser.step || s.step === 1) setActiveTab(s.step); }}
                                    >
                                        <div className={styles.stepIcon}>
                                            {s.step < selectedUser.step ? (
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                            ) : (
                                                s.step
                                            )}
                                        </div>
                                        <div className={styles.stepText}>
                                            <h4>{s.title}</h4>
                                            <p>{s.desc}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className={styles.stepContent}>
                                
                                {activeTab === 1 && (
                                    <div className={styles.contentCard}>
                                        <h4 className={styles.contentTitle}>Informasi Pembayaran</h4>
                                        <div className={styles.detailRow}><span className={styles.detailLabel}>Nama Pendaftar</span><span className={styles.detailValue}>{selectedUser.name}</span></div>
                                        <div className={styles.detailRow}><span className={styles.detailLabel}>Email Terdaftar</span><span className={styles.detailValue}>{selectedUser.email}</span></div>
                                        <div className={styles.detailRow}>
                                            <span className={styles.detailLabel}>Status Pembayaran</span>
                                            <span className={styles.detailValue}>
                                                <span className={`${styles.badge} ${selectedUser.status_bayar === 'Lunas' || selectedUser.step > 1 ? styles.badgeLunas : styles.badgePending}`}>
                                                    {selectedUser.step > 1 ? 'Lunas' : selectedUser.status_bayar}
                                                </span>
                                            </span>
                                        </div>
                                        <div className={styles.detailRow}><span className={styles.detailLabel}>Jenjang Pilihan</span><span className={styles.detailValue}>{selectedUser.jenjang !== '-' ? `${selectedUser.jenjang}` : 'Belum Memilih'}</span></div>
                                        
                                        {selectedUser.proof_of_payment && (
                                            <div className={styles.attachmentBox}>
                                                <p>Lampiran Bukti Transfer</p>
                                                <img src={`${API_URL}/storage/${selectedUser.proof_of_payment}`} alt="Bukti Transfer" />
                                            </div>
                                        )}
                                    </div>
                                )}

                                {activeTab === 2 && (
                                    selectedUser.biodata ? (
                                        <>
                                            <div className={styles.contentCard} style={{marginBottom: '20px'}}>
                                                <h4 className={styles.contentTitle}>Identitas Calon Siswa</h4>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Asal Sekolah</span><span className={styles.detailValue}>{selectedUser.biodata.asal_sekolah || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Nama Lengkap</span><span className={styles.detailValue}>{selectedUser.biodata.nama_lengkap || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Nama Panggilan</span><span className={styles.detailValue}>{selectedUser.biodata.nama_panggilan || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Jenis Kelamin</span><span className={styles.detailValue}>{selectedUser.biodata.jenis_kelamin === 'L' ? 'Laki-laki' : selectedUser.biodata.jenis_kelamin === 'P' ? 'Perempuan' : '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>NISN / NIK Siswa</span><span className={styles.detailValue}>{(selectedUser.biodata.nisn || '-') + ' / ' + (selectedUser.biodata.nik_siswa || '-')}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>No. Kartu Keluarga</span><span className={styles.detailValue}>{selectedUser.biodata.no_kk || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Tempat, Tgl Lahir</span><span className={styles.detailValue}>{selectedUser.biodata.tempat_lahir || '-'}, {selectedUser.biodata.tanggal_lahir ? new Date(selectedUser.biodata.tanggal_lahir).toLocaleDateString('id-ID') : '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Status Anak</span><span className={styles.detailValue}>Anak ke-{selectedUser.biodata.anak_ke || '-'} ({selectedUser.biodata.status_peserta_didik || '-'})</span></div>
                                            </div>

                                            <div className={styles.contentCard} style={{marginBottom: '20px'}}>
                                                <h4 className={styles.contentTitle}>Data Orang Tua</h4>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Nama Ayah</span><span className={styles.detailValue}>{selectedUser.biodata.nama_ayah || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>NIK Ayah</span><span className={styles.detailValue}>{selectedUser.biodata.nik_ayah || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Pendidikan Ayah</span><span className={styles.detailValue}>{selectedUser.biodata.pendidikan_ayah || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Pekerjaan Ayah</span><span className={styles.detailValue}>{selectedUser.biodata.pekerjaan_ayah || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Penghasilan Ayah</span><span className={styles.detailValue}>{selectedUser.biodata.penghasilan_ayah || '-'}</span></div>
                                                
                                                <div className={styles.dividerLine}></div>

                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Nama Ibu</span><span className={styles.detailValue}>{selectedUser.biodata.nama_ibu || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>NIK Ibu</span><span className={styles.detailValue}>{selectedUser.biodata.nik_ibu || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Pendidikan Ibu</span><span className={styles.detailValue}>{selectedUser.biodata.pendidikan_ibu || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Pekerjaan Ibu</span><span className={styles.detailValue}>{selectedUser.biodata.pekerjaan_ibu || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Penghasilan Ibu</span><span className={styles.detailValue}>{selectedUser.biodata.penghasilan_ibu || '-'}</span></div>
                                            </div>

                                            <div className={styles.contentCard}>
                                                <h4 className={styles.contentTitle}>Kontak & Alamat</h4>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>WhatsApp Orang Tua</span><span className={styles.detailValue}>{selectedUser.biodata.no_hp_ortu || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Email Siswa</span><span className={styles.detailValue}>{selectedUser.biodata.email_siswa || '-'}</span></div>
                                                <div className={styles.detailRow}><span className={styles.detailLabel}>Alamat Lengkap</span><span className={styles.detailValue}>{selectedUser.biodata.alamat_lengkap || '-'}</span></div>
                                                <div className={styles.detailRow}>
                                                    <span className={styles.detailLabel}>Wilayah Detail</span>
                                                    <span className={styles.detailValue}>
                                                        RT {selectedUser.biodata.rt || '-'} / RW {selectedUser.biodata.rw || '-'}, Kel. {selectedUser.biodata.kelurahan || '-'}, Kec. {selectedUser.biodata.kecamatan || '-'}, {selectedUser.biodata.kota || '-'}
                                                    </span>
                                                </div>

                                                {(selectedUser.biodata.nama_wali || selectedUser.biodata.no_hp_wali) && (
                                                    <>
                                                        <div className={styles.dividerLine}></div>
                                                        <div className={styles.detailRow}><span className={styles.detailLabel}>Nama Wali</span><span className={styles.detailValue}>{selectedUser.biodata.nama_wali || '-'}</span></div>
                                                        <div className={styles.detailRow}><span className={styles.detailLabel}>Kontak Wali</span><span className={styles.detailValue}>{selectedUser.biodata.no_hp_wali || '-'}</span></div>
                                                    </>
                                                )}
                                            </div>
                                        </>
                                    ) : (
                                        <div className={styles.emptyStateCard}>Data biodata belum diisi oleh pendaftar.</div>
                                    )
                                )}

                                {activeTab === 3 && (
                                    selectedUser.berkas && (selectedUser.berkas.kartu_keluarga || selectedUser.berkas.akte_kelahiran) ? (
                                        <div className={styles.contentCard}>
                                            <h4 className={styles.contentTitle}>Dokumen Fisik (KK & Akte)</h4>
                                            <div className={styles.documentGrid}>
                                                {selectedUser.berkas.kartu_keluarga && (
                                                    <div className={styles.documentItem}>
                                                        <p>Kartu Keluarga</p>
                                                        <a href={`${API_URL}/storage/${selectedUser.berkas.kartu_keluarga}`} target="_blank" rel="noreferrer">Lihat Berkas</a>
                                                    </div>
                                                )}
                                                {selectedUser.berkas.akte_kelahiran && (
                                                    <div className={styles.documentItem}>
                                                        <p>Akte Kelahiran</p>
                                                        <a href={`${API_URL}/storage/${selectedUser.berkas.akte_kelahiran}`} target="_blank" rel="noreferrer">Lihat Berkas</a>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    ) : (
                                        <div className={styles.emptyStateCard}>Dokumen berkas belum diunggah.</div>
                                    )
                                )}

                                {activeTab === 4 && (
                                    <div className={styles.contentCard}>
                                        <h4 className={styles.contentTitle}>Jadwal & Hasil Observasi</h4>
                                        {selectedUser.jadwal_observasi ? (
                                            <>
                                                <div className={styles.detailRow}>
                                                    <span className={styles.detailLabel}>Tanggal Tes</span>
                                                    <span className={styles.detailValue}>
                                                        {new Date(selectedUser.jadwal_observasi).toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
                                                    </span>
                                                </div>
                                                <div className={styles.detailRow}>
                                                    <span className={styles.detailLabel}>Waktu & Tempat</span>
                                                    <span className={styles.detailValue}>
                                                        Pukul {selectedUser.jam_observasi} WIB • {selectedUser.ruang_observasi}
                                                    </span>
                                                </div>
                                                {selectedUser.perlengkapan && (
                                                    <div className={styles.detailRow}>
                                                        <span className={styles.detailLabel}>Perlengkapan Bawaan</span>
                                                        <span className={styles.detailValue}>{selectedUser.perlengkapan}</span>
                                                    </div>
                                                )}
                                                <div className={styles.dividerLine}></div>
                                                <div className={styles.detailRow}>
                                                    <span className={styles.detailLabel}>Status Penilaian Guru</span>
                                                    <span className={styles.detailValue}>
                                                        {selectedUser.hasil_observasi ? (
                                                            <span style={{color: '#10b981', fontWeight: 800}}>Telah Dinilai Guru</span>
                                                        ) : (
                                                            <span style={{color: '#f59e0b', fontWeight: 800}}>Menunggu Penilaian</span>
                                                        )}
                                                    </span>
                                                </div>
                                            </>
                                        ) : (
                                            <div className={styles.emptyStateCard}>Jadwal observasi belum diatur oleh Admin.</div>
                                        )}
                                    </div>
                                )}

                                {activeTab === 5 && (
                                    <div className={styles.contentCard}>
                                        <h4 className={styles.contentTitle}>Keputusan Panitia Kelulusan</h4>
                                        <div className={styles.decisionBox}>
                                            {selectedUser.status_kelulusan ? (
                                                <span className={`${styles.badge} ${selectedUser.status_kelulusan === 'LULUS' ? styles.badgeLunas : styles.badgeNull} ${styles.badgeLarge}`}>
                                                    {selectedUser.status_kelulusan === 'LULUS' ? 'LULUS SELEKSI' : 'TIDAK LULUS'}
                                                </span>
                                            ) : (
                                                <span className={`${styles.badge} ${styles.badgeWait} ${styles.badgeLarge}`}>Belum Ada Keputusan</span>
                                            )}
                                        </div>
                                    </div>
                                )}

                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}