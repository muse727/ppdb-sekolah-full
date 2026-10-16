// src/app/dashboard/settings/page.tsx
'use client';

import { useState, useEffect, useRef } from 'react';
import * as XLSX from 'xlsx';
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
  jenis_kelamin: 'L' | 'P';
  asal_sekolah: string;
}

interface SystemConfigView {
  ppdb_status?: string;
  gate_jalur_umum?: string;
  gate_jalur_internal?: string;
  kuota_sd?: string | number;
  kuota_smp?: string | number;
  kuota_smp_ikhwan?: string | number;
  kuota_smp_akhwat?: string | number;
  kuota_sd_terpakai?: number;
  kuota_smp_terpakai?: number;
  kuota_smp_ikhwan_terpakai?: number;
  kuota_smp_akhwat_terpakai?: number;
  kuota_sd_sisa?: number;
  kuota_smp_sisa?: number;
  kuota_smp_ikhwan_sisa?: number;
  kuota_smp_akhwat_sisa?: number;
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
  const [showPassword, setShowPassword] = useState(false);
  const [biaya, setBiaya] = useState<BiayaForm>({ biaya_sd: '', biaya_smp: '', bank_name: '', rekening_sd: '', rekening_smp: '', bank_owner: '' });
  const [loadingBiaya, setLoadingBiaya] = useState(false);
  const [canEditFinancial, setCanEditFinancial] = useState(false);
  
  // Tab 2: Umum
  const [ppdbStatus, setPpdbStatus] = useState<PpdbStatus>('TUTUP');
  const [gateUmum, setGateUmum] = useState<PpdbStatus>('TUTUP');
  const [gateInternal, setGateInternal] = useState<PpdbStatus>('BUKA');
  const [kuotaSD, setKuotaSD] = useState(100);
  const [kuotaSmpIkhwan, setKuotaSmpIkhwan] = useState(30);
  const [kuotaSmpAkhwat, setKuotaSmpAkhwat] = useState(30);
  const [statsUmum, setStatsUmum] = useState<SystemConfigView>({});
  const [loadingUmum, setLoadingUmum] = useState(false);
  
  // Tab 3: Whitelist
  const [whitelists, setWhitelists] = useState<WhitelistEntry[]>([]);
  const [formWhite, setFormWhite] = useState<WhitelistForm>({ nama_siswa: '', jenjang: 'SD', jenis_kelamin: 'L', asal_sekolah: '' });
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
      setGateUmum((resUmum.data?.gate_jalur_umum as PpdbStatus) || 'TUTUP');
      setGateInternal((resUmum.data?.gate_jalur_internal as PpdbStatus) || 'BUKA');
      setKuotaSD(parseInt(String(resUmum.data?.kuota_sd || '100')));
      setKuotaSmpIkhwan(parseInt(String(resUmum.data?.kuota_smp_ikhwan || '30')));
      setKuotaSmpAkhwat(parseInt(String(resUmum.data?.kuota_smp_akhwat || '30')));
      setStatsUmum(resUmum.data || {});
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
  const handleCopyText = async (text: string, label: string) => {
    if (!text) return;
    const ok = await copyNumberToClipboard(text);
    if (ok) {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: `${label} berhasil disalin!`,
        showConfirmButton: false,
        timer: 1500
      });
    }
  };

  const handleCopyAccountCredentials = async (name: string, email: string, pass: string, roleValue: string) => {
    const roleLabel = roleValue === 'admin' ? 'Admin (TU / Keuangan)' : 'Guru (Tim Observasi)';
    const textToCopy = `*AKUN AKSES SPMB SIT AL-MUBARAK*\n\n` +
      `Nama: ${name || '-'}\n` +
      `Role: ${roleLabel}\n` +
      `Email: ${email}\n` +
      `Password: ${pass}\n\n` +
      `Link Login: https://spmb.sitalmubarak.com/login`;

    const ok = await copyNumberToClipboard(textToCopy);
    if (ok) {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'Info akun lengkap berhasil disalin!',
        showConfirmButton: false,
        timer: 2000
      });
    }
  };

  const handleAddAdmin = async (e: React.FormEvent) => {
    e.preventDefault();
    const accountSnapshot = { ...formData };
    try {
      await libAxios.get('/sanctum/csrf-cookie');
      await libAxios.post('/api/users', formData);
      
      const roleLabel = accountSnapshot.role === 'admin' ? 'Admin (TU / Keuangan)' : 'Guru (Tim Observasi)';
      const shareText = `*AKUN AKSES SPMB SIT AL-MUBARAK*\n\n` +
        `Nama: ${accountSnapshot.name}\n` +
        `Role: ${roleLabel}\n` +
        `Email: ${accountSnapshot.email}\n` +
        `Password: ${accountSnapshot.password}\n\n` +
        `Link Login: https://spmb.sitalmubarak.com/login`;

      void Swal.fire({
        title: 'Akun Berhasil Dibuat!',
        html: `
          <div style="text-align: left; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 14px; margin-top: 10px; font-size: 13px; line-height: 1.6;">
            <div><b>Nama:</b> ${escapeHtml(accountSnapshot.name)}</div>
            <div><b>Role:</b> ${escapeHtml(roleLabel)}</div>
            <div><b>Email:</b> ${escapeHtml(accountSnapshot.email)}</div>
            <div><b>Password:</b> <code style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-family: monospace;">${escapeHtml(accountSnapshot.password)}</code></div>
          </div>
          <p style="font-size: 12px; color: #718096; margin-top: 10px; text-align: left;">Klik tombol di bawah untuk menyalin pesan siap kirim (Email, Password, & Link Login) ke WhatsApp penerima.</p>
        `,
        icon: 'success',
        showCancelButton: true,
        confirmButtonColor: '#1a5f51',
        cancelButtonColor: '#718096',
        confirmButtonText: '📋 Salin Info Akun',
        cancelButtonText: 'Tutup'
      }).then((result) => {
        if (result.isConfirmed) {
          void copyNumberToClipboard(shareText).then(() => {
            Swal.fire({
              toast: true,
              position: 'top-end',
              icon: 'success',
              title: 'Info akun disalin!',
              showConfirmButton: false,
              timer: 1500
            });
          });
        }
      });

      setFormData({ name: '', email: '', password: '', role: 'admin' });
      setShowPassword(false);
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
        await libAxios.post('/api/admin/system-config', { 
          ppdb_status: ppdbStatus, 
          gate_jalur_umum: gateUmum,
          gate_jalur_internal: gateInternal,
          kuota_sd: kuotaSD, 
          kuota_smp_ikhwan: kuotaSmpIkhwan,
          kuota_smp_akhwat: kuotaSmpAkhwat,
          kuota_smp: kuotaSmpIkhwan + kuotaSmpAkhwat
        });
        Swal.fire({ icon: 'success', title: 'Tersimpan!', text: 'Konfigurasi SPMB & pembagian kuota berhasil diperbarui.', timer: 1500, showConfirmButton: false });
        fetchMasterData();
    } catch { Swal.fire('Gagal', 'Terjadi kesalahan sistem saat menyimpan konfigurasi.', 'error'); } 
    finally { setLoadingUmum(false); }
  };

  // === 4. LOGIKA TAB WHITELIST ===
  const handleAddWhitelist = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
        const res = await libAxios.post('/api/admin/whitelists', formWhite);
        const number = res.data?.registration_number;
        setFormWhite({ nama_siswa: '', jenjang: 'SD', jenis_kelamin: 'L', asal_sekolah: '' });
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
    const wb = XLSX.utils.book_new();
    const wsData = [
      ['NO', 'NAMA_SISWA', 'JENJANG', 'JENIS_KELAMIN', 'ASAL_SEKOLAH'],
      [1, 'Ahmad Fauzi', 'SD', 'L', 'TK IT Al-Mubarak'],
      [2, 'Muhammad Rizqi', 'SMP', 'L', 'SDIT Al-Mubarak'],
      [3, 'Siti Aisyah', 'SMP', 'P', 'SD Negeri 01'],
      ['', '', '', '', ''],
      ['* PETUNJUK PENGISIAN:', '', '', '', ''],
      ['1. Kolom JENJANG wajib diisi "SD" (tujuan SDIT) atau "SMP" (tujuan SMPIT).', '', '', '', ''],
      ['2. Kolom JENIS_KELAMIN diisi "L" (Ikhwan / Putra) atau "P" (Akhwat / Putri) - penting untuk pembagian kuota SMPIT.', '', '', '', ''],
      ['3. Kolom ASAL_SEKOLAH bersifat opsional (boleh dikosongkan).', '', '', '', ''],
      ['4. Kolom NO bersifat nomor urut bantuan (opsional).', '', '', '', '']
    ];
    const ws = XLSX.utils.aoa_to_sheet(wsData);
    ws['!cols'] = [
      { wch: 6 },
      { wch: 30 },
      { wch: 15 },
      { wch: 18 },
      { wch: 30 },
    ];
    XLSX.utils.book_append_sheet(wb, ws, 'Template Jalur Internal');
    XLSX.writeFile(wb, 'Template_Jalur_Internal_Al_Mubarak.xlsx');
  };

  // Card-level export — unduh daftar jalur internal langsung ke file .xlsx rapi
  const handleExportWhitelist = () => {
    if (whitelists.length === 0) {
      Swal.fire('Info', 'Belum ada data pendaftar jalur internal untuk diexport.', 'info');
      return;
    }
    const wb = XLSX.utils.book_new();
    const wsData = [
      ['NO', 'NAMA_LENGKAP_SISWA', 'JENJANG', 'GENDER', 'ASAL_SEKOLAH', 'STATUS', 'WAKTU_DIBUAT'],
      ...whitelists.map((w, idx) => [
        idx + 1,
        w.nama_siswa || '',
        w.jenjang ? `${w.jenjang}IT` : '',
        w.jenis_kelamin === 'L' ? 'Ikhwan (L)' : w.jenis_kelamin === 'P' ? 'Akhwat (P)' : '—',
        w.asal_sekolah || '',
        w.is_used ? 'DIPAKAI' : 'TERSEDIA',
        w.created_at ? new Date(w.created_at).toLocaleString('id-ID') : '—',
      ]),
    ];
    const ws = XLSX.utils.aoa_to_sheet(wsData);
    ws['!cols'] = [
      { wch: 6 },
      { wch: 30 },
      { wch: 15 },
      { wch: 15 },
      { wch: 30 },
      { wch: 15 },
      { wch: 22 },
    ];
    XLSX.utils.book_append_sheet(wb, ws, 'Jalur Internal');
    XLSX.writeFile(wb, `Daftar_Jalur_Internal_${new Date().toISOString().slice(0, 10)}.xlsx`);
  };

  const handleFileUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    // Reset input value agar file yang sama bisa dipilih ulang jika perlu
    e.target.value = '';

    Swal.fire({
      title: 'Membaca File...',
      text: file.name,
      allowOutsideClick: false,
      didOpen: () => Swal.showLoading(),
    });

    try {
      const data = await file.arrayBuffer();
      const wb = XLSX.read(data, { type: 'array' });
      const firstSheetName = wb.SheetNames[0];
      const sheet = wb.Sheets[firstSheetName];
      const rows: unknown[][] = XLSX.utils.sheet_to_json(sheet, { header: 1 });

      if (!rows || rows.length <= 1) {
        Swal.fire('File Kosong', 'Tidak ada data siswa ditemukan di dalam file Excel.', 'warning');
        return;
      }

      // Cari baris header
      let headerIdx = -1;
      let colNama = -1;
      let colJenjang = -1;
      let colGender = -1;
      let colAsal = -1;

      for (let i = 0; i < Math.min(5, rows.length); i++) {
        const r = (rows[i] || []).map(c => String(c || '').toLowerCase().trim());
        const idxNama = r.findIndex(c => c.includes('nama'));
        const idxJenjang = r.findIndex(c => c.includes('jenjang') || c.includes('tujuan') || c === 'sd' || c === 'smp');
        if (idxNama !== -1) {
          headerIdx = i;
          colNama = idxNama;
          colJenjang = idxJenjang;
          colGender = r.findIndex(c => c.includes('kelamin') || c.includes('gender') || c.includes('jk') || c.includes('ikhwan'));
          colAsal = r.findIndex(c => c.includes('asal') || c.includes('sekolah'));
          break;
        }
      }

      // Default jika tidak ketemu nama header spesifik
      if (headerIdx === -1) {
        headerIdx = 0;
        if (rows[0] && rows[0].length >= 5) {
          colNama = 1; colJenjang = 2; colGender = 3; colAsal = 4;
        } else if (rows[0] && rows[0].length >= 4) {
          colNama = 1; colJenjang = 2; colGender = -1; colAsal = 3;
        } else {
          colNama = 0; colJenjang = 1; colGender = -1; colAsal = 2;
        }
      }

      const parsedRows: Array<{ nama_siswa: string; jenjang: string; jenis_kelamin?: string; asal_sekolah: string }> = [];

      for (let i = headerIdx + 1; i < rows.length; i++) {
        const r = rows[i];
        if (!r || r.length === 0) continue;
        const rawNama = String(r[colNama] ?? '').trim();
        // Lewati jika baris catatan / petunjuk atau kosong
        if (!rawNama || rawNama.startsWith('*') || rawNama.toLowerCase().includes('petunjuk')) continue;

        const rawJenjang = colJenjang !== -1 ? String(r[colJenjang] ?? '').trim() : '';
        const rawGender = colGender !== -1 ? String(r[colGender] ?? '').trim() : '';
        const rawAsal = colAsal !== -1 ? String(r[colAsal] ?? '').trim() : '';

        parsedRows.push({
          nama_siswa: rawNama,
          jenjang: rawJenjang,
          jenis_kelamin: rawGender,
          asal_sekolah: rawAsal,
        });
      }

      if (parsedRows.length === 0) {
        Swal.fire('Data Tidak Valid', 'Tidak ada baris data siswa yang terbaca. Pastikan format kolom sesuai template.', 'warning');
        return;
      }

      // Kirim parsedRows ke backend
      Swal.fire({
        title: 'Mengimpor Data...',
        text: `Memproses ${parsedRows.length} data siswa...`,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading(),
      });

      await libAxios.get('/sanctum/csrf-cookie');
      const res = await libAxios.post('/api/admin/whitelists/import', { rows: parsedRows });

      Swal.fire({
        icon: 'success',
        title: 'Import Selesai!',
        text: res.data.message || `${res.data.total_sukses || parsedRows.length} data berhasil diimpor.`,
      });

      fetchMasterData();
    } catch (err: unknown) {
      console.error('Gagal import file', err);
      const errMsg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || 'Format file Excel tidak sesuai standar.';
      Swal.fire('Gagal Import', errMsg, 'error');
    }
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
                        <div className={styles.adminEmailRow}>
                          <span className={styles.adminEmail}>{u.email}</span>
                          <button
                            type="button"
                            title="Salin Email"
                            className={styles.copyMiniBtn}
                            onClick={() => handleCopyText(u.email, 'Email')}
                          >
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                              <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                              <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                          </button>
                        </div>
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
                <input
                  className={styles.formInput}
                  placeholder="Nama Lengkap"
                  required
                  value={formData.name}
                  onChange={e => setFormData({ ...formData, name: e.target.value })}
                />

                {/* Input Email dengan tombol Salin */}
                <div className={styles.inputWithAction}>
                  <input
                    className={styles.formInput}
                    type="email"
                    placeholder="Email Login"
                    required
                    value={formData.email}
                    onChange={e => setFormData({ ...formData, email: e.target.value })}
                  />
                  {formData.email && (
                    <div className={styles.inputActionGroup}>
                      <button
                        type="button"
                        className={styles.inputActionIconBtn}
                        onClick={() => handleCopyText(formData.email, 'Email')}
                        title="Salin Email"
                      >
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                          <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                          <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                        </svg>
                      </button>
                    </div>
                  )}
                </div>

                {/* Input Password dengan tombol Lihat/Sembunyikan & Salin */}
                <div className={styles.inputWithAction}>
                  <input
                    className={styles.formInput}
                    type={showPassword ? 'text' : 'password'}
                    placeholder="Password Login"
                    required
                    value={formData.password}
                    onChange={e => setFormData({ ...formData, password: e.target.value })}
                  />
                  <div className={styles.inputActionGroup}>
                    {formData.password && (
                      <button
                        type="button"
                        className={styles.inputActionIconBtn}
                        onClick={() => handleCopyText(formData.password, 'Password')}
                        title="Salin Password"
                      >
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                          <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                          <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                        </svg>
                      </button>
                    )}
                    <button
                      type="button"
                      className={styles.inputActionIconBtn}
                      onClick={() => setShowPassword(!showPassword)}
                      title={showPassword ? 'Sembunyikan Password' : 'Lihat Password'}
                    >
                      {showPassword ? (
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                          <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                          <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                      ) : (
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                          <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                          <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                      )}
                    </button>
                  </div>
                </div>

                <select className={`${styles.formInput} ${styles.selectInput}`} value={formData.role} onChange={e => setFormData({...formData, role: e.target.value})}>
                  <option value="admin">Admin (TU / Keuangan)</option>
                  <option value="guru">Guru (Tim Observasi)</option>
                </select>

                {/* Banner Cepat Salin Akun Lengkap Saat Sedang Diketik */}
                {formData.email && formData.password && (
                  <div className={styles.copyAccountBanner}>
                    <span className={styles.copyAccountText}>Siap dibagikan ke penerima</span>
                    <button
                      type="button"
                      className={styles.copyAccountBtn}
                      onClick={() => handleCopyAccountCredentials(formData.name, formData.email, formData.password, formData.role)}
                    >
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                      </svg>
                      Salin Lengkap
                    </button>
                  </div>
                )}

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
        <div className={styles.card} style={{ maxWidth: '680px', margin: '0 auto' }}>
          <h3 className={styles.cardTitle} style={{ marginBottom: '20px' }}>Kontrol Utama Aplikasi SPMB</h3>
          <form onSubmit={handleSaveUmumSettings} className={styles.adminForm}>
            {/* SAKLAR GLOBAL */}
            <div style={{ background: '#f8fafc', padding: '16px 20px', borderRadius: '12px', border: '1px solid #edf2f7' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <div>
                  <label style={{ fontSize: '14px', fontWeight: 800, color: '#2d3748', display: 'block' }}>Master Gerbang PPDB</label>
                  <p style={{ fontSize: '12px', color: '#718096', margin: '2px 0 0 0' }}>Saklar utama seluruh operasional pendaftaran SPMB.</p>
                </div>
                <div style={{ display: 'flex', gap: '10px' }}>
                    <label className={`${styles.radioLabel} ${ppdbStatus === 'BUKA' ? styles.radioActiveSuccess : ''}`} style={{ padding: '6px 14px', fontSize: '12px' }}>
                        <input type="radio" value="BUKA" checked={ppdbStatus === 'BUKA'} onChange={(e) => setPpdbStatus(e.target.value as PpdbStatus)} /> BUKA
                    </label>
                    <label className={`${styles.radioLabel} ${ppdbStatus === 'TUTUP' ? styles.radioActiveDanger : ''}`} style={{ padding: '6px 14px', fontSize: '12px' }}>
                        <input type="radio" value="TUTUP" checked={ppdbStatus === 'TUTUP'} onChange={(e) => setPpdbStatus(e.target.value as PpdbStatus)} /> TUTUP
                    </label>
                </div>
              </div>
            </div>

            {/* SAKLAR KHUSUS JALUR */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '15px' }}>
              <div style={{ background: '#f0fdf4', padding: '16px', borderRadius: '12px', border: '1px solid #bbf7d0' }}>
                <span style={{ fontSize: '13px', fontWeight: 800, color: '#166534', display: 'block', marginBottom: '4px' }}>Jalur Internal (SD &amp; SMP)</span>
                <p style={{ fontSize: '11px', color: '#15803d', margin: '0 0 12px 0', lineHeight: 1.4 }}>Pendaftaran menggunakan nomor registrasi internal 10 digit.</p>
                <div style={{ display: 'flex', gap: '8px' }}>
                  <label className={`${styles.radioLabel} ${gateInternal === 'BUKA' ? styles.radioActiveSuccess : ''}`} style={{ padding: '5px 12px', fontSize: '11px', flex: 1, textAlign: 'center' }}>
                      <input type="radio" value="BUKA" checked={gateInternal === 'BUKA'} onChange={(e) => setGateInternal(e.target.value as PpdbStatus)} /> DIBUKA
                  </label>
                  <label className={`${styles.radioLabel} ${gateInternal === 'TUTUP' ? styles.radioActiveDanger : ''}`} style={{ padding: '5px 12px', fontSize: '11px', flex: 1, textAlign: 'center' }}>
                      <input type="radio" value="TUTUP" checked={gateInternal === 'TUTUP'} onChange={(e) => setGateInternal(e.target.value as PpdbStatus)} /> DITUTUP
                  </label>
                </div>
              </div>

              <div style={{ background: '#fef2f2', padding: '16px', borderRadius: '12px', border: '1px solid #fecaca' }}>
                <span style={{ fontSize: '13px', fontWeight: 800, color: '#991b1b', display: 'block', marginBottom: '4px' }}>Jalur Reguler / Umum</span>
                <p style={{ fontSize: '11px', color: '#b91c1c', margin: '0 0 12px 0', lineHeight: 1.4 }}>Pendaftaran mandiri menggunakan token reguler/email.</p>
                <div style={{ display: 'flex', gap: '8px' }}>
                  <label className={`${styles.radioLabel} ${gateUmum === 'BUKA' ? styles.radioActiveSuccess : ''}`} style={{ padding: '5px 12px', fontSize: '11px', flex: 1, textAlign: 'center' }}>
                      <input type="radio" value="BUKA" checked={gateUmum === 'BUKA'} onChange={(e) => setGateUmum(e.target.value as PpdbStatus)} /> DIBUKA
                  </label>
                  <label className={`${styles.radioLabel} ${gateUmum === 'TUTUP' ? styles.radioActiveDanger : ''}`} style={{ padding: '5px 12px', fontSize: '11px', flex: 1, textAlign: 'center' }}>
                      <input type="radio" value="TUTUP" checked={gateUmum === 'TUTUP'} onChange={(e) => setGateUmum(e.target.value as PpdbStatus)} /> DITUTUP
                  </label>
                </div>
              </div>
            </div>

            {/* PENGATURAN KUOTA */}
            <div style={{ borderTop: '1px solid #edf2f7', paddingTop: '15px' }}>
              <h4 style={{ fontSize: '13px', fontWeight: 800, color: '#1a5f51', marginBottom: '12px', textTransform: 'uppercase', letterSpacing: '0.5px' }}>Kapasitas &amp; Kuota Siswa</h4>

              {/* KUOTA SD */}
              <div style={{ background: '#f8fafc', padding: '15px', borderRadius: '12px', border: '1px solid #e2e8f0', marginBottom: '15px' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
                  <label style={{ fontSize: '13px', fontWeight: 700, color: '#2d3748' }}>Kuota SDIT Al-Mubarak (Global)</label>
                  <span style={{ fontSize: '11.5px', color: '#718096' }}>Terpakai: <b>{statsUmum.kuota_sd_terpakai ?? 0}</b> | Sisa: <b>{statsUmum.kuota_sd_sisa ?? kuotaSD}</b></span>
                </div>
                <input className={styles.formInput} type="number" required min="1" value={kuotaSD} onChange={(e) => setKuotaSD(parseInt(e.target.value) || 0)} />
              </div>

              {/* KUOTA SMP DENGAN GENDER */}
              <div style={{ background: '#f8fafc', padding: '15px', borderRadius: '12px', border: '1px solid #e2e8f0' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }}>
                  <div>
                    <label style={{ fontSize: '13px', fontWeight: 700, color: '#2d3748', display: 'block' }}>Kuota SMPIT Al-Mubarak (Per Gender)</label>
                    <span style={{ fontSize: '11.5px', color: '#718096' }}>Total Target: <b>{kuotaSmpIkhwan + kuotaSmpAkhwat} Kursi</b></span>
                  </div>
                  <span style={{ fontSize: '11.5px', color: '#718096' }}>Terpakai: <b>{statsUmum.kuota_smp_terpakai ?? 0}</b> | Sisa: <b>{statsUmum.kuota_smp_sisa ?? (kuotaSmpIkhwan + kuotaSmpAkhwat)}</b></span>
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '15px' }}>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '6px' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '12px' }}>
                      <span style={{ fontWeight: 600, color: '#2b6cb0' }}>Ikhwan (Putra)</span>
                      <span style={{ color: '#718096', fontSize: '11px' }}>Sisa: {statsUmum.kuota_smp_ikhwan_sisa ?? kuotaSmpIkhwan}</span>
                    </div>
                    <input className={styles.formInput} type="number" required min="0" value={kuotaSmpIkhwan} onChange={(e) => setKuotaSmpIkhwan(parseInt(e.target.value) || 0)} />
                  </div>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '6px' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '12px' }}>
                      <span style={{ fontWeight: 600, color: '#97266d' }}>Akhwat (Putri)</span>
                      <span style={{ color: '#718096', fontSize: '11px' }}>Sisa: {statsUmum.kuota_smp_akhwat_sisa ?? kuotaSmpAkhwat}</span>
                    </div>
                    <input className={styles.formInput} type="number" required min="0" value={kuotaSmpAkhwat} onChange={(e) => setKuotaSmpAkhwat(parseInt(e.target.value) || 0)} />
                  </div>
                </div>
              </div>
            </div>

            <button type="submit" className={styles.submitBtn} disabled={loadingUmum} style={{ marginTop: '10px' }}>Terapkan Konfigurasi</button>
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
                <h3 className={styles.cardTitle}>Upload Massal (Excel / XLSX)</h3>
                <p style={{ fontSize: '12px', color: '#718096', margin: '6px 0 14px 0', lineHeight: 1.5 }}>
                  Unduh template Excel resmi untuk mendaftarkan siswa jalur internal (mendukung jenjang SD &amp; SMP sekaligus).
                </p>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                    <button type="button" onClick={downloadTemplate} className={styles.btnOutline} style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '8px' }}>
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                      Download Template Excel (.xlsx)
                    </button>
                    <input type="file" accept=".xlsx, .xls, .csv" ref={fileInputRef} onChange={handleFileUpload} style={{ display: 'none' }} />
                    <button type="button" onClick={() => fileInputRef.current?.click()} className={styles.submitBtn} style={{background: '#2b6cb0', width: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '8px'}}>
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                      Import File Excel (.xlsx)
                    </button>
                </div>
            </div>

            <div className={styles.card}>
                <h3 className={styles.cardTitle}>Input Manual 1 Siswa</h3>
                <form onSubmit={handleAddWhitelist} className={styles.adminForm}>
                    <input className={styles.formInput} type="text" placeholder="Nama Lengkap Anak" required value={formWhite.nama_siswa} onChange={(e) => setFormWhite({...formWhite, nama_siswa: e.target.value})} />
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
                      <select className={`${styles.formInput} ${styles.selectInput}`} value={formWhite.jenjang} onChange={(e) => setFormWhite({...formWhite, jenjang: e.target.value as Jenjang})}>
                          <option value="SD">SD (SDIT)</option>
                          <option value="SMP">SMP (SMPIT)</option>
                      </select>
                      <select className={`${styles.formInput} ${styles.selectInput}`} value={formWhite.jenis_kelamin} onChange={(e) => setFormWhite({...formWhite, jenis_kelamin: e.target.value as 'L' | 'P'})}>
                          <option value="L">Ikhwan (L)</option>
                          <option value="P">Akhwat (P)</option>
                      </select>
                    </div>
                    <input className={styles.formInput} type="text" placeholder="Asal Sekolah (opsional)" value={formWhite.asal_sekolah} onChange={(e) => setFormWhite({...formWhite, asal_sekolah: e.target.value})} />
                    <button type="submit" className={styles.submitBtn}>Simpan Data Manual</button>
                </form>
            </div>
          </div>

          <div className={styles.leftCol}>
            <div className={styles.card}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid #edf2f7', paddingBottom: '12px' }}>
                    <h3 className={styles.cardTitle} style={{ borderBottom: 'none', paddingBottom: 0 }}>Daftar Jalur Internal ({whitelists.length} Data)</h3>
                    <button type="button" className={styles.submitBtn} style={{ padding: '6px 14px', fontSize: '11px', width: 'auto', whiteSpace: 'nowrap', display: 'flex', alignItems: 'center', gap: '6px' }} onClick={handleExportWhitelist}>
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                      Export Excel (.xlsx)
                    </button>
                </div>
                <div style={{ overflowX: 'auto', marginTop: '15px' }}>
                    <table className={styles.adminTable}>
                        <thead>
                            <tr className={styles.tableHeader}>
                                <th className={styles.thPad}>IDENTITAS</th>
                                <th>JENJANG</th>
                                <th>GENDER</th>
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
                                        <span style={{ fontSize: '11.5px', fontWeight: 600, color: w.jenis_kelamin === 'L' ? '#2b6cb0' : w.jenis_kelamin === 'P' ? '#97266d' : '#a0aec0' }}>
                                            {w.jenis_kelamin === 'L' ? 'Ikhwan' : w.jenis_kelamin === 'P' ? 'Akhwat' : '—'}
                                        </span>
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