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
      const res = await libAxios.get('/api/admin/banners');
      setBanners(res.data);
    } catch {
      Swal.fire({ icon: 'error', title: 'Gagal', text: 'Tidak dapat memuat daftar banner.', confirmButtonColor: '#1a5f51' });
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
      Swal.fire({ icon: 'warning', title: 'Gambar wajib', text: 'Pilih gambar banner terlebih dahulu.', confirmButtonColor: '#1a5f51' });
      return;
    }
    setSaving(true);
    try {
      const fd = new FormData();
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

      await Swal.fire({ icon: 'success', title: 'Tersimpan', text: editing ? 'Banner diperbarui.' : 'Banner ditambahkan.', confirmButtonColor: '#1a5f51', timer: 1800 });
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
    } catch (error) {
      Swal.fire({ icon: 'error', title: 'Gagal', text: fieldErrorText(error), confirmButtonColor: '#1a5f51' });
    }
  };

  /** Pindah posisi lalu persist urutan baru ke backend. */
  const move = async (index: number, delta: number) => {
    const target = index + delta;
    if (target < 0 || target >= banners.length || reordering) return;
    const next = [...banners];
    [next[index], next[target]] = [next[target], next[index]];
    setBanners(next);
    setReordering(true);
    try {
      await libAxios.patch('/api/admin/banners/reorder', { orders: next.map(b => b.id) });
      await load();
    } catch (error) {
      Swal.fire({ icon: 'error', title: 'Gagal mengurutkan', text: fieldErrorText(error), confirmButtonColor: '#1a5f51' });
      await load();
    } finally {
      setReordering(false);
    }
  };

  const previewImageSrc = previewSrc ?? (editing?.has_image ? `${apiBase}${editing.image_url}` : null);

  return (
    <div className={styles.container}>
      <div className={styles.header}>
        <div>
          <h1>Banner Hero Landing Page</h1>
          <p>Kelola slide hero yang tampil di halaman utama SPMB. Hanya banner aktif dan berada dalam periode tayang yang dilihat publik.</p>
        </div>
        {!showForm && (
          <button className={styles.btnPrimary} onClick={openCreate}>+ Tambah Banner</button>
        )}
      </div>

      {showForm && (
        <div className={styles.formCard}>
          <h2>{editing ? 'Edit Banner' : 'Banner Baru'}</h2>
          <div className={styles.formGrid}>
            <div className={styles.fieldFull}>
              <label className={styles.label} htmlFor="banner-title">Judul <small>(maks 120 karakter)</small></label>
              <input id="banner-title" className={styles.input} maxLength={120}
                value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))} />
            </div>

            <div className={styles.fieldFull}>
              <label className={styles.label} htmlFor="banner-desc">Deskripsi <small>(opsional, maks 500 karakter)</small></label>
              <textarea id="banner-desc" className={styles.textarea} maxLength={500}
                value={form.description} onChange={e => setForm(f => ({ ...f, description: e.target.value }))} />
            </div>

            <div>
              <label className={styles.label} htmlFor="banner-cta-text">Teks Tombol CTA <small>(opsional)</small></label>
              <input id="banner-cta-text" className={styles.input} maxLength={60} placeholder="Contoh: Daftar Sekarang"
                value={form.cta_text} onChange={e => setForm(f => ({ ...f, cta_text: e.target.value }))} />
            </div>

            <div>
              <label className={styles.label} htmlFor="banner-cta-url">URL CTA <small>(https://… atau path internal /…)</small></label>
              <input id="banner-cta-url" className={styles.input} maxLength={255} placeholder="/login"
                value={form.cta_url} onChange={e => setForm(f => ({ ...f, cta_url: e.target.value }))} />
            </div>

            <div>
              <label className={styles.label} htmlFor="banner-order">Urutan <small>(0 = paling awal)</small></label>
              <input id="banner-order" className={styles.input} type="number" min={0} max={65535}
                value={form.sort_order} onChange={e => setForm(f => ({ ...f, sort_order: e.target.value }))} />
            </div>

            <div className={styles.checkboxRow}>
              <input id="banner-active" type="checkbox" checked={form.is_active}
                onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))} />
              <span>Aktif (tampil di landing page)</span>
            </div>

            <div>
              <label className={styles.label} htmlFor="banner-starts">Mulai Tayang <small>(opsional)</small></label>
              <input id="banner-starts" className={styles.input} type="datetime-local"
                value={form.starts_at} onChange={e => setForm(f => ({ ...f, starts_at: e.target.value }))} />
            </div>

            <div>
              <label className={styles.label} htmlFor="banner-ends">Berakhir <small>(opsional)</small></label>
              <input id="banner-ends" className={styles.input} type="datetime-local"
                value={form.ends_at} onChange={e => setForm(f => ({ ...f, ends_at: e.target.value }))} />
            </div>

            <div className={styles.fieldFull}>
              <label className={styles.label} htmlFor="banner-image">
                Gambar Banner {editing && <small>(kosongkan bila tidak diganti)</small>}
              </label>
              <input id="banner-image" className={styles.input} type="file" accept="image/jpeg,image/png"
                onChange={e => setImageFile(e.target.files?.[0] ?? null)} />
              <div className={styles.fileHint}>JPG atau PNG, maksimal 3 MB, resolusi hingga 4000×4000 piksel. Rekomendasi rasio lebar (mis. 1920×800).</div>

              <div className={styles.previewBox} aria-label="Pratinjau tampilan banner">
                {previewImageSrc ? (
                  <>
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={previewImageSrc} alt="Pratinjau gambar banner" />
                    <div className={styles.previewOverlay}>
                      <h3>{form.title || 'Judul banner'}</h3>
                      {form.description && <p>{form.description}</p>}
                      {form.cta_text && form.cta_url && <span className={styles.previewCta}>{form.cta_text}</span>}
                    </div>
                  </>
                ) : (
                  <div className={styles.previewEmpty}>Pratinjau akan tampil setelah gambar dipilih</div>
                )}
              </div>
            </div>
          </div>

          <div className={styles.formActions}>
            <button className={styles.btnPrimary} onClick={handleSubmit} disabled={saving}>
              {saving ? 'Menyimpan…' : editing ? 'Simpan Perubahan' : 'Publikasikan Banner'}
            </button>
            <button className={styles.btnSecondary} onClick={closeForm} disabled={saving}>Batal</button>
          </div>
        </div>
      )}

      <div className={styles.listCard}>
        {loading ? (
          <div className={styles.loadingState}>Memuat daftar banner…</div>
        ) : banners.length === 0 ? (
          <div className={styles.emptyState}>Belum ada banner. Landing page akan menampilkan tampilan bawaan sampai banner pertama dipublikasikan.</div>
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
                  /* eslint-disable-next-line @next/next/no-img-element */
                  <img src={`${apiBase}${banner.image_url}`} alt={`Gambar ${banner.title}`} loading="lazy" />
                )}
              </div>

              <div className={styles.info}>
                <h3>{banner.title}</h3>
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

              <div className={styles.rowActions}>
                <button className={`${styles.actionBtn} ${styles.actionNeutral}`} onClick={() => openEdit(banner)}>Edit</button>
                <button
                  className={`${styles.actionBtn} ${banner.is_active ? styles.actionGray : styles.actionGreen}`}
                  onClick={() => handleToggle(banner)}
                >
                  {banner.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                </button>
                <button className={`${styles.actionBtn} ${styles.actionRed}`} onClick={() => handleDelete(banner)}>Hapus</button>
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
}
