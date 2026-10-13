// src/app/lupa-password/page.tsx — E.10: permintaan reset password via email.
'use client';

import { useState } from 'react';
import Link from 'next/link';
import libAxios, { ensureCsrfCookie } from '@/lib/axios';
import Swal from 'sweetalert2';
import { httpErrorMessage } from '@/lib/domain';
import styles from './lupa-password.module.css';

export default function LupaPassword() {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    if (!email.trim()) {
      setError('Mohon isi alamat email Anda.');
      return;
    }

    setLoading(true);
    try {
      await ensureCsrfCookie();
      // Respons backend selalu generik — tidak membocorkan apakah email terdaftar.
      await libAxios.post('/api/forgot-password', { email: email.trim() });
      await Swal.fire({
        icon: 'success',
        title: 'Permintaan Terkirim',
        text: 'Jika email terdaftar, tautan reset password telah dikirim. Silakan periksa kotak masuk Anda (termasuk folder spam).',
        confirmButtonColor: '#1a5f51',
      });
      window.location.href = '/login';
    } catch (err) {
      setError(httpErrorMessage(err, 'Gagal mengirim permintaan. Silakan coba lagi nanti.'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className={styles.page}>
      <div className={styles.card}>
        <h1 className={styles.title}>Lupa Password</h1>
        <p className={styles.subtitle}>
          Masukkan alamat email akun SPMB Anda. Kami akan mengirim tautan untuk membuat password baru.
        </p>

        {error && <div className={styles.errorBox}>{error}</div>}

        <form onSubmit={submit}>
          <input
            className={styles.input}
            type="email"
            placeholder="Alamat email terdaftar"
            required
            maxLength={255}
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            disabled={loading}
          />
          <button type="submit" className={styles.button} disabled={loading}>
            {loading ? 'Mengirim…' : 'Kirim Tautan Reset'}
          </button>
        </form>

        <Link href="/login" className={styles.backLink}>
          &larr; Kembali ke halaman login
        </Link>
      </div>
    </div>
  );
}
