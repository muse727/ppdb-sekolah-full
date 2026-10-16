'use client';

import { useState, useEffect } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './biaya.module.css';

interface PricingItem {
  id: string;
  name: string;
  quantity: number;
  unit_amount: number;
}

interface PricingRevision {
  id: number;
  revision: number;
  jenjang: 'SD' | 'SMP';
  subtotal: number;
  discount_nominal: number;
  total: number;
  items: Array<{
    id: string;
    name: string;
    quantity: number;
    unit_amount: number;
    line_total: number;
  }>;
  updated_at: string;
}

const formatRupiah = (val: number) => {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(val || 0);
};

export default function BiayaDaftarUlangPage() {
  const [jenjang, setJenjang] = useState<'SD' | 'SMP'>('SD');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [revisionData, setRevisionData] = useState<PricingRevision | null>(null);

  const [items, setItems] = useState<PricingItem[]>([]);
  const [discount, setDiscount] = useState<number>(0);

  const fetchPricing = async (targetJenjang: 'SD' | 'SMP') => {
    setLoading(true);
    try {
      const res = await libAxios.get<PricingRevision | null>(
        `/api/admin/daftar-ulang/pricing/default/${targetJenjang}`
      );
      if (res.data) {
        setRevisionData(res.data);
        setItems(
          res.data.items?.map((it) => ({
            id: it.id,
            name: it.name,
            quantity: Number(it.quantity) || 1,
            unit_amount: Number(it.unit_amount) || 0,
          })) || []
        );
        setDiscount(Number(res.data.discount_nominal) || 0);
      } else {
        setRevisionData(null);
        // Default template komponen biaya jika belum diset
        setItems([
          { id: 'gedung', name: 'Infaq Pengembangan Gedung & Sarana', quantity: 1, unit_amount: 5000000 },
          { id: 'seragam', name: 'Paket Seragam Sekolah Lengkap', quantity: 1, unit_amount: 1500000 },
          { id: 'buku', name: 'Paket Modul & Buku Pembelajaran (1 Tahun)', quantity: 1, unit_amount: 1200000 },
          { id: 'spp_juli', name: 'SPP Bulan Pertama (Juli)', quantity: 1, unit_amount: 600000 },
          { id: 'kegiatan', name: 'Dana Kegiatan Siswa Tahunan', quantity: 1, unit_amount: 1000000 },
        ]);
        setDiscount(0);
      }
    } catch (err) {
      console.error('Gagal mengambil rincian biaya', err);
      Swal.fire('Error', 'Gagal memuat rincian biaya daftar ulang.', 'error');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchPricing(jenjang);
  }, [jenjang]);

  const handleAddItem = () => {
    const newId = `item_${Date.now()}_${Math.floor(Math.random() * 1000)}`;
    setItems((prev) => [
      ...prev,
      { id: newId, name: '', quantity: 1, unit_amount: 0 },
    ]);
  };

  const handleUpdateItem = (
    index: number,
    field: keyof PricingItem,
    val: string | number
  ) => {
    setItems((prev) => {
      const updated = [...prev];
      if (field === 'quantity' || field === 'unit_amount') {
        const num = Math.max(0, parseInt(String(val).replace(/\D/g, '') || '0', 10));
        updated[index] = { ...updated[index], [field]: num };
      } else {
        updated[index] = { ...updated[index], [field]: val };
      }
      return updated;
    });
  };

  const handleDeleteItem = (index: number) => {
    if (items.length <= 1) {
      Swal.fire('Peringatan', 'Minimal harus ada 1 rincian biaya.', 'warning');
      return;
    }
    setItems((prev) => prev.filter((_, i) => i !== index));
  };

  // Kalkulasi live
  const subtotal = items.reduce(
    (acc, it) => acc + (Number(it.quantity) || 0) * (Number(it.unit_amount) || 0),
    0
  );
  const validDiscount = Math.min(subtotal, Math.max(0, Number(discount) || 0));
  const total = subtotal - validDiscount;

  const handleSave = async () => {
    // Validasi form
    for (let i = 0; i < items.length; i++) {
      if (!items[i].name.trim()) {
        Swal.fire('Validasi Gagal', `Nama komponen biaya pada baris ke-${i + 1} tidak boleh kosong.`, 'warning');
        return;
      }
      if (items[i].quantity <= 0) {
        Swal.fire('Validasi Gagal', `Jumlah (Qty) pada baris ke-${i + 1} harus lebih dari 0.`, 'warning');
        return;
      }
    }

    const payload = {
      items: items.map((it) => ({
        id: it.id || `item_${Date.now()}`,
        name: it.name.trim(),
        quantity: Math.floor(it.quantity),
        unit_amount: Math.floor(it.unit_amount),
      })),
      discount_nominal: Math.floor(validDiscount),
    };

    setSaving(true);
    try {
      await libAxios.get('/sanctum/csrf-cookie');
      const res = await libAxios.put(`/api/admin/daftar-ulang/pricing/default/${jenjang}`, payload);
      setRevisionData(res.data);
      Swal.fire({
        icon: 'success',
        title: 'Berhasil Disimpan!',
        text: `Rincian biaya daftar ulang ${jenjang}IT berhasil diperbarui ke Revisi #${res.data.revision}.`,
      });
    } catch (err: unknown) {
      console.error('Gagal menyimpan rincian biaya', err);
      const errMsg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message || 'Gagal menyimpan konfigurasi biaya.';
      Swal.fire('Gagal Menyimpan', errMsg, 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className={styles.container}>
      {/* Header */}
      <div className={styles.header}>
        <div className={styles.titleArea}>
          <h1>
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
              <line x1="12" y1="1" x2="12" y2="23"></line>
              <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
            </svg>
            Biaya & Rincian Daftar Ulang
          </h1>
          <p>Atur komponen rincian biaya resmi daftar ulang untuk siswa yang dinyatakan lulus seleksi.</p>
        </div>
      </div>

      {/* Switch Jenjang */}
      <div className={styles.jenjangSwitch}>
        <button
          type="button"
          className={`${styles.jenjangBtn} ${jenjang === 'SD' ? styles.jenjangActive : ''}`}
          onClick={() => setJenjang('SD')}
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
          Jenjang SDIT Al-Mubarak
        </button>
        <button
          type="button"
          className={`${styles.jenjangBtn} ${jenjang === 'SMP' ? styles.jenjangActive : ''}`}
          onClick={() => setJenjang('SMP')}
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
          Jenjang SMPIT Al-Mubarak
        </button>
      </div>

      {loading ? (
        <div className={styles.loading}>Memuat Konfigurasi Biaya {jenjang}IT...</div>
      ) : (
        <div className={styles.card}>
          <div className={styles.cardHeader}>
            <div>
              <h2>Rincian Komponen Biaya ({jenjang}IT)</h2>
              <p>Komponen ini akan otomatis terbit menjadi invoice tagihan saat orang tua membuka formulir daftar ulang.</p>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              {revisionData && (
                <span className={styles.revisionBadge}>
                  Revisi #{revisionData.revision} • Terkunci Server
                </span>
              )}
              <button type="button" className={styles.addItemBtn} onClick={handleAddItem}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Tambah Komponen
              </button>
            </div>
          </div>

          {/* Table Items */}
          <div className={styles.itemsTableWrapper}>
            <table className={styles.itemsTable}>
              <thead>
                <tr>
                  <th style={{ width: '4%' }}>No</th>
                  <th style={{ width: '46%' }}>Nama Komponen / Rincian Biaya</th>
                  <th style={{ width: '12%', textAlign: 'center' }}>Jumlah (Qty)</th>
                  <th style={{ width: '22%' }}>Biaya Satuan (Rp)</th>
                  <th style={{ width: '12%', textAlign: 'right' }}>Subtotal</th>
                  <th style={{ width: '4%', textAlign: 'center' }}></th>
                </tr>
              </thead>
              <tbody>
                {items.map((item, idx) => {
                  const lineTotal = (Number(item.quantity) || 0) * (Number(item.unit_amount) || 0);
                  return (
                    <tr key={item.id || idx}>
                      <td style={{ color: '#94a3b8', fontWeight: 700, fontSize: '13px' }}>{idx + 1}</td>
                      <td>
                        <input
                          type="text"
                          className={styles.inputField}
                          placeholder="Misal: Uang Pangkal / Seragam Sekolah / SPP"
                          value={item.name}
                          onChange={(e) => handleUpdateItem(idx, 'name', e.target.value)}
                        />
                      </td>
                      <td>
                        <input
                          type="number"
                          min="1"
                          style={{ textAlign: 'center' }}
                          className={styles.inputField}
                          value={item.quantity}
                          onChange={(e) => handleUpdateItem(idx, 'quantity', e.target.value)}
                        />
                      </td>
                      <td>
                        <input
                          type="number"
                          min="0"
                          step="10000"
                          className={styles.inputField}
                          value={item.unit_amount}
                          onChange={(e) => handleUpdateItem(idx, 'unit_amount', e.target.value)}
                        />
                      </td>
                      <td style={{ textAlign: 'right', fontWeight: 800, color: '#1e293b', fontSize: '14px' }}>
                        {formatRupiah(lineTotal)}
                      </td>
                      <td style={{ textAlign: 'center' }}>
                        <button
                          type="button"
                          className={styles.deleteItemBtn}
                          onClick={() => handleDeleteItem(idx)}
                          title="Hapus Komponen"
                          disabled={items.length <= 1}
                        >
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {/* Ringkasan & Diskon */}
          <div className={styles.summarySection}>
            <div className={styles.summaryRow}>
              <span>Subtotal Biaya</span>
              <span style={{ fontWeight: 800, color: '#1e293b' }}>{formatRupiah(subtotal)}</span>
            </div>
            <div className={styles.summaryRow}>
              <span>Potongan / Diskon Umum (Rp)</span>
              <div style={{ width: '180px' }}>
                <input
                  type="number"
                  min="0"
                  max={subtotal}
                  step="10000"
                  className={styles.inputField}
                  style={{ textAlign: 'right' }}
                  value={discount}
                  onChange={(e) => setDiscount(Math.max(0, parseInt(e.target.value || '0', 10)))}
                />
              </div>
            </div>
            <div className={styles.summaryTotalRow}>
              <span>Total Tagihan Daftar Ulang</span>
              <span>{formatRupiah(total)}</span>
            </div>
          </div>

          {/* Action Bar */}
          <div className={styles.actionsBar}>
            <button
              type="button"
              className={styles.saveBtn}
              onClick={handleSave}
              disabled={saving}
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
              {saving ? 'Menyimpan ke Server...' : `Simpan Rincian Biaya ${jenjang}IT`}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
