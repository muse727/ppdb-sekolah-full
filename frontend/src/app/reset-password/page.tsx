// src/app/reset-password/page.tsx — E.10: buat password baru dari tautan email.
'use client';

import { Suspense, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import libAxios, { ensureCsrfCookie } from '@/lib/axios';
import Swal from 'sweetalert2';
import { httpErrorMessage } from '@/lib/domain';
import styles from './reset-password.module.css';

function ResetPasswordForm() {
  const params = useSearchParams();
  const email = params.get('email') ?? '';
  const token = params.get('token') ?? '';

  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const hasValidParams = email.includes('@') && token.length > 0;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    if (password.length < 8) {
      setError('Password minimal 8 karakter.');
      return;
    }
    if (password !== confirm) {
      setError('Konfirmasi password tidak sama.');
      return;
    }

    setLoading(true);
    try {
      await ensureCsrfCookie();
      await libAxios.post('/api/reset-password', {
        email,
        token,
        password,
        password_confirmation: confirm,
      });
      await Swal.fire({
        icon: 'success',
        title: 'Password Berhasil Diubah',
        text: 'Semua sesi login lama telah dikeluarkan. Silakan login dengan password baru Anda.',
        confirmButtonColor: '#1a5f51',
      });
      window.location.href = '/login';
    } catch (err) {
      setError(httpErrorMessage(err, 'Tautan reset tidak valid atau sudah kedaluwarsa. Silakan ajukan reset password kembali.'));
    } finally {
      setLoading(false);
    }
  };

  if (!hasValidParams) {
    return (
      <div className={styles.page}>
        <div className={styles.card}>
          <h1 className={styles.title}>Tautan Tidak Valid</h1>
          <p className={styles.subtitle}>
            Tautan reset password tidak lengkap atau tidak valid. Silakan ajukan
            permintaan reset password kembali.
          </p>
          <Link href="/lupa-password" className={styles.backLink}>
            Ajukan reset password &rarr;
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className={styles.page}>
      <div className={styles.card}>
        <h1 className={styles.title}>Buat Password Baru</h1>
        <p className={styles.subtitle}>
          Untuk akun <b>{email}</b>. Tautan ini hanya berlaku sekali dan 60 menit sejak email dikirim.
        </p>

        {error && <div className={styles.errorBox}>{error}</div>}

        <form onSubmit={submit}>
          <input
            className={styles.input}
            type="password"
            placeholder="Password baru (min. 8 karakter)"
            minLength={8}
            maxLength={100}
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            disabled={loading}
          />
          <input
            className={`${styles.input} ${password && confirm && password !== confirm ? styles.inputError : ''}`}
            type="password"
            placeholder="Ulangi password baru"
            minLength={8}
            maxLength={100}
            required
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
            disabled={loading}
          />
          {password && confirm && password !== confirm && (
            <p className={styles.hint}>Konfirmasi password belum sama.</p>
          )}
          <button type="submit" className={styles.button} disabled={loading}>
            {loading ? 'Menyimpan…' : 'Simpan Password Baru'}
          </button>
        </form>

        <Link href="/login" className={styles.backLink}>
          &larr; Kembali ke halaman login
        </Link>
      </div>
    </div>
  );
}

export default function ResetPassword() {
  return (
    <Suspense fallback={null}>
      <ResetPasswordForm />
    </Suspense>
  );
}
