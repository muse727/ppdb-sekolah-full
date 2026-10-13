// src/app/login/page.tsx
'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './login.module.css';

export default function Login() {
  const router = useRouter();
  const [isRightPanelActive, setIsRightPanelActive] = useState(false);

  // === STATE REGISTER ===
  const [regJalur, setRegJalur] = useState('UMUM'); 
  const [regName, setRegName] = useState('');
  const [regEmail, setRegEmail] = useState('');
  const [regPassword, setRegPassword] = useState('');
  const [regKredensial, setRegKredensial] = useState('');
  const [showRegPassword, setShowRegPassword] = useState(false);

  // === STATE LOGIN ===
  const [loginEmail, setLoginEmail] = useState('');
  const [loginPassword, setLoginPassword] = useState('');
  const [showLoginPassword, setShowLoginPassword] = useState(false);
  const [rememberMe, setRememberMe] = useState(false);

  // === STATE CAPTCHA ANTI-BOT ===
  const [captchaNum1, setCaptchaNum1] = useState(0);
  const [captchaNum2, setCaptchaNum2] = useState(0);
  const [captchaAnswer, setCaptchaAnswer] = useState('');

  // === STATE MODAL TOKEN ===
  const [showModal, setShowModal] = useState(false);
  const [modalStep, setModalStep] = useState('PILIH_JENJANG'); 
  const [tokenForm, setTokenForm] = useState({ nama: '', email: '' });
  const [generatedToken, setGeneratedToken] = useState('');
  const [isGenerating, setIsGenerating] = useState(false);

  // Generate Captcha Otomatis pas halaman dimuat
  const generateCaptcha = () => {
    setCaptchaNum1(Math.floor(Math.random() * 10) + 1);
    setCaptchaNum2(Math.floor(Math.random() * 10) + 1);
    setCaptchaAnswer('');
  };

  useEffect(() => {
    generateCaptcha();
  }, []);

  const openTokenModal = () => { setModalStep('PILIH_JENJANG'); setTokenForm({ nama: '', email: '' }); setShowModal(true); };
  const closeTokenModal = () => setShowModal(false);

  const handleGenerateSMPToken = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsGenerating(true);
    setTimeout(() => {
      const dummyToken = 'AM-SMP-' + Math.floor(Math.random() * 9000 + 1000);
      setGeneratedToken(dummyToken);
      setRegKredensial(dummyToken); 
      setModalStep('SUCCESS_SMP');
      setIsGenerating(false);
    }, 1500);
  };

  // === HANDLE REGISTER ===
  const handleRegister = async (e: React.FormEvent) => {
    e.preventDefault();
    Swal.fire({ title: 'Memproses...', text: 'Mendaftarkan akun Anda', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});

    try {
      await libAxios.get('/sanctum/csrf-cookie');
      const response = await libAxios.post('/api/register', { 
        name: regName, email: regEmail, password: regPassword, jalur: regJalur, kredensial: regKredensial
      });
      
      const userRole = response.data?.user?.role || 'orang_tua';
      Swal.fire({ icon: 'success', title: 'Berhasil!', text: 'Akun berhasil dibuat. Mengalihkan...', showConfirmButton: false, timer: 1500 })
      .then(() => {
        if (userRole === 'guru') { router.push('/guru'); } 
        else if (userRole === 'admin' || userRole === 'super_admin') { router.push('/dashboard'); } 
        else { router.push('/pendaftar'); }
      });
    } catch (error: any) {
      let errorMessage = 'Terjadi kesalahan saat registrasi';
      if (error.response?.data?.errors?.email) errorMessage = 'Email ini sudah terdaftar. Silakan gunakan email lain atau langsung Login.';
      else if (error.response?.data?.message) errorMessage = error.response.data.message;
      Swal.fire({ icon: 'error', title: 'Gagal', text: errorMessage, confirmButtonColor: '#1a5f51' });
    }
  };

  // === HANDLE LOGIN ===
  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();

    // Validasi Captcha
    if (parseInt(captchaAnswer) !== (captchaNum1 + captchaNum2)) {
        Swal.fire({ icon: 'error', title: 'Captcha Salah', text: 'Silakan hitung ulang angka keamanan dengan benar!', confirmButtonColor: '#1a5f51' });
        generateCaptcha(); 
        return;
    }

    Swal.fire({ title: 'Mohon Tunggu...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});

    try {
      await libAxios.get('/sanctum/csrf-cookie');
      const response = await libAxios.post('/api/login', { 
          email: loginEmail, 
          password: loginPassword,
          remember: rememberMe 
      });
      
      const userRole = response.data?.user?.role || 'orang_tua';
      Swal.fire({ icon: 'success', title: 'Login Berhasil!', text: 'Mengarahkan...', showConfirmButton: false, timer: 1500 })
      .then(() => {
        if (userRole === 'guru') { router.push('/guru'); } 
        else if (userRole === 'admin' || userRole === 'super_admin') { router.push('/dashboard'); } 
        else { router.push('/pendaftar'); }
      });
    } catch (error: any) {
      let errorMsg = error.response?.data?.message || 'Email atau Password salah!';
      Swal.fire({ icon: 'error', title: 'Akses Ditolak', text: errorMsg, confirmButtonColor: '#1a5f51' });
      generateCaptcha(); 
    }
  };

  return (
    <div className={styles.wrapper}>
      <div className={`${styles.container} ${isRightPanelActive ? styles.rightPanelActive : ''}`} id="container">
        
        {/* ================= FORM REGISTER ================= */}
        <div className={`${styles.formContainer} ${styles.signUpContainer}`}>
          <form className={styles.form} onSubmit={handleRegister}>
            <div className={styles.mobileHeader}>
              <img src="/education.svg" alt="Education" className={styles.mobileIllustration} />
              <h2 className={styles.mobileHeaderTitle}>Ahlan Wa Sahlan!</h2>
              <p className={styles.mobileHeaderDesc}>Mari bergabung dengan Al-Mubarak</p>
            </div>
            
            <h1 className={styles.title}>Buat Akun SPMB</h1>
            
            <div className={styles.jalurToggleContainer}>
                <button type="button" className={`${styles.jalurToggleBtn} ${regJalur === 'UMUM' ? styles.jalurActive : ''}`} onClick={() => { setRegJalur('UMUM'); setRegKredensial(''); }}>
                    Jalur Umum
                </button>
                <button type="button" className={`${styles.jalurToggleBtn} ${regJalur === 'INTERNAL' ? styles.jalurActive : ''}`} onClick={() => { setRegJalur('INTERNAL'); setRegKredensial(''); }}>
                    Alumni Internal
                </button>
            </div>
            <span className={styles.paragraph} style={{marginTop: 0}}>
                {regJalur === 'UMUM' ? 'Gunakan Token Pendaftaran Reguler' : 'Gunakan NISN Terdaftar (TK/SD Al-Mubarak)'}
            </span>

            <div className={styles.inputGroup}>
              <svg className={styles.icon} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
              <input className={styles.input} type="text" placeholder="Nama Calon Siswa" required value={regName} onChange={(e) => setRegName(e.target.value)} />
            </div>

            <div className={styles.inputGroup}>
              <svg className={styles.icon} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
              <input className={styles.input} type="email" placeholder="Alamat Email Aktif" required value={regEmail} onChange={(e) => setRegEmail(e.target.value)} />
            </div>

            <div className={styles.inputGroup}>
              <svg className={styles.icon} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
              <input className={`${styles.input} ${styles.inputPassword}`} type={showRegPassword ? "text" : "password"} placeholder="Buat Password (Min. 8 Karakter)" minLength={8} required value={regPassword} onChange={(e) => setRegPassword(e.target.value)} />
              <button type="button" className={styles.eyeBtn} onClick={() => setShowRegPassword(!showRegPassword)}>
                {showRegPassword ? (
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                ) : (
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                )}
              </button>
            </div>

            <div className={styles.inputGroup}>
              <svg className={styles.icon} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke={regJalur === 'INTERNAL' ? '#eab308' : '#1a5f51'} strokeWidth="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
              <input 
                className={`${styles.input} ${regJalur === 'INTERNAL' ? styles.inputHighlight : ''}`} 
                type="text" 
                placeholder={regJalur === 'UMUM' ? "Masukkan Kode Token..." : "Masukkan NISN Valid..."} 
                required 
                value={regKredensial} 
                onChange={(e) => setRegKredensial(e.target.value)} 
              />
            </div>
            
            {regJalur === 'UMUM' && (
                <button type="button" className={styles.btnGetToken} onClick={openTokenModal}>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                    Belum punya Token? Dapatkan di sini
                </button>
            )}
            
            <button type="submit" className={styles.button}>Selesaikan Pendaftaran</button>
            <button type="button" className={styles.mobileToggleBtn} onClick={() => setIsRightPanelActive(false)}>
              Punya Akun? Masuk di sini
            </button>
          </form>
        </div>

        {/* ================= FORM LOGIN ================= */}
        <div className={`${styles.formContainer} ${styles.signInContainer}`}>
          <form className={styles.form} onSubmit={handleLogin}>
            <div className={styles.mobileHeader}>
              <img src="/education.svg" alt="Education" className={styles.mobileIllustration} />
              <h2 className={styles.mobileHeaderTitle}>Selamat Datang!</h2>
              <p className={styles.mobileHeaderDesc}>Silakan login ke portal SPMB</p>
            </div>
            
            <h1 className={styles.title}>Login SPMB</h1>
            <span className={styles.paragraph}>SDIT & SMPIT Al-Mubarak</span>
            
            <div className={styles.inputGroup}>
              <svg className={styles.icon} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
              <input className={styles.input} type="email" placeholder="Masukkan Email" required value={loginEmail} onChange={(e) => setLoginEmail(e.target.value)} />
            </div>

            <div className={styles.inputGroup}>
              <svg className={styles.icon} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
              <input className={`${styles.input} ${styles.inputPassword}`} type={showLoginPassword ? "text" : "password"} placeholder="Masukkan Password" required value={loginPassword} onChange={(e) => setLoginPassword(e.target.value)} />
              <button type="button" className={styles.eyeBtn} onClick={() => setShowLoginPassword(!showLoginPassword)}>
                {showLoginPassword ? (
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                ) : (
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                )}
              </button>
            </div>

            <div className={styles.loginOptions}>
                <label className={styles.rememberMe}>
                    <input type="checkbox" checked={rememberMe} onChange={(e) => setRememberMe(e.target.checked)} />
                    <span>Ingat akun saya</span>
                </label>
                <a href="#" className={styles.link}>Lupa Password?</a>
            </div>

            <div className={styles.captchaBox}>
                <span className={styles.captchaLabel}>Keamanan: Berapa hasil <b>{captchaNum1} + {captchaNum2}</b>?</span>
                <input type="number" className={styles.captchaInput} placeholder="Jawaban..." required value={captchaAnswer} onChange={(e) => setCaptchaAnswer(e.target.value)} />
            </div>

            <button type="submit" className={styles.button}>Masuk Sekarang</button>
            <button type="button" className={styles.mobileToggleBtn} onClick={() => setIsRightPanelActive(true)}>
              Belum punya Akun? Daftar di sini
            </button>
          </form>
        </div>

        {/* OVERLAY DESKTOP */}
        <div className={styles.overlayContainer}>
          <div className={styles.overlay}>
            <div className={`${styles.overlayPanel} ${styles.overlayLeft}`}>
              <img src="/education.svg" alt="Education" className={styles.illustration} />
              <h1 className={styles.title} style={{ color: 'white' }}>Sudah Punya Akun?</h1>
              <p className={styles.overlayParagraph}>Silakan login untuk memantau status pendaftaran.</p>
              <button type="button" className={`${styles.button} ${styles.buttonGhost}`} onClick={() => setIsRightPanelActive(false)}>Login</button>
            </div>
            <div className={`${styles.overlayPanel} ${styles.overlayRight}`}>
              <img src="/education.svg" alt="Education" className={styles.illustration} />
              <h1 className={styles.title} style={{ color: 'white' }}>Ahlan Wa Sahlan!</h1>
              <p className={styles.overlayParagraph}>Belum punya akun? Daftarkan putra/putri Anda sekarang.</p>
              <button type="button" className={`${styles.button} ${styles.buttonGhost}`} onClick={() => setIsRightPanelActive(true)}>Buat Akun SPMB</button>
            </div>
          </div>
        </div>
      </div>

      {/* MODAL TOKEN */}
      {showModal && (
        <div className={styles.modalOverlay} onClick={closeTokenModal}>
          <div className={styles.modalContent} onClick={(e) => e.stopPropagation()}>
            <div className={styles.modalHeader}>
              <h3 className={styles.modalTitle}>Dapatkan Token</h3>
              <button className={styles.modalCloseBtn} onClick={closeTokenModal}>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
              </button>
            </div>

            {modalStep === 'PILIH_JENJANG' && (
              <div className={styles.modalBody}>
                <p className={styles.modalDesc}>Pilih jenjang pendidikan yang ingin dituju.</p>
                <div className={styles.cardSelectGroup}>
                  <div className={styles.cardSelect} onClick={() => setModalStep('FORM_SMP')}>
                    <div className={styles.cardIconWrapper} style={{background: '#eff6ff', color: '#3b82f6'}}>
                      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line></svg>
                    </div>
                    <div className={styles.cardSelectText}>
                      <h4>SMPIT Al-Mubarak</h4>
                      <p>Token Dikirim ke Email Otomatis</p>
                    </div>
                  </div>

                  <div className={styles.cardSelect} onClick={() => setModalStep('INFO_SD')}>
                    <div className={styles.cardIconWrapper} style={{background: '#ecfdf5', color: '#10b981'}}>
                      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                    </div>
                    <div className={styles.cardSelectText}>
                      <h4>SDIT Al-Mubarak</h4>
                      <p>Verifikasi Manual via Admin</p>
                    </div>
                  </div>
                </div>
              </div>
            )}

            {modalStep === 'FORM_SMP' && (
              <form className={styles.modalBody} onSubmit={handleGenerateSMPToken}>
                <p className={styles.modalDesc}>Masukkan data diri singkat. Token akan dikirim ke email Anda.</p>
                <div className={styles.inputGroup} style={{marginBottom: '15px'}}>
                  <svg className={styles.icon} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                  <input className={styles.input} type="text" placeholder="Nama Calon Siswa SMPIT" required value={tokenForm.nama} onChange={(e) => setTokenForm({...tokenForm, nama: e.target.value})} />
                </div>
                <div className={styles.inputGroup}>
                  <svg className={styles.icon} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                  <input className={styles.input} type="email" placeholder="Email Aktif Orang Tua" required value={tokenForm.email} onChange={(e) => setTokenForm({...tokenForm, email: e.target.value})} />
                </div>
                <div style={{ display: 'flex', gap: '10px', marginTop: '20px' }}>
                  <button type="button" className={styles.btnModalOutline} onClick={() => setModalStep('PILIH_JENJANG')}>Kembali</button>
                  <button type="submit" className={styles.btnModalPrimary} disabled={isGenerating}>
                    {isGenerating ? 'Memproses...' : 'Kirim Token ke Email'}
                  </button>
                </div>
              </form>
            )}

            {modalStep === 'SUCCESS_SMP' && (
              <div className={styles.modalBody} style={{textAlign: 'center'}}>
                <div className={styles.successIconWrapper}>
                  <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </div>
                <h4 style={{fontSize: '20px', color: '#1a5f51', marginBottom: '10px', fontWeight: 800}}>Token Berhasil Dibuat!</h4>
                <p className={styles.modalDesc} style={{background: '#f0fdf4', color: '#059669', padding: '10px', borderRadius: '8px', border: '1px solid #a7f3d0'}}>
                  Tembusan token juga telah berhasil dikirimkan ke kotak masuk email <b>{tokenForm.email}</b>.
                </p>
                <div className={styles.tokenDisplayBox}>{generatedToken}</div>
                <button type="button" className={styles.btnModalPrimary} style={{width: '100%', marginTop: '20px'}} onClick={closeTokenModal}>
                  Tutup & Lanjutkan Daftar
                </button>
              </div>
            )}

            {modalStep === 'INFO_SD' && (
              <div className={styles.modalBody}>
                <div style={{background: '#f8fafc', padding: '20px', borderRadius: '16px', border: '1px solid #e2e8f0', marginBottom: '20px'}}>
                  <h4 style={{fontSize: '16px', color: '#1e293b', marginBottom: '10px', fontWeight: 700}}>Token Khusus SDIT</h4>
                  <p style={{fontSize: '13.5px', color: '#64748b', lineHeight: 1.6, margin: 0}}>
                    Silakan hubungi Admin Sekolah via WhatsApp untuk mendapatkan kode token Anda.
                  </p>
                </div>
                <div style={{ display: 'flex', gap: '10px' }}>
                  <button type="button" className={styles.btnModalOutline} onClick={() => setModalStep('PILIH_JENJANG')}>Kembali</button>
                  <button type="button" className={styles.btnModalWhatsApp} onClick={() => window.open('https://wa.me/6281234567890?text=Halo%20Admin...', '_blank')}>
                    Hubungi via WA
                  </button>
                </div>
              </div>
            )}

          </div>
        </div>
      )}

    </div>
  );
}