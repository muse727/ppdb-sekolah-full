// src/app/dashboard/banners/page.tsx
'use client';

import { useCallback, useEffect, useState } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './banners.module.css';

/**
 * CMS Banner hero landing page (admin).
 * Mengikuti pola admin existing: libAxios + Sanctum, Swal untuk dialog,
 * authorization sesungguhnya di backend (CheckRole:admin) — UI hanya cermin.
 */

interface AdminBanner {
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

interface BannerForm {
  title: string;
  description: string;
  cta_text: string;
  cta_url: string;
  sort_order: string;
  is_active: boolean;
  starts_at: string;
  ends_at: string;
}

const EMPTY_FORM: BannerForm = {
  title: '', description: '', cta_text: '', cta_url: '',
  sort_order: '0', is_active: true, starts_at: '', ends_at: '',
};

/** datetime-local (yyyy-MM-ddTHH:mm) <-> ISO; kosong -> null. */
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

export default function BannersPage() {
  const apiBase = (process.env.NEXT_PUBLIC_API_URL || '').replace(/\/$/, '');

  const [banners, setBanners] = useState<AdminBanner[]>([]);
  const [loading, setLoading] = useState(true);

  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<AdminBanner | null>(null);
  const [form, setForm] = useState<BannerForm>(EMPTY_FORM);
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [previewSrc, setPreviewSrc] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [reordering, setReordering] = useState(false);

  const load = useCallback(async () => {
    try {
      const res = await libAxios.get('/api/admin/banners?target=landing');
      setBanners(res.data);
    } catch {
      Swal.fire({ icon: 'error', title: 'Gagal', text: 'Tidak dapat memuat daftar banner landing page.', confirmButtonColor: '#1a5f51' });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  // Object URL preview — selalu dilepas saat ganti/unmount.
  useEffect(() => {
    if (!imageFile) { setPreviewSrc(null); return; }
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

  const openEdit = (banner: AdminBanner) => {
    setEditing(banner);
    setForm({
      title: banner.title,
      description: banner.description ?? '',
      cta_text: banner.cta_text ?? '',
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
      return first ?? res?.data?.message ?? 'Terjadi kesalahan. Periksa isian Anda.';
    }
    return 'Tidak dapat terhubung ke server.';
  };

  const handleSubmit = async () => {
    if (!editing && !imageFile) {
      Swal.fire({ icon: 'warning', title: 'Media wajib', text: 'Pilih file gambar atau video banner terlebih dahulu.', confirmButtonColor: '#1a5f51' });
      return;
    }
    setSaving(true);
    try {
      const fd = new FormData();
      fd.append('target', 'landing');
      fd.append('title', form.title);
      fd.append('description', form.description);
      fd.append('cta_text', form.cta_text);
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

      await Swal.fire({ icon: 'success', title: 'Tersimpan', text: editing ? 'Banner landing page diperbarui.' : 'Banner landing page ditambahkan.', confirmButtonColor: '#1a5f51', timer: 1800 });
      closeForm();
      await load();
    } catch (error) {
      Swal.fire({ icon: 'error', title: 'Gagal menyimpan', text: fieldErrorText(error), confirmButtonColor: '#1a5f51' });
    } finally {
      setSaving(false);
    }
  };

  const handleToggle = async (banner: AdminBanner) => {
    try {
      const res = await libAxios.patch(`/api/admin/banners/${banner.id}/status`, { is_active: !banner.is_active });
      setBanners(prev => prev.map(b => b.id === banner.id ? { ...b, is_active: res.data.is_active } : b));
    } catch (error) {
      Swal.fire({ icon: 'error', title: 'Gagal', text: fieldErrorText(error), confirmButtonColor: '#1a5f51' });
    }
  };

  const handleDelete = async (banner: AdminBanner) => {
    const result = await Swal.fire({
      icon: 'warning',
      title: 'Hapus banner?',
      text: `"${banner.title}" beserta gambarnya akan dihapus permanen.`,
      showCancelButton: true,
      confirmButtonColor: '#b91c1c',
      cancelButtonColor: '#64748b',
      confirmButtonText: 'Ya, hapus',
      cancelButtonText: 'Batal',
    });
    if (!result.isConfirmed) return;
    try {
      await libAxios.delete(`/api/admin/banners/${banner.id}`);
      setBanners(prev => prev.filter(b => b.id !== banner.id));
      Swal.fire({ icon: 'success', title: 'Terhapus', confirmButtonColor: '#1a5f51', timer: 1200 });
    } catch (error) {
      Swal.fire({ icon: 'error', title: 'Gagal menghapus', text: fieldErrorText(error), confirmButtonColor: '#1a5f51' });
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
      Swal.fire({ icon: 'error', title: 'Gagal mengurutkan', confirmButtonColor: '#1a5f51' });
    } finally {
      setReordering(false);
    }
  };

  const isVideoPreview = imageFile
    ? (imageFile.type ? imageFile.type.startsWith('video/') : /\.(mp4|webm)$/i.test(imageFile.name))
    : editing?.media_type === 'video';

  const previewMediaSrc = previewSrc ?? (editing?.has_image ? `${apiBase}${editing.image_url}` : null);

  return (
    <div className={styles.container}>
      <header className={styles.header}>
        <div className={styles.headerTitle}>
          <h1>Banner Hero Landing Page</h1>
          <p>Kelola slide hero yang tampil di halaman utama SPMB. Hanya banner aktif dan berada dalam periode tayang yang dilihat publik.</p>
        </div>
        {!showForm && (
          <button className={styles.btnPrimary} onClick={openCreate}>+ Tambah Slide Hero</button>
        )}
      </header>

      {showForm && (
        <section className={styles.formCard} aria-labelledby="form-title">
          <div className={styles.formHeader}>
            <div>
              <h2 id="form-title">{editing ? 'Edit Slide Hero' : 'Tambah Slide Hero Baru'}</h2>
              <p>Isi informasi detail slide hero yang akan ditampilkan di landing page SPMB.</p>
            </div>
            <button className={styles.btnSecondary} onClick={closeForm} disabled={saving}>
              Tutup Form
            </button>
          </div>

          <div className={styles.formSectionTitle}>Informasi Utama</div>
          <div className={styles.formGrid}>
            <div className={styles.fieldFull}>
              <label className={styles.label} htmlFor="banner-title">Judul Banner <small>(maks 120 karakter)</small></label>
              <input id="banner-title" className={styles.input} maxLength={120} placeholder="Contoh: Penerimaan Santri Baru SIT Al-Mubarak"
                value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))} />
            </div>

            <div className={styles.fieldFull}>
              <label className={styles.label} htmlFor="banner-desc">Deskripsi Singkat <small>(opsional, maks 500 karakter)</small></label>
              <textarea id="banner-desc" className={styles.textarea} maxLength={500} placeholder="Tuliskan keterangan pendukung banner di sini..."
                value={form.description} onChange={e => setForm(f => ({ ...f, description: e.target.value }))} />
            </div>
          </div>

          <div className={styles.formSectionTitle}>Tombol Aksi (Call To Action)</div>
          <div className={styles.formGrid}>
            <div>
              <label className={styles.label} htmlFor="banner-cta-text">Teks Tombol CTA <small>(opsional)</small></label>
              <input id="banner-cta-text" className={styles.input} maxLength={60} placeholder="Contoh: Daftar Sekarang"
                value={form.cta_text} onChange={e => setForm(f => ({ ...f, cta_text: e.target.value }))} />
            </div>

            <div>
              <label className={styles.label} htmlFor="banner-cta-url">URL Tujuan CTA <small>(https://... atau internal /login)</small></label>
              <input id="banner-cta-url" className={styles.input} maxLength={255} placeholder="/login"
                value={form.cta_url} onChange={e => setForm(f => ({ ...f, cta_url: e.target.value }))} />
            </div>
          </div>

          <div className={styles.formSectionTitle}>Pengaturan & Jadwal Tayang</div>
          <div className={styles.formGrid}>
            <div>
              <label className={styles.label} htmlFor="banner-order">Urutan Tampil <small>(0 = slide paling awal)</small></label>
              <input id="banner-order" className={styles.input} type="number" min={0} max={65535}
                value={form.sort_order} onChange={e => setForm(f => ({ ...f, sort_order: e.target.value }))} />
            </div>

            <div>
              <label className={styles.label}>Status Publikasi</label>
              <div className={styles.checkboxWrapper}>
                <label className={styles.checkboxLabel}>
                  <input type="checkbox" checked={form.is_active}
                    onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))} />
                  <div>
                    <strong>Aktifkan Slide</strong>
                    <span>Slide akan tayang di landing page jika dalam jadwal aktif</span>
                  </div>
                </label>
              </div>
            </div>

            <div>
              <label className={styles.label} htmlFor="banner-start">Mulai Tayang <small>(opsional)</small></label>
              <input id="banner-start" className={styles.input} type="datetime-local"
                value={form.starts_at} onChange={e => setForm(f => ({ ...f, starts_at: e.target.value }))} />
            </div>

            <div>
              <label className={styles.label} htmlFor="banner-end">Selesai Tayang <small>(opsional)</small></label>
              <input id="banner-end" className={styles.input} type="datetime-local"
                value={form.ends_at} onChange={e => setForm(f => ({ ...f, ends_at: e.target.value }))} />
            </div>
          </div>

          <div className={styles.formSectionTitle}>Media Banner & Pratinjau</div>
          <div className={styles.formGrid}>
            <div className={styles.fieldFull}>
              <label className={styles.label} htmlFor="banner-media">
                File Media (Gambar atau Video) {editing && <small>(kosongkan bila tidak ingin diganti)</small>}
              </label>
              <div className={styles.uploadArea}>
                <input
                  id="banner-media"
                  type="file"
                  accept="image/jpeg,image/png,image/webp,video/mp4,video/webm"
                  onChange={e => {
                    const f = e.target.files?.[0] ?? null;
                    setImageFile(f);
                  }}
                />
                <div className={styles.uploadContent}>
                  <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                  <div className={styles.uploadText}>
                    <strong>{imageFile ? imageFile.name : 'Pilih file media atau seret ke sini'}</strong>
                    <span>Format didukung: JPG, PNG, WEBP, MP4, atau WEBM (maksimal 25 MB).</span>
                  </div>
                </div>
              </div>

              <div className={styles.previewSection}>
                <div className={styles.previewHeader}>
                  <span>Simulasi Tampilan di Hero Landing Page</span>
                  <small>Rasio aspek responsif dengan lapisan gradien</small>
                </div>
                <div className={styles.previewBox} aria-label="Pratinjau tampilan slide hero">
                  {previewMediaSrc ? (
                    <>
                      {isVideoPreview ? (
                        <video src={previewMediaSrc} controls muted loop playsInline />
                      ) : (
                        /* eslint-disable-next-line @next/next/no-img-element */
                        <img src={previewMediaSrc} alt="Pratinjau media" />
                      )}
                      <div className={styles.previewOverlay}>
                        <span className={styles.previewBadge}>Slide Hero</span>
                        <h3>{form.title || 'Judul banner'}</h3>
                        {form.description && <p>{form.description}</p>}
                        {form.cta_text && form.cta_url && <span className={styles.previewCta}>{form.cta_text}</span>}
                      </div>
                    </>
                  ) : (
                    <div className={styles.previewEmpty}>
                      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                      <span>Pratinjau visual akan muncul otomatis saat media dipilih</span>
                    </div>
                  )}
                </div>
              </div>
            </div>
          </div>

          <div className={styles.formActions}>
            <button className={styles.btnSecondary} onClick={closeForm} disabled={saving}>Batal</button>
            <button className={styles.btnPrimary} onClick={handleSubmit} disabled={saving}>
              {saving ? 'Menyimpan...' : (editing ? 'Simpan Perubahan' : 'Terbitkan Slide Hero')}
            </button>
          </div>
        </section>
      )}

      <section className={styles.listCard} aria-labelledby="list-title">
        <h2 id="list-title" className={styles.srOnly}>Daftar Slide Hero</h2>
        {loading ? (
          <div className={styles.emptyState}>Memuat data slide hero...</div>
        ) : banners.length === 0 ? (
          <div className={styles.emptyState}>Belum ada slide hero. Landing page akan menampilkan tampilan bawaan sampai slide pertama dipublikasikan.</div>
        ) : (
          banners.map((banner, i) => (
            <div key={banner.id} className={styles.bannerRow}>
              <div className={styles.orderCol}>
                <button className={styles.orderBtn} aria-label="Naikkan urutan" disabled={i === 0 || reordering}
                  onClick={() => move(i, -1)}>▲</button>
                <span className={styles.orderNum}>{i + 1}</span>
                <button className={styles.orderBtn} aria-label="Turunkan urutan" disabled={i === banners.length - 1 || reordering}
                  onClick={() => move(i, 1)}>▼</button>
              </div>

              <div className={styles.thumb}>
                {banner.has_image && (
                  banner.media_type === 'video' ? (
                    <video src={`${apiBase}${banner.image_url}`} muted loop autoPlay playsInline />
                  ) : (
                    /* eslint-disable-next-line @next/next/no-img-element */
                    <img src={`${apiBase}${banner.image_url}`} alt={`Banner ${banner.title}`} loading="lazy" />
                  )
                )}
              </div>

              <div className={styles.info}>
                <div className={styles.titleWithBadge}>
                  <h3>{banner.title}</h3>
                  <span className={`${styles.typeBadge} ${banner.media_type === 'video' ? styles.typeVideo : styles.typeImage}`}>
                    {banner.media_type === 'video' ? '🎬 Video' : '🖼️ Gambar'}
                  </span>
                </div>
                {banner.description && <p>{banner.description}</p>}
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
