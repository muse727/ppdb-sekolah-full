// src/app/dashboard/settings/page.tsx
'use client';

import { useState, useEffect, useRef } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import type { Jenjang, PpdbStatus, StaffAccount, TokenMetadata, WhitelistEntry } from '@/lib/domain';
import { httpErrorMessage } from '@/lib/domain';
import styles from './settings.module.css'; 

interface BiayaForm {
  biaya_sd: string;
  biaya_smp: string;
  bank_name: string;
  rekening_sd: string;
  rekening_smp: string;
  bank_owner: string;
}

interface WhitelistForm {
  nama_siswa: string;
  jenjang: Jenjang;
  asal_sekolah: string;
}

interface SystemConfigView {
  ppdb_status?: string;
  kuota_sd?: string;
  kuota_smp?: string;
}

/**
 * Escape a value before it is interpolated into a SweetAlert2 `html` string.
 * The registration number is server-generated digits, but nothing here should
 * depend on that assumption — this keeps the modal XSS-safe for any value.
 */
function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * Write the registration number to the clipboard. Returns true on success.
 * The number is never logged and never persisted client-side.
 */
async function copyNumberToClipboard(number: string): Promise<boolean> {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(number);
      return true;
    }
  } catch {
    // fall through to the legacy path below
  }

  // Fallback for non-secure contexts (http://LAN) where navigator.clipboard
  // is unavailable. Uses a throwaway textarea so the value is never retained.
  try {
    const ta = document.createElement('textarea');
    ta.value = number;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  } catch {
    return false;
  }
}

export default function SettingsPage() {
  const [activeTab, setActiveTab] = useState('AKUN'); 

  // --- STATE DATA MASTER ---
  const [users, setUsers] = useState<StaffAccount[]>([]);
  const [loading, setLoading] = useState(true);
  
  // Tab 1: Akun & Biaya
  const [formData, setFormData] = useState({ name: '', email: '', password: '', role: 'admin' });
  const [biaya, setBiaya] = useState<BiayaForm>({ biaya_sd: '', biaya_smp: '', bank_name: '', rekening_sd: '', rekening_smp: '', bank_owner: '' });
  const [loadingBiaya, setLoadingBiaya] = useState(false);
  const [canEditFinancial, setCanEditFinancial] = useState(false);
  
  // Tab 2: Umum
  const [ppdbStatus, setPpdbStatus] = useState<PpdbStatus>('TUTUP');
  const [kuotaSD, setKuotaSD] = useState(100);
  const [kuotaSMP, setKuotaSMP] = useState(50);
  const [loadingUmum, setLoadingUmum] = useState(false);
  
  // Tab 3: Whitelist
  const [whitelists, setWhitelists] = useState<WhitelistEntry[]>([]);
  const [formWhite, setFormWhite] = useState<WhitelistForm>({ nama_siswa: '', jenjang: 'SD', asal_sekolah: '' });
  const fileInputRef = useRef<HTMLInputElement>(null);
  
  // Tab 4: Token
  const [tokens, setTokens] = useState<TokenMetadata[]>([]);
  const [jumlahToken, setJumlahToken] = useState<number | ''>(10);
  const [loadingToken, setLoadingToken] = useState(false);


  // === 1. FETCH SEMUA DATA (AMAN & ANTI LOADING ABADI) ===
  const fetchMasterData = async () => {
    setLoading(true);
    try {
      const [resUsers, resBiaya, resUmum, resWhite, resTokens, resActor] = await Promise.all([
        libAxios.get<StaffAccount[]>('/api/users').catch(() => ({ data: [] as StaffAccount[] })),
        libAxios.get<Partial<BiayaForm>>('/api/settings/biaya').catch(() => ({ data: {} as Partial<BiayaForm> })),
        libAxios.get<SystemConfigView>('/api/admin/system-config').catch(() => ({ data: {} as SystemConfigView })),
        libAxios.get<WhitelistEntry[]>('/api/admin/whitelists').catch(() => ({ data: [] as WhitelistEntry[] })),
        libAxios.get<TokenMetadata[]>('/api/admin/tokens').catch(() => ({ data: [] as TokenMetadata[] })),
        libAxios.get<{ role?: string }>('/api/user').catch(() => ({ data: {} as { role?: string } }))
      ]);
      
      setCanEditFinancial(resActor.data.role === 'super_admin');
      setUsers(resUsers.data || []);
      setBiaya({ 
          biaya_sd: resBiaya.data?.biaya_sd || '', 
          biaya_smp: resBiaya.data?.biaya_smp || '',
          bank_name: resBiaya.data?.bank_name || 'BANK SYARIAH INDONESIA (BSI)',
          rekening_sd: resBiaya.data?.rekening_sd || '',
          rekening_smp: resBiaya.data?.rekening_smp || '',
          bank_owner: resBiaya.data?.bank_owner || 'Yayasan Al-Mubarak'
      });
      setPpdbStatus((resUmum.data?.ppdb_status as PpdbStatus) || 'TUTUP');
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
      await libAxios.get('/sanctum/csrf-cookie');
      await libAxios.post('/api/users', formData);
      Swal.fire('Berhasil!', 'Akun berhasil ditambahkan', 'success');
      setFormData({ name: '', email: '', password: '', role: 'admin' });
      fetchMasterData();
    } catch (err: unknown) {
      const msg = httpErrorMessage(err, 'Gagal menyimpan akun baru.');
      Swal.fire('Gagal', msg, 'error');
    }
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
    } catch (err: unknown) { Swal.fire('Gagal', httpErrorMessage(err, 'Gagal menyimpan konfigurasi transaksi.'), 'error'); } 
    finally { setLoadingBiaya(false); }
  };

  // === 3. LOGIKA TAB UMUM ===
  const handleSaveUmumSettings = async (e: React.FormEvent) => {
    e.preventDefault(); setLoadingUmum(true);
    try {
        await libAxios.post('/api/admin/system-config', { ppdb_status: ppdbStatus, kuota_sd: kuotaSD, kuota_smp: kuotaSMP });
        Swal.fire({ icon: 'success', title: 'Tersimpan!', text: 'Konfigurasi SPMB diperbarui.', timer: 1500, showConfirmButton: false });
    } catch { Swal.fire('Gagal', 'Terjadi kesalahan sistem.', 'error'); } 
    finally { setLoadingUmum(false); }
  };

  // === 4. LOGIKA TAB WHITELIST ===
  const handleAddWhitelist = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
        const res = await libAxios.post('/api/admin/whitelists', formWhite);
        const number = res.data?.registration_number;
        setFormWhite({ nama_siswa: '', jenjang: 'SD', asal_sekolah: '' });
        fetchMasterData();

        if (!number) {
            Swal.fire({ icon: 'success', title: 'Tersimpan!' });
            return;
        }

        // PART F — first disclosure of the number to the admin. Copying it again
        // later ("Salin Nomor") always yields this SAME value; creation is the
        // only moment the number is generated.
        // The copy button lives inside the modal and does NOT close it, so the
        // admin can re-read/copy the number as many times as needed.
        void Swal.fire({
            icon: 'success',
            title: 'Tersimpan!',
            html: `<div style="text-align:center">` +
                  `<div style="font-size:12px;color:#718096;font-weight:600;margin-bottom:4px">Nomor Jalur Internal</div>` +
                  `<div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:24px;font-weight:700;letter-spacing:2px;color:#1a5f51">${escapeHtml(number)}</div>` +
                  `<button type="button" id="swalCopyNumber" class="${styles.actionBtn} ${styles.actionBtnSecondary}" style="margin-top:14px;padding:8px 18px;font-size:12px">Salin Nomor</button>` +
                  `</div>`,
            showConfirmButton: false,
            showCloseButton: true,
            didOpen: () => {
                const btn = document.getElementById('swalCopyNumber');
                if (!btn) return;
                btn.addEventListener('click', async () => {
                    const ok = await copyNumberToClipboard(number);
                    btn.textContent = ok ? 'Tersalin' : 'Gagal menyalin';
                });
            }
        });
    } catch (error: unknown) { Swal.fire('Gagal', httpErrorMessage(error, 'Gagal menyimpan.'), 'error'); }
  };

  const handleGenerateCode = async (w: WhitelistEntry) => {
    // PART G — regenerate is an EXPLICIT, destructive action: the old number
    // stops working the moment this succeeds, so it always asks first.
    const confirmed = await Swal.fire({
        title: 'Perbarui Nomor Jalur Internal?',
        text: 'Nomor lama akan langsung tidak berlaku.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#1a5f51',
        cancelButtonText: 'Batal',
        confirmButtonText: 'Perbarui'
    });
    if (!confirmed.isConfirmed) return;

    try {
        const res = await libAxios.post<{ registration_number?: string }>(`/api/admin/whitelists/${w.id}/regenerate-number`);
        const number = res.data?.registration_number ?? '';
        fetchMasterData();

        if (!number) {
            Swal.fire({ icon: 'success', title: 'Nomor berhasil diperbarui' });
            return;
        }

        // PART G — show the NEW number only; the old one is already invalid and
        // must never be displayed again. The in-modal copy button keeps the
        // dialog open so the number can be copied repeatedly.
        void Swal.fire({
            icon: 'success',
            title: 'Nomor berhasil diperbarui',
            html: `<div style="text-align:center">` +
                  `<div style="font-size:12px;color:#718096;font-weight:600;margin-bottom:4px">Nomor Jalur Internal</div>` +
                  `<div style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:24px;font-weight:700;letter-spacing:2px;color:#1a5f51">${escapeHtml(number)}</div>` +
                  `<div style="font-size:11px;color:#a0aec0;margin-top:6px">Nomor lama sudah tidak berlaku.</div>` +
                  `<button type="button" id="swalCopyNumber" class="${styles.actionBtn} ${styles.actionBtnSecondary}" style="margin-top:14px;padding:8px 18px;font-size:12px">Salin Nomor</button>` +
                  `</div>`,
            showConfirmButton: false,
            showCloseButton: true,
            didOpen: () => {
                const btn = document.getElementById('swalCopyNumber');
                if (!btn) return;
                btn.addEventListener('click', async () => {
                    const ok = await copyNumberToClipboard(number);
                    btn.textContent = ok ? 'Tersalin' : 'Gagal menyalin';
                });
            }
        });
    } catch (error: unknown) { Swal.fire('Ditolak', httpErrorMessage(error, 'Gagal memperbarui nomor.'), 'error'); }
  };

  const handleDeleteWhitelist = async (id: number) => {
    const confirmed = await Swal.fire({
        title: 'Hapus data ini?', icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#e53e3e', cancelButtonText: 'Batal', confirmButtonText: 'Hapus'
    });
    if (!confirmed.isConfirmed) return;

    try {
      // PART H — correct admin route. The old '/api/whitelists/{id}' path did
      // not exist, so every delete 404'd and the blanket catch reported a
      // false "already used" message.
      await libAxios.delete(`/api/admin/whitelists/${id}`);
      fetchMasterData();
    } catch (error: unknown) {
      // Surface the real server reason (409 used / 404 missing / 403 role)
      // instead of a hard-coded guess.
      Swal.fire('Gagal', httpErrorMessage(error, 'Gagal menghapus data.'), 'error');
      fetchMasterData();
    }
  };

  // PART D/E — "Salin Nomor" is READ-ONLY. It GETs the current number and puts
  // it on the clipboard. It NEVER regenerates: the number stays fixed until an
  // admin explicitly chooses "Perbarui Nomor".
  const handleCopyNumber = async (w: WhitelistEntry) => {
    try {
        const res = await libAxios.get<{ registration_number?: string }>(`/api/admin/whitelists/${w.id}/registration-number`);
        const number = res.data?.registration_number;
        if (!number) {
            Swal.fire('Gagal', 'Nomor belum tersedia untuk kandidat ini.', 'error');
            return;
        }
        const ok = await copyNumberToClipboard(number);
        if (ok) {
            // PART E — feedback must never imply a new number was generated.
            Swal.fire({ icon: 'success', title: 'Nomor berhasil disalin', timer: 1500, showConfirmButton: false });
        } else {
            Swal.fire('Gagal', 'Browser menolak akses clipboard. Salin nomor secara manual.', 'error');
        }
    } catch (error: unknown) {
        Swal.fire('Gagal', httpErrorMessage(error, 'Gagal menyalin nomor.'), 'error');
    }
  };

  const downloadTemplate = () => {
    const csvContent = "data:text/csv;charset=utf-8,NAMA,JENJANG,ASAL\nBudi Santoso,SD,TK IT Al-Mubarak";
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri); link.setAttribute("download", "Template_Whitelist_Internal.csv");
    document.body.appendChild(link); link.click(); document.body.removeChild(link);
  };

  // Card-level export — purely client-side, dumps the already-fetched
  // whitelist list as a CSV download. No backend endpoint involved.
  const handleExportWhitelist = () => {
    const header = 'nama_siswa,jenjang,asal_sekolah,status';
    const rows = whitelists.map(w =>
      `"${(w.nama_siswa || '').replace(/"/g, '""')}","${w.jenjang}","${(w.asal_sekolah || '').replace(/"/g, '""')}","${w.is_used ? 'DIGUNAKAN' : 'TERSEDIA'}"`
    );
    const csv = [header, ...rows].join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = 'jalur_internal.csv'; a.click();
    window.URL.revokeObjectURL(url);
  };

  const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]; if (!file) return;
    const formDataFile = new FormData(); formDataFile.append('file', file);
    Swal.fire({ title: 'Mengimpor Data...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    try {
        const res = await libAxios.post('/api/admin/whitelists/import', formDataFile, { headers: { 'Content-Type': 'multipart/form-data' } });
        Swal.fire('Berhasil!', res.data.message, 'success'); fetchMasterData();
    } catch { Swal.fire('Gagal', 'Format file CSV tidak sesuai standar', 'error'); }
  };

  // === 5. LOGIKA TAB TOKEN ===
  const handleGenerateToken = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!jumlahToken || jumlahToken <= 0) return Swal.fire('Peringatan', 'Jumlah token tidak valid', 'warning');
    
    setLoadingToken(true);
    try {
        const res = await libAxios.post('/api/admin/tokens/generate', { jumlah: jumlahToken });
        // P3-C: server mengembalikan kode plaintext SATU KALI saja di sini.
        // Tampilkan agar admin bisa mencetak/menyebarkannya — daftar token
        // (list) hanya menampilkan kode ter-mask.
        const issued: {code: string, expires_at: string}[] = res.data.tokens || [];
        const body = document.createElement('div');
        body.style.textAlign = 'left';
        const intro = document.createElement('p');
        intro.textContent = `${res.data.message ?? ''} Simpan/cetak kode berikut (hanya ditampilkan sekali):`;
        body.appendChild(intro);
        for (const token of issued) {
          const line = document.createElement('div');
          line.style.fontFamily = 'monospace';
          line.textContent = `${token.code} - berlaku s.d. ${new Date(token.expires_at).toLocaleDateString('id-ID')}`;
          body.appendChild(line);
        }
        Swal.fire({ icon: 'success', title: 'Sukses Produksi!', html: body });
        fetchMasterData();
    } catch {
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
            catch (err: unknown) { Swal.fire('Ditolak', httpErrorMessage(err, 'Gagal menghapus token.'), 'error'); }
        }
    });
  };

  if (loading) return <div className={styles.loading}>Memuat Konfigurasi Sistem...</div>;

  return (
    <div className={styles.containerPad}>
      
      {/* HEADER & TAB NAVIGATION */}
      <div className={styles.topHeader}>
        <h1 className={styles.mainTitle}>Sistem Kontrol SPMB</h1>
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
                  {users.map((u: StaffAccount) => (
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
                </select>
                <button type="submit" className={styles.submitBtn}>Tambahkan Akun</button>
              </form>
            </div>

            <div className={styles.card}>
              <h3 className={styles.cardTitle}>Tagihan & Rekening Pembayaran</h3>
              <form onSubmit={handleUpdateBiaya} className={styles.adminForm}>
                <fieldset disabled={!canEditFinancial || loadingBiaya} style={{ border: 0, padding: 0, margin: 0, minWidth: 0, display: 'flex', flexDirection: 'column', gap: '15px' }}>
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

                <p style={{ fontSize: '12px', color: '#718096', margin: 0 }}>Bank dan Atas Nama digunakan bersama untuk SD dan SMP. Hanya Super Admin dapat mengubah konfigurasi transaksi.</p>
                {(['SD', 'SMP'] as const).map(jenjang => {
                    const accountKey = jenjang === 'SD' ? 'rekening_sd' : 'rekening_smp';
                    return (
                        <div key={jenjang} style={{ padding: '15px', background: '#f8fafc', borderRadius: '12px', border: '1px solid #e2e8f0' }}>
                            <h4 style={{ fontSize: '13px', fontWeight: 800, color: '#1a5f51', margin: '0 0 12px' }}>REKENING {jenjang}</h4>
                            <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                                <label htmlFor={`bank-${jenjang}`} style={{ fontSize: '12px', fontWeight: 600, color: '#718096' }}>Bank (shared)</label>
                                <input id={`bank-${jenjang}`} className={styles.formInput} type="text" maxLength={100} required value={biaya.bank_name} onChange={e => setBiaya({...biaya, bank_name: e.target.value})} />
                                <label htmlFor={`rekening-${jenjang}`} style={{ fontSize: '12px', fontWeight: 600, color: '#718096' }}>Nomor Rekening {jenjang}</label>
                                <input id={`rekening-${jenjang}`} className={styles.formInput} type="text" inputMode="numeric" pattern="[0-9]{6,30}" minLength={6} maxLength={30} required value={biaya[accountKey]} onChange={e => setBiaya({...biaya, [accountKey]: e.target.value})} />
                                <label htmlFor={`owner-${jenjang}`} style={{ fontSize: '12px', fontWeight: 600, color: '#718096' }}>Atas Nama (shared)</label>
                                <input id={`owner-${jenjang}`} className={styles.formInput} type="text" maxLength={100} required value={biaya.bank_owner} onChange={e => setBiaya({...biaya, bank_owner: e.target.value})} />
                            </div>
                        </div>
                    );
                })}
                <button type="submit" className={styles.submitBtn}>Simpan Konfigurasi Transaksi</button>
                </fieldset>
              </form>
            </div>
          </div>
        </div>
      )}

      {/* ========================================= TAB 2: KONFIGURASI GLOBAL ========================================= */}
      {activeTab === 'UMUM' && (
        <div className={styles.card} style={{ maxWidth: '600px', margin: '0 auto' }}>
          <h3 className={styles.cardTitle} style={{ marginBottom: '20px' }}>Kontrol Utama Aplikasi SPMB</h3>
          <form onSubmit={handleSaveUmumSettings} className={styles.adminForm}>
            <div style={{ background: '#f8fafc', padding: '20px', borderRadius: '12px', border: '1px solid #edf2f7' }}>
              <label style={{ fontSize: '14px', fontWeight: 800, color: '#2d3748', display: 'block', marginBottom: '15px' }}>Status Gerbang Pendaftaran</label>
              <div style={{ display: 'flex', gap: '15px' }}>
                  <label className={`${styles.radioLabel} ${ppdbStatus === 'BUKA' ? styles.radioActiveSuccess : ''}`}>
                      <input type="radio" value="BUKA" checked={ppdbStatus === 'BUKA'} onChange={(e) => setPpdbStatus(e.target.value as PpdbStatus)} /> BUKA TERUS
                  </label>
                  <label className={`${styles.radioLabel} ${ppdbStatus === 'TUTUP' ? styles.radioActiveDanger : ''}`}>
                      <input type="radio" value="TUTUP" checked={ppdbStatus === 'TUTUP'} onChange={(e) => setPpdbStatus(e.target.value as PpdbStatus)} /> TUTUP KUNCI
                  </label>
              </div>
            </div>
            <div style={{ display: 'flex', gap: '20px' }}>
              <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: '8px' }}>
                  <label style={{ fontSize: '13px', fontWeight: 700, color: '#4a5568' }}>Total Kursi SDIT</label>
                  <input className={styles.formInput} type="number" required value={kuotaSD} onChange={(e) => setKuotaSD(parseInt(e.target.value))} />
              </div>
              <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: '8px' }}>
                  <label style={{ fontSize: '13px', fontWeight: 700, color: '#4a5568' }}>Total Kursi SMPIT</label>
                  <input className={styles.formInput} type="number" required value={kuotaSMP} onChange={(e) => setKuotaSMP(parseInt(e.target.value))} />
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
                  {loadingToken ? 'Mencetak...' : 'Cetak Kode Massal'}
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
                      <th>MASA BERLAKU</th>
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
                            {t.is_used ? 'HANGUS' : (t.is_expired ? 'KEDALUWARSA' : 'AKTIF')}
                          </span>
                        </td>
                        <td style={{fontSize: '12px', color: '#4a5568'}}>
                          {t.expires_at ? new Date(t.expires_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : 'Tanpa batas (lama)'}
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
                        <td colSpan={5} style={{textAlign: 'center', padding: '30px', color: '#a0aec0', fontSize: '13px'}}>Belum ada token yang dibuat.</td>
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
                    <input className={styles.formInput} type="text" placeholder="Nama Lengkap Anak" required value={formWhite.nama_siswa} onChange={(e) => setFormWhite({...formWhite, nama_siswa: e.target.value})} />
                    <select className={`${styles.formInput} ${styles.selectInput}`} value={formWhite.jenjang} onChange={(e) => setFormWhite({...formWhite, jenjang: e.target.value as Jenjang})}>
                        <option value="SD">SD (Tujuan SDIT)</option>
                        <option value="SMP">SMP (Tujuan SMPIT)</option>
                    </select>
                    <input className={styles.formInput} type="text" placeholder="Asal Sekolah (opsional)" value={formWhite.asal_sekolah} onChange={(e) => setFormWhite({...formWhite, asal_sekolah: e.target.value})} />
                    <button type="submit" className={styles.submitBtn}>Simpan Data Manual</button>
                </form>
            </div>
          </div>

          <div className={styles.leftCol}>
            <div className={styles.card}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid #edf2f7', paddingBottom: '12px' }}>
                    <h3 className={styles.cardTitle} style={{ borderBottom: 'none', paddingBottom: 0 }}>Daftar Jalur Internal ({whitelists.length} Data)</h3>
                    <button className={styles.submitBtn} style={{ padding: '6px 14px', fontSize: '11px', width: 'auto', whiteSpace: 'nowrap' }} onClick={handleExportWhitelist}>Export Data</button>
                </div>
                <div style={{ overflowX: 'auto', marginTop: '15px' }}>
                    <table className={styles.adminTable}>
                        <thead>
                            <tr className={styles.tableHeader}>
                                <th className={styles.thPad}>IDENTITAS</th>
                                <th>JENJANG</th>
                                <th>STATUS</th>
                                <th className={styles.aksiCol}>AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            {whitelists.length > 0 ? whitelists.map((w, idx) => (
                                <tr key={idx} className={styles.tableRow}>
                                    <td className={styles.tdPad}>
                                      <div style={{fontWeight: 700, fontSize: '13px', color: '#1a5f51'}}>{w.nama_siswa}</div>
                                      <div style={{fontSize: '11px', color: '#718096'}}>{w.asal_sekolah}</div>
                                    </td>
                                    <td>
                                        <span className={`${styles.roleBadge} ${styles.roleSuperAdmin}`}>{w.jenjang || '-'}</span>
                                    </td>
                                    <td>
                                        <span className={`${styles.roleBadge} ${w.is_used ? styles.roleAdmin : styles.roleSuperAdmin}`}>
                                            {w.is_used ? 'DIPAKAI' : 'TERSEDIA'}
                                        </span>
                                    </td>
                                    <td>
                                        {w.is_used ? (
                                            <span className={styles.usedBadge}>Sudah Digunakan</span>
                                        ) : (
                                            <div className={styles.actionGroup}>
                                                <button className={`${styles.actionBtn} ${styles.actionBtnNeutral}`} onClick={() => handleCopyNumber(w)}>Salin Nomor</button>
                                                <button className={`${styles.actionBtn} ${styles.actionBtnSecondary}`} onClick={() => handleGenerateCode(w)}>Perbarui Nomor</button>
                                                <button className={`${styles.actionBtn} ${styles.actionBtnDestructive}`} onClick={() => handleDeleteWhitelist(w.id)}>Hapus</button>
                                            </div>
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