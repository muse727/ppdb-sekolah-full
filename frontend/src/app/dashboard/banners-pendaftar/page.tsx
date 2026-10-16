// src/app/dashboard/banners-pendaftar/page.tsx
'use client';

import { useCallback, useEffect, useState } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './banners-pendaftar.module.css';

interface PendaftarBannerAdmin {
  id: number;
  title: string;
  description: string | null;
  media_type: 'image' | 'video';
  image_url: string;
  has_image: boolean;
  cta_text: string | null;
  cta_url: string | null;
  sort_order: number;
  is_active: boolean;
  starts_at: string | null;
  ends_at: string | null;
}

interface BannerPendaftarForm {
  title: string;
  cta_url: string;
  sort_order: string;
  is_active: boolean;
  starts_at: string;
  ends_at: string;
}

const EMPTY_FORM: BannerPendaftarForm = {
  title: '',
  cta_url: '',
  sort_order: '0',
  is_active: true,
  starts_at: '',
  ends_at: '',
};

function toLocalInput(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function toIso(local: string): string | null {
  return local ? new Date(local).toISOString() : null;
}

export default function BannersPendaftarPage() {
  const apiBase = (process.env.NEXT_PUBLIC_API_URL || '').replace(/\/$/, '');

  const [banners, setBanners] = useState<PendaftarBannerAdmin[]>([]);
  const [loading, setLoading] = useState(true);

  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<PendaftarBannerAdmin | null>(null);
  const [form, setForm] = useState<BannerPendaftarForm>(EMPTY_FORM);
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [previewSrc, setPreviewSrc] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [reordering, setReordering] = useState(false);

  const load = useCallback(async () => {
    try {
      const res = await libAxios.get('/api/admin/banners?target=pendaftar');
      setBanners(res.data);
    } catch {
      Swal.fire({ icon: 'error', title: 'Gagal', text: 'Tidak dapat memuat banner pendaftar.', confirmButtonColor: '#1a5f51' });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (!imageFile) {
      setPreviewSrc(null);
      return;
    }
    const url = URL.createObjectURL(imageFile);
    setPreviewSrc(url);
    return () => URL.revokeObjectURL(url);
  }, [imageFile]);

  const openCreate = () => {
    setEditing(null);
    setForm(EMPTY_FORM);
    setImageFile(null);
    setShowForm(true);
  };

  const openEdit = (banner: PendaftarBannerAdmin) => {
    setEditing(banner);
    setForm({
      title: banner.title,
      cta_url: banner.cta_url ?? '',
      sort_order: String(banner.sort_order),
      is_active: banner.is_active,
      starts_at: toLocalInput(banner.starts_at),
      ends_at: toLocalInput(banner.ends_at),
    });
    setImageFile(null);
    setShowForm(true);
  };

  const closeForm = () => {
    setShowForm(false);
    setEditing(null);
    setImageFile(null);
  };

  const fieldErrorText = (error: unknown): string => {
    if (typeof error === 'object' && error !== null && 'response' in error) {
      const res = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response;
      const first = res?.data?.errors ? Object.values(res.data.errors)[0]?.[0] : null;
      return first ?? res?.data?.message ?? 'Terjadi kesalahan. Periksa berkas banner Anda.';
    }
    return 'Tidak dapat terhubung ke server.';
  };

  const handleSubmit = async () => {
    if (!form.title.trim()) {
      Swal.fire({ icon: 'warning', title: 'Judul Wajib', text: 'Nama / judul banner wajib diisi.', confirmButtonColor: '#1a5f51' });
      return;
    }

    if (!editing && !imageFile) {
      Swal.fire({ icon: 'warning', title: 'Gambar Wajib', text: 'Pilih file gambar banner mobile terlebih dahulu.', confirmButtonColor: '#1a5f51' });
      return;
    }

    setSaving(true);
    try {
      const fd = new FormData();
      fd.append('target', 'pendaftar');
      fd.append('title', form.title);
      fd.append('cta_url', form.cta_url);
      fd.append('sort_order', form.sort_order || '0');
      fd.append('is_active', form.is_active ? '1' : '0');

      const starts = toIso(form.starts_at);
      const ends = toIso(form.ends_at);
      if (starts) fd.append('starts_at', starts);
      if (ends) fd.append('ends_at', ends);
      if (imageFile) fd.append('image', imageFile);

      if (editing) {
        await libAxios.post(`/api/admin/banners/${editing.id}`, fd);
      } else {
        await libAxios.post('/api/admin/banners', fd);
      }

      await Swal.fire({
        icon: 'success',
        title: 'Tersimpan',
        text: editing ? 'Banner pendaftar berhasil diperbarui.' : 'Banner pendaftar berhasil ditambahkan.',
        confirmButtonColor: '#1a5f51',
        timer: 1800,
      });
      closeForm();
      await load();
    } catch (error) {
      Swal.fire({ icon: 'error', title: 'Gagal Menyimpan', text: fieldErrorText(error), confirmButtonColor: '#1a5f51' });
    } finally {
      setSaving(false);
    }
  };

  const handleToggle = async (banner: PendaftarBannerAdmin) => {
    try {
      const res = await libAxios.patch(`/api/admin/banners/${banner.id}/status`, { is_active: !banner.is_active });
      setBanners(prev => prev.map(b => (b.id === banner.id ? { ...b, is_active: res.data.is_active } : b)));
    } catch (error) {
      Swal.fire({ icon: 'error', title: 'Gagal', text: fieldErrorText(error), confirmButtonColor: '#1a5f51' });
    }
  };

  const handleDelete = async (banner: PendaftarBannerAdmin) => {
    const result = await Swal.fire({
      icon: 'warning',
      title: 'Hapus Banner Pendaftar?',
      text: `Banner "${banner.title}" akan dihapus permanen dari aplikasi pendaftar.`,
      showCancelButton: true,
      confirmButtonColor: '#b91c1c',
      cancelButtonColor: '#64748b',
      confirmButtonText: 'Ya, Hapus',
      cancelButtonText: 'Batal',
    });
    if (!result.isConfirmed) return;

    try {
      await libAxios.delete(`/api/admin/banners/${banner.id}`);
      setBanners(prev => prev.filter(b => b.id !== banner.id));
      Swal.fire({ icon: 'success', title: 'Terhapus', confirmButtonColor: '#1a5f51', timer: 1200 });
    } catch (error) {
      Swal.fire({ icon: 'error', title: 'Gagal Menghapus', text: fieldErrorText(error), confirmButtonColor: '#1a5f51' });
    }
  };

  const move = async (fromIdx: number, delta: number) => {
    const target = fromIdx + delta;
    if (target < 0 || target >= banners.length || reordering) return;
    const next = [...banners];
    const [moved] = next.splice(fromIdx, 1);
    next.splice(target, 0, moved);
    setBanners(next);
    setReordering(true);
    try {
      await libAxios.post('/api/admin/banners/reorder', { ids: next.map(b => b.id) });
    } catch {
      await load();
      Swal.fire({ icon: 'error', title: 'Gagal Mengurutkan', confirmButtonColor: '#1a5f51' });
    } finally {
      setReordering(false);
    }
  };

  const previewMediaSrc = previewSrc ?? (editing?.has_image ? `${apiBase}${editing.image_url}` : null);

  return (
    <div className={styles.container}>
      <header className={styles.header}>
        <div className={styles.headerTitle}>
          <h1>Banner Dashboard Pendaftar</h1>
          <p>
            Kelola banner promosi dan pengumuman visual di halaman pendaftar mobile. Cukup unggah gambar banner dengan ukuran proporsional (ala aplikasi e-commerce / superapp).
          </p>
        </div>
        {!showForm && (
          <button className={styles.btnPrimary} onClick={openCreate}>
            + Tambah Banner Pendaftar
          </button>
        )}
      </header>

      {showForm && (
        <section className={styles.formCard}>
          <div className={styles.formHeader}>
            <div>
              <h2>{editing ? 'Edit Banner Pendaftar' : 'Tambah Banner Mobile Pendaftar'}</h2>
              <p>Unggah materi banner visual yang akan langsung tampil di beranda pendaftar.</p>
            </div>
            <button className={styles.btnSecondary} onClick={closeForm} disabled={saving}>
              Tutup Form
            </button>
          </div>

          <div className={styles.formSectionTitle}>Informasi Banner</div>
          <div className={styles.formGrid}>
            <div>
              <label className={styles.label} htmlFor="banner-title">
                Nama / Judul Banner <small>(untuk identifikasi admin & teks alt)</small>
              </label>
              <input
                id="banner-title"
                className={styles.input}
                maxLength={120}
                placeholder="Contoh: Promo Gelombang 1 / Ujian CBT Akbar"
                value={form.title}
                onChange={e => setForm(f => ({ ...f, title: e.target.value }))}
              />
            </div>

            <div>
              <label className={styles.label} htmlFor="banner-cta-url">
                Tautan Klik Banner <small>(opsional, saat banner diklik)</small>
              </label>
              <input
                id="banner-cta-url"
                className={styles.input}
                maxLength={255}
                placeholder="Contoh: /pendaftar/biodata atau https://wa.me/..."
                value={form.cta_url}
                onChange={e => setForm(f => ({ ...f, cta_url: e.target.value }))}
              />
            </div>

            <div>
              <label className={styles.label} htmlFor="banner-order">
                Urutan Tampil <small>(0 = slide paling awal)</small>
              </label>
              <input
                id="banner-order"
                className={styles.input}
                type="number"
                min={0}
                max={65535}
                value={form.sort_order}
                onChange={e => setForm(f => ({ ...f, sort_order: e.target.value }))}
              />
            </div>

            <div>
              <label className={styles.label}>Status Penayangan</label>
              <div className={styles.checkboxWrapper}>
                <label className={styles.checkboxLabel}>
                  <input
                    type="checkbox"
                    checked={form.is_active}
                    onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))}
                  />
                  <div>
                    <strong>Aktifkan Banner</strong>
                    <span>Banner akan langsung tampil di carousel dashboard pendaftar</span>
                  </div>
                </label>
              </div>
            </div>

            <div>
              <label className={styles.label} htmlFor="banner-start">
                Mulai Tayang <small>(opsional)</small>
              </label>
              <input
                id="banner-start"
                className={styles.input}
                type="datetime-local"
                value={form.starts_at}
                onChange={e => setForm(f => ({ ...f, starts_at: e.target.value }))}
              />
            </div>

            <div>
              <label className={styles.label} htmlFor="banner-end">
                Selesai Tayang <small>(opsional)</small>
              </label>
              <input
                id="banner-end"
                className={styles.input}
                type="datetime-local"
                value={form.ends_at}
                onChange={e => setForm(f => ({ ...f, ends_at: e.target.value }))}
              />
            </div>
          </div>

          <div className={styles.formSectionTitle}>Upload Gambar Banner Mobile</div>
          <div className={styles.formGrid}>
            <div className={styles.fieldFull}>
              <div className={styles.uploadArea}>
                <input
                  id="banner-media"
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  onChange={e => {
                    const f = e.target.files?.[0] ?? null;
                    setImageFile(f);
                  }}
                />
                <div className={styles.uploadContent}>
                  <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                    <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                    <circle cx="9" cy="9" r="2" />
                    <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                  </svg>
                  <div className={styles.uploadText}>
                    <strong>{imageFile ? imageFile.name : 'Pilih file gambar banner atau seret ke sini'}</strong>
                    <span>
                      Rekomendasi rasio ~ <b>2.4 : 1</b> (contoh ukuran: <b>720 × 300 px</b> atau <b>600 × 250 px</b>). Format: JPG, PNG, WEBP (maks 10 MB).
                    </span>
                  </div>
                </div>
              </div>

              <div className={styles.previewSection}>
                <div className={styles.previewHeader}>
                  <span>Simulasi Tampilan di Layar HP Pendaftar</span>
                  <small>Rasio 2.4 : 1 Mobile Card</small>
                </div>

                <div className={styles.mobileSimulatorFrame}>
                  <div className={styles.mobileBannerCard}>
                    {previewMediaSrc ? (
                      /* eslint-disable-next-line @next/next/no-img-element */
                      <img src={previewMediaSrc} alt="Pratinjau Mobile Banner" />
                    ) : (
                      <div className={styles.previewEmpty}>
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                          <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                          <circle cx="9" cy="9" r="2" />
                          <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                        </svg>
                        <span>Gambar banner akan tampil di sini saat file dipilih</span>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div className={styles.formActions}>
            <button className={styles.btnSecondary} onClick={closeForm} disabled={saving}>
              Batal
            </button>
            <button className={styles.btnPrimary} onClick={handleSubmit} disabled={saving}>
              {saving ? 'Menyimpan...' : editing ? 'Simpan Perubahan' : 'Terbitkan Banner'}
            </button>
          </div>
        </section>
      )}

      <section className={styles.listCard}>
        {loading ? (
          <div className={styles.emptyState}>Memuat banner pendaftar...</div>
        ) : banners.length === 0 ? (
          <div className={styles.emptyState}>
            Belum ada banner untuk dashboard pendaftar. Klik tombol <b>+ Tambah Banner Pendaftar</b> di atas untuk menambahkan.
          </div>
        ) : (
          banners.map((banner, i) => (
            <div key={banner.id} className={styles.bannerRow}>
              <div className={styles.orderCol}>
                <button
                  className={styles.orderBtn}
                  aria-label="Naikkan urutan"
                  disabled={i === 0 || reordering}
                  onClick={() => move(i, -1)}
                >
                  ▲
                </button>
                <span className={styles.orderNum}>{i + 1}</span>
                <button
                  className={styles.orderBtn}
                  aria-label="Turunkan urutan"
                  disabled={i === banners.length - 1 || reordering}
                  onClick={() => move(i, 1)}
                >
                  ▼
                </button>
              </div>

              <div className={styles.thumb}>
                {banner.has_image ? (
                  /* eslint-disable-next-line @next/next/no-img-element */
                  <img src={`${apiBase}${banner.image_url}`} alt={banner.title} loading="lazy" />
                ) : (
                  <div style={{ color: '#94a3b8', fontSize: '11px', textAlign: 'center', padding: '10px' }}>
                    Tanpa Gambar
                  </div>
                )}
              </div>

              <div className={styles.info}>
                <h3>{banner.title}</h3>
                {banner.cta_url && <p className={styles.linkText}>🔗 Tujuan klik: {banner.cta_url}</p>}
                <div className={styles.badgeRow}>
                  <span className={`${styles.badge} ${banner.is_active ? styles.badgeOn : styles.badgeOff}`}>
                    {banner.is_active ? 'Aktif' : 'Nonaktif'}
                  </span>
                  {(banner.starts_at || banner.ends_at) && (
                    <span className={`${styles.badge} ${styles.badgePeriod}`}>
                      {banner.starts_at ? new Date(banner.starts_at).toLocaleDateString('id-ID') : '…'}
                      {' — '}
                      {banner.ends_at ? new Date(banner.ends_at).toLocaleDateString('id-ID') : '…'}
                    </span>
                  )}
                </div>
              </div>

              <div className={styles.actions}>
                <button
                  className={`${styles.actionBtn} ${banner.is_active ? styles.actionDeactivate : styles.actionActivate}`}
                  onClick={() => handleToggle(banner)}
                >
                  {banner.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                </button>
                <button className={`${styles.actionBtn} ${styles.actionEdit}`} onClick={() => openEdit(banner)}>
                  Edit
                </button>
                <button className={`${styles.actionBtn} ${styles.actionDelete}`} onClick={() => handleDelete(banner)}>
                  Hapus
                </button>
              </div>
            </div>
          ))
        )}
      </section>
    </div>
  );
}
