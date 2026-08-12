// src/app/dashboard/settings/page.tsx
'use client';

import { useState, useEffect, useRef } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './settings.module.css'; 

export default function SettingsPage() {
  const [activeTab, setActiveTab] = useState('AKUN'); 

  // --- STATE DATA MASTER ---
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  
  // Tab 1: Akun & Biaya
  const [formData, setFormData] = useState({ name: '', email: '', password: '', role: 'admin' });
  const [biaya, setBiaya] = useState({ biaya_sd: '', biaya_smp: '', bank_name: '', bank_account: '', bank_owner: '' });
  const [loadingBiaya, setLoadingBiaya] = useState(false);
  
  // Tab 2: Umum
  const [ppdbStatus, setPpdbStatus] = useState('TUTUP');
  const [kuotaSD, setKuotaSD] = useState(100);
  const [kuotaSMP, setKuotaSMP] = useState(50);
  const [loadingUmum, setLoadingUmum] = useState(false);
  
  // Tab 3: Whitelist
  const [whitelists, setWhitelists] = useState<any[]>([]);
  const [formWhite, setFormWhite] = useState({ nisn: '', nama_siswa: '', asal_sekolah: 'TK IT Al-Mubarak' });
  const fileInputRef = useRef<HTMLInputElement>(null);
  
  // Tab 4: Token
  const [tokens, setTokens] = useState<any[]>([]);
  const [jumlahToken, setJumlahToken] = useState<number | ''>(10);
  const [loadingToken, setLoadingToken] = useState(false);


  // === 1. FETCH SEMUA DATA (AMAN & ANTI LOADING ABADI) ===
  const fetchMasterData = async () => {
    setLoading(true);
    try {
      const [resUsers, resBiaya, resUmum, resWhite, resTokens] = await Promise.all([
        libAxios.get('/api/users').catch(() => ({ data: [] })),
        libAxios.get('/api/settings/biaya').catch(() => ({ data: {} })),
        libAxios.get('/api/admin/system-config').catch(() => ({ data: {} })),
        libAxios.get('/api/admin/whitelists').catch(() => ({ data: [] })),
        libAxios.get('/api/admin/tokens').catch(() => ({ data: [] }))
      ]);
      
      setUsers(resUsers.data || []);
      setBiaya({ 
          biaya_sd: resBiaya.data?.biaya_sd || '', 
          biaya_smp: resBiaya.data?.biaya_smp || '',
          bank_name: resBiaya.data?.bank_name || 'BANK SYARIAH INDONESIA (BSI)',
          bank_account: resBiaya.data?.bank_account || '',
          bank_owner: resBiaya.data?.bank_owner || 'Yayasan Al-Mubarak'
      });
      setPpdbStatus(resUmum.data?.ppdb_status || 'TUTUP');
      setKuotaSD(parseInt(resUmum.data?.kuota_sd || '100'));
      setKuotaSMP(parseInt(resUmum.data?.kuota_smp || '50'));
      setWhitelists(resWhite.data || []);
      setTokens(resTokens.data || []);

    } catch (err) { 
        console.error("Gagal menarik data", err); 
        Swal.fire('Error', 'Gagal memuat pengaturan', 'error');
    } finally { 
        setLoading(false); // PASTIKAN LOADING MATI APAPUN YANG TERJADI
    }
  };

  useEffect(() => { 
      fetchMasterData(); 
  }, []);

  // === 2. LOGIKA TAB AKUN & BIAYA ===
  const handleAddAdmin = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await libAxios.post('/api/users', formData);
      Swal.fire('Berhasil!', 'Akun berhasil ditambahkan', 'success');
      setFormData({ name: '', email: '', password: '', role: 'admin' });
      fetchMasterData();
    } catch (err) { Swal.fire('Gagal', 'Gagal menyimpan akun baru.', 'error'); }
  };

  const handleDelete = (id: number) => {
    Swal.fire({ title: 'Hapus akun?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#1a5f51', confirmButtonText: 'Hapus'
    }).then(async (result) => { if (result.isConfirmed) { await libAxios.delete(`/api/users/${id}`); fetchMasterData(); } });
  };

  const handleUpdateBiaya = async (e: React.FormEvent) => {
    e.preventDefault(); setLoadingBiaya(true);
    try {
      const res = await libAxios.post('/api/settings/biaya', biaya);
      Swal.fire({ icon: 'success', title: 'Tersimpan!', text: res.data.message, timer: 1500, showConfirmButton: false });
    } catch (err) { Swal.fire('Gagal', 'Terjadi kesalahan saat menyimpan biaya.', 'error'); } 
    finally { setLoadingBiaya(false); }
  };

  // === 3. LOGIKA TAB UMUM ===
  const handleSaveUmumSettings = async (e: React.FormEvent) => {
    e.preventDefault(); setLoadingUmum(true);
    try {
        await libAxios.post('/api/admin/system-config', { ppdb_status: ppdbStatus, kuota_sd: kuotaSD, kuota_smp: kuotaSMP });
        Swal.fire({ icon: 'success', title: 'Tersimpan!', text: 'Konfigurasi PPDB diperbarui.', timer: 1500, showConfirmButton: false });
    } catch (error) { Swal.fire('Gagal', 'Terjadi kesalahan sistem.', 'error'); } 
    finally { setLoadingUmum(false); }
  };

  // === 4. LOGIKA TAB WHITELIST ===
  const handleAddWhitelist = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
        await libAxios.post('/api/admin/whitelists', formWhite);
        Swal.fire({ icon: 'success', title: 'Tersimpan!', text: 'Siswa ditambahkan.', timer: 1500, showConfirmButton: false });
        setFormWhite({ nisn: '', nama_siswa: '', asal_sekolah: 'TK IT Al-Mubarak' });
        fetchMasterData();
    } catch (error: any) { Swal.fire('Gagal', error.response?.data?.message || 'Gagal menyimpan.', 'error'); }
  };

  const handleDeleteWhitelist = async (id: number) => {
    Swal.fire({ title: 'Hapus data ini?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#e53e3e', confirmButtonText: 'Hapus'
    }).then(async (result) => {
        if (result.isConfirmed) {
            try { await libAxios.delete(`/api/whitelists/${id}`); fetchMasterData(); } 
            catch (error: any) { Swal.fire('Gagal', 'Data sudah digunakan pendaftar.', 'error'); }
        }
    });
  };

  const downloadTemplate = () => {
    const csvContent = "data:text/csv;charset=utf-8,NISN,NAMA_SISWA,ASAL_SEKOLAH\n12345678,Budi Santoso,TK IT Al-Mubarak";
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri); link.setAttribute("download", "Template_Whitelist_Internal.csv");
    document.body.appendChild(link); link.click(); document.body.removeChild(link);
  };

  const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]; if (!file) return;
    const formDataFile = new FormData(); formDataFile.append('file', file);
    Swal.fire({ title: 'Mengimpor Data...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const res = await libAxios.post('/api/admin/whitelists/import', formDataFile, { headers: { 'Content-Type': 'multipart/form-data' } });
        Swal.fire('Berhasil!', res.data.message, 'success'); fetchMasterData();
    } catch (err) { Swal.fire('Gagal', 'Format file CSV tidak sesuai standar', 'error'); }
  };

  // === 5. LOGIKA TAB TOKEN ===
  const handleGenerateToken = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!jumlahToken || jumlahToken <= 0) return Swal.fire('Peringatan', 'Jumlah token tidak valid', 'warning');
    
    setLoadingToken(true);
    try {
        const res = await libAxios.post('/api/admin/tokens/generate', { jumlah: jumlahToken });
        Swal.fire('Sukses Produksi!', res.data.message, 'success');
        fetchMasterData();
    } catch (err) {
        Swal.fire('Gagal', 'Gagal memproduksi token baru.', 'error');
    } finally {
        setLoadingToken(false);
    }
  };

  const handleDeleteToken = async (id: number) => {
    Swal.fire({ title: 'Batalkan & hapus token ini?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#e53e3e', confirmButtonText: 'Hapus'
    }).then(async (result) => {
        if (result.isConfirmed) {
            try { await libAxios.delete(`/api/admin/tokens/${id}`); fetchMasterData(); } 
            catch (err: any) { Swal.fire('Ditolak', err.response?.data?.message || 'Gagal menghapus token.', 'error'); }
        }
    });
  };

  if (loading) return <div className={styles.loading}>Memuat Konfigurasi Sistem...</div>;

  return (
    <div className={styles.containerPad}>
      
      {/* HEADER & TAB NAVIGATION */}
      <div className={styles.topHeader}>
        <h1 className={styles.mainTitle}>Sistem Kontrol PPDB</h1>
        <p className={styles.subTitle}>Pengaturan pusat aplikasi pendaftaran dan manajemen sistem.</p>
        
        <div className={styles.tabNav}>
          <button className={`${styles.tabBtn} ${activeTab === 'AKUN' ? styles.tabActive : ''}`} onClick={() => setActiveTab('AKUN')}>Akun & Transaksi</button>
          <button className={`${styles.tabBtn} ${activeTab === 'UMUM' ? styles.tabActive : ''}`} onClick={() => setActiveTab('UMUM')}>Konfigurasi Global</button>
          <button className={`${styles.tabBtn} ${activeTab === 'TOKEN' ? styles.tabActive : ''}`} onClick={() => setActiveTab('TOKEN')}>Token (Umum)</button>
          <button className={`${styles.tabBtn} ${activeTab === 'INTERNAL' ? styles.tabActive : ''}`} onClick={() => setActiveTab('INTERNAL')}>Jalur Internal</button>
        </div>
      </div>

      {/* ========================================= TAB 1: AKUN & BIAYA ========================================= */}
      {activeTab === 'AKUN' && (
        <div className={styles.settingsGrid}>
          <div className={styles.leftCol}>
            <div className={styles.card}>
              <h3 className={styles.cardTitle}>Manajemen Akun Akses</h3>
              <table className={styles.adminTable}>
                <thead>
                  <tr className={styles.tableHeader}>
                    <th className={styles.thPad}>NAMA & EMAIL</th>
                    <th>HAK AKSES</th>
                    <th>AKSI</th>
                  </tr>
                </thead>
                <tbody>
                  {users.map((u: any) => (
                    <tr key={u.id} className={styles.tableRow}>
                      <td className={styles.tdPad}>
                        <div className={styles.adminName}>{u.name}</div>
                        <div className={styles.adminEmail}>{u.email}</div>
                      </td>
                      <td>
                        <span className={`${styles.roleBadge} ${u.role === 'super_admin' ? styles.roleSuperAdmin : styles.roleAdmin}`}>
                          {u.role.replace('_', ' ').toUpperCase()}
                        </span>
                      </td>
                      <td><button onClick={() => handleDelete(u.id)} className={styles.deleteBtn}>Hapus</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div className={styles.rightCol}>
            <div className={styles.card} style={{ marginBottom: '25px' }}>
              <h3 className={styles.cardTitle}>Tambah Akses Baru</h3>
              <form onSubmit={handleAddAdmin} className={styles.adminForm}>
                <input className={styles.formInput} placeholder="Nama Lengkap" required value={formData.name} onChange={e => setFormData({...formData, name: e.target.value})} />
                <input className={styles.formInput} type="email" placeholder="Email Login" required value={formData.email} onChange={e => setFormData({...formData, email: e.target.value})} />
                <input className={styles.formInput} type="password" placeholder="Password Login" required value={formData.password} onChange={e => setFormData({...formData, password: e.target.value})} />
                <select className={`${styles.formInput} ${styles.selectInput}`} value={formData.role} onChange={e => setFormData({...formData, role: e.target.value})}>
                  <option value="admin">Admin (TU / Keuangan)</option>
                  <option value="guru">Guru (Tim Observasi)</option>
                  <option value="super_admin">Super Admin</option>
                </select>
                <button type="submit" className={styles.submitBtn}>Tambahkan Akun</button>
              </form>
            </div>

            <div className={styles.card}>
              <h3 className={styles.cardTitle}>Tagihan & Rekening Pembayaran</h3>
              <form onSubmit={handleUpdateBiaya} className={styles.adminForm}>
                <div style={{ display: 'flex', gap: '15px' }}>
                    <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: '5px' }}>
                        <label style={{ fontSize: '12px', fontWeight: 600, color: '#718096' }}>Biaya SDIT (Rp)</label>
                        <input className={styles.formInput} type="number" required value={biaya.biaya_sd} onChange={e => setBiaya({...biaya, biaya_sd: e.target.value})} />
                    </div>
                    <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: '5px' }}>
                        <label style={{ fontSize: '12px', fontWeight: 600, color: '#718096' }}>Biaya SMPIT (Rp)</label>
                        <input className={styles.formInput} type="number" required value={biaya.biaya_smp} onChange={e => setBiaya({...biaya, biaya_smp: e.target.value})} />
                    </div>
                </div>

                <div style={{ padding: '15px', background: '#f8fafc', borderRadius: '12px', border: '1px solid #e2e8f0', marginTop: '10px' }}>
                    <label style={{ fontSize: '13px', fontWeight: 800, color: '#1a5f51', display: 'block', marginBottom: '10px' }}>Tujuan Transfer Manual</label>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                        <input className={styles.formInput} type="text" placeholder="Nama Bank (Cth: Bank Syariah Indonesia)" required value={biaya.bank_name} onChange={e => setBiaya({...biaya, bank_name: e.target.value})} />
                        <input className={styles.formInput} type="text" placeholder="Nomor Rekening" required value={biaya.bank_account} onChange={e => setBiaya({...biaya, bank_account: e.target.value})} />
                        <input className={styles.formInput} type="text" placeholder="Atas Nama" required value={biaya.bank_owner} onChange={e => setBiaya({...biaya, bank_owner: e.target.value})} />
                    </div>
                </div>

                <button type="submit" className={styles.submitBtn} disabled={loadingBiaya}>Simpan Konfigurasi Transaksi</button>
              </form>
            </div>
          </div>
        </div>
      )}

      {/* ========================================= TAB 2: KONFIGURASI GLOBAL ========================================= */}
      {activeTab === 'UMUM' && (
        <div className={styles.card} style={{ maxWidth: '600px', margin: '0 auto' }}>
          <h3 className={styles.cardTitle} style={{ marginBottom: '20px' }}>Kontrol Utama Aplikasi PPDB</h3>
          <form onSubmit={handleSaveUmumSettings} className={styles.adminForm}>
            <div style={{ background: '#f8fafc', padding: '20px', borderRadius: '12px', border: '1px solid #edf2f7' }}>
              <label style={{ fontSize: '14px', fontWeight: 800, color: '#2d3748', display: 'block', marginBottom: '15px' }}>Status Gerbang Pendaftaran</label>
              <div style={{ display: 'flex', gap: '15px' }}>
                  <label className={`${styles.radioLabel} ${ppdbStatus === 'BUKA' ? styles.radioActiveSuccess : ''}`}>
                      <input type="radio" value="BUKA" checked={ppdbStatus === 'BUKA'} onChange={(e) => setPpdbStatus(e.target.value)} /> BUKA TERUS
                  </label>
                  <label className={`${styles.radioLabel} ${ppdbStatus === 'TUTUP' ? styles.radioActiveDanger : ''}`}>
                      <input type="radio" value="TUTUP" checked={ppdbStatus === 'TUTUP'} onChange={(e) => setPpdbStatus(e.target.value)} /> TUTUP KUNCI
                  </label>
              </div>
            </div>
            <div style={{ display: 'flex', gap: '20px' }}>
              <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: '8px' }}>
                  <label style={{ fontSize: '13px', fontWeight: 700, color: '#4a5568' }}>Total Kursi SDIT</label>
                  <input className={styles.formInput} type="number" required value={kuotaSD} onChange={(e) => setKuotaSD(parseInt(e.target.value as any))} />
              </div>
              <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: '8px' }}>
                  <label style={{ fontSize: '13px', fontWeight: 700, color: '#4a5568' }}>Total Kursi SMPIT</label>
                  <input className={styles.formInput} type="number" required value={kuotaSMP} onChange={(e) => setKuotaSMP(parseInt(e.target.value as any))} />
              </div>
            </div>
            <button type="submit" className={styles.submitBtn} disabled={loadingUmum}>Terapkan Konfigurasi</button>
          </form>
        </div>
      )}

      {/* ========================================= TAB 3: GENERATE KODE TOKEN ========================================= */}
      {activeTab === 'TOKEN' && (
        <div className={styles.settingsGrid}>
          {/* SISI GENERATOR */}
          <div className={styles.rightCol}>
            <div className={styles.card}>
              <h3 className={styles.cardTitle}>Mesin Cetak Token</h3>
              <p style={{ fontSize: '12px', color: '#718096', margin: '10px 0 20px 0' }}>Token digunakan pendaftar umum untuk mengakses jalur pendaftaran.</p>
              
              <form onSubmit={handleGenerateToken} className={styles.adminForm}>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '5px' }}>
                  <label style={{ fontSize: '12px', fontWeight: 600, color: '#718096' }}>Jumlah Token Yang Ingin Dibuat</label>
                  <input className={styles.formInput} type="number" min="1" max="100" required value={jumlahToken} onChange={e => setJumlahToken(e.target.value === '' ? '' : parseInt(e.target.value))} />
                </div>
                <button type="submit" className={styles.submitBtn} disabled={loadingToken} style={{background: '#2b6cb0'}}>
                  {loadingToken ? 'Mencetak...' : '⚙️ Cetak Kode Massal'}
                </button>
              </form>
            </div>
          </div>

          {/* SISI TABEL LIST TOKEN */}
          <div className={styles.leftCol}>
            <div className={styles.card}>
              <h3 className={styles.cardTitle}>Daftar Token Terbit ({tokens.length} Data)</h3>
              <div style={{ overflowX: 'auto', marginTop: '15px', maxHeight: '450px' }}>
                <table className={styles.adminTable}>
                  <thead>
                    <tr className={styles.tableHeader}>
                      <th className={styles.thPad}>KODE TOKEN</th>
                      <th>STATUS</th>
                      <th>PENGGUNA</th>
                      <th>AKSI</th>
                    </tr>
                  </thead>
                  <tbody>
                    {tokens.length > 0 ? tokens.map((t, idx) => (
                      <tr key={idx} className={styles.tableRow}>
                        <td className={styles.tdPad} style={{fontFamily: 'monospace', fontSize: '15px', fontWeight: 900, color: '#2d3748'}}>{t.code}</td>
                        <td>
                          <span className={`${styles.roleBadge} ${t.is_used ? styles.roleAdmin : styles.roleSuperAdmin}`}>
                            {t.is_used ? 'HANGUS' : 'AKTIF'}
                          </span>
                        </td>
                        <td>
                          <div style={{fontWeight: 700, fontSize: '13px', color: '#1a5f51'}}>{t.nama_pendaftar || '-'}</div>
                        </td>
                        <td>
                          {!t.is_used && (
                            <button className={styles.deleteBtn} onClick={() => handleDeleteToken(t.id)}>Hapus</button>
                          )}
                        </td>
                      </tr>
                    )) : (
                      <tr>
                        <td colSpan={4} style={{textAlign: 'center', padding: '30px', color: '#a0aec0', fontSize: '13px'}}>Belum ada token yang dibuat.</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ========================================= TAB 4: JALUR INTERNAL ========================================= */}
      {activeTab === 'INTERNAL' && (
        <div className={styles.settingsGrid}>
          <div className={styles.rightCol}>
            <div className={styles.card} style={{ marginBottom: '20px' }}>
                <h3 className={styles.cardTitle}>Upload Massal (CSV)</h3>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '10px', marginTop: '15px' }}>
                    <button onClick={downloadTemplate} className={styles.btnOutline}>Download Template CSV</button>
                    <input type="file" accept=".csv" ref={fileInputRef} onChange={handleFileUpload} style={{ display: 'none' }} />
                    <button onClick={() => fileInputRef.current?.click()} className={styles.submitBtn} style={{background: '#2b6cb0', width: '100%'}}>Import File CSV</button>
                </div>
            </div>

            <div className={styles.card}>
                <h3 className={styles.cardTitle}>Input Manual 1 Siswa</h3>
                <form onSubmit={handleAddWhitelist} className={styles.adminForm}>
                    <input className={styles.formInput} type="text" placeholder="NISN / NIK Valid" required value={formWhite.nisn} onChange={(e) => setFormWhite({...formWhite, nisn: e.target.value})} />
                    <input className={styles.formInput} type="text" placeholder="Nama Lengkap Anak" required value={formWhite.nama_siswa} onChange={(e) => setFormWhite({...formWhite, nama_siswa: e.target.value})} />
                    <select className={`${styles.formInput} ${styles.selectInput}`} value={formWhite.asal_sekolah} onChange={(e) => setFormWhite({...formWhite, asal_sekolah: e.target.value})}>
                        <option value="TK IT Al-Mubarak">TK IT Al-Mubarak</option>
                        <option value="SD IT Al-Mubarak">SD IT Al-Mubarak</option>
                    </select>
                    <button type="submit" className={styles.submitBtn}>Simpan Data Manual</button>
                </form>
            </div>
          </div>

          <div className={styles.leftCol}>
            <div className={styles.card}>
                <h3 className={styles.cardTitle}>Database Bypass Aktif ({whitelists.length} Data)</h3>
                <div style={{ overflowX: 'auto', marginTop: '15px' }}>
                    <table className={styles.adminTable}>
                        <thead>
                            <tr className={styles.tableHeader}>
                                <th className={styles.thPad}>NISN</th>
                                <th>IDENTITAS</th>
                                <th>STATUS</th>
                                <th>AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            {whitelists.length > 0 ? whitelists.map((w, idx) => (
                                <tr key={idx} className={styles.tableRow}>
                                    <td className={styles.tdPad} style={{fontWeight: 800, color: '#2d3748'}}>{w.nisn}</td>
                                    <td>
                                      <div style={{fontWeight: 700, fontSize: '13px', color: '#1a5f51'}}>{w.nama_siswa}</div>
                                      <div style={{fontSize: '11px', color: '#718096'}}>{w.asal_sekolah}</div>
                                    </td>
                                    <td>
                                        <span className={`${styles.roleBadge} ${w.is_used ? styles.roleAdmin : styles.roleSuperAdmin}`}>
                                            {w.is_used ? 'DIPAKAI' : 'TERSEDIA'}
                                        </span>
                                    </td>
                                    <td>
                                        {!w.is_used && (
                                            <button className={styles.deleteBtn} onClick={() => handleDeleteWhitelist(w.id)}>Hapus</button>
                                        )}
                                    </td>
                                </tr>
                            )) : (
                                <tr><td colSpan={4} style={{textAlign: 'center', padding: '30px', color: '#a0aec0', fontSize: '13px'}}>Belum ada data whitelist.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
          </div>
        </div>
      )}

    </div>
  );
}