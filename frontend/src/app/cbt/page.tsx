// src/app/cbt/page.tsx
'use client';

import { useState, useEffect, useRef } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './ujian.module.css';

export default function StudentCBTPage() {
    const [token, setToken] = useState('');
    const [namaSiswa, setNamaSiswa] = useState('');
    
    const [isStarted, setIsStarted] = useState(false);
    const [sessionData, setSessionData] = useState<any>(null);
    const [questions, setQuestions] = useState<any[]>([]);
    
    const [currentIndex, setCurrentIndex] = useState(0);
    const [answers, setAnswers] = useState<any>({});
    
    const examRef = useRef<HTMLDivElement>(null);

    const [cheatCount, setCheatCount] = useState(0);
    const [timeLeft, setTimeLeft] = useState(0); 
    const timerRef = useRef<any>(null);

    // STATE UNTUK LAYAR REVIEW HASIL
    const [showReview, setShowReview] = useState(false);
    const [reviewData, setReviewData] = useState<any>(null);

    useEffect(() => {
        if (!isStarted || showReview) return; // Matikan anti-nyontek kalau udah di layar review

        const handleBlur = () => {
            setCheatCount(prev => prev + 1);
            Swal.fire({ title: 'PELANGGARAN TERDETEKSI!', text: 'Anda keluar dari layar ujian. Tindakan ini dicatat oleh sistem!', icon: 'error', confirmButtonColor: '#1a5f51', confirmButtonText: 'Kembali', allowOutsideClick: false, allowEscapeKey: false });
        };
        const handleFullscreenChange = () => {
            if (!document.fullscreenElement && !showReview) {
                setCheatCount(prev => prev + 1);
                Swal.fire({ title: 'LAYAR PENUH DITUTUP!', text: 'Anda keluar dari mode layar penuh. Segera kembali!', icon: 'warning', confirmButtonText: 'Masuk Layar Penuh', confirmButtonColor: '#1a5f51', allowOutsideClick: false, allowEscapeKey: false })
                .then(() => document.documentElement.requestFullscreen().catch(console.log));
            }
        };
        const preventDefault = (e: any) => e.preventDefault();
        
        window.addEventListener('blur', handleBlur);
        document.addEventListener('fullscreenchange', handleFullscreenChange);
        document.addEventListener('contextmenu', preventDefault); 
        document.addEventListener('copy', preventDefault); 

        return () => {
            window.removeEventListener('blur', handleBlur);
            document.removeEventListener('fullscreenchange', handleFullscreenChange);
            document.removeEventListener('contextmenu', preventDefault);
            document.removeEventListener('copy', preventDefault);
        };
    }, [isStarted, showReview]);

    useEffect(() => {
        if (isStarted && !showReview && timeLeft > 0) {
            timerRef.current = setInterval(() => {
                setTimeLeft(prev => {
                    if (prev <= 1) { clearInterval(timerRef.current); handleSubmitUjian(); return 0; }
                    return prev - 1;
                });
            }, 1000);
        }
        return () => clearInterval(timerRef.current);
    }, [isStarted, showReview, timeLeft]);

    const formatTime = (seconds: number) => {
        const m = Math.floor(seconds / 60).toString().padStart(2, '0');
        const s = (seconds % 60).toString().padStart(2, '0');
        return `${m}:${s}`;
    };

    const handleLogin = async (e: React.FormEvent) => {
        e.preventDefault();
        Swal.fire({ title: 'Menyiapkan Soal...', didOpen: () => Swal.showLoading() });
        try {
            const res = await libAxios.post('/api/cbt/login', { token, nama_siswa: namaSiswa });
            const session = res.data.session;
            
            const start = new Date(`2000-01-01T${session.jam_mulai}`);
            const end = new Date(`2000-01-01T${session.jam_selesai}`);
            const durasiDetik = (end.getTime() - start.getTime()) / 1000;
            
            setSessionData(session);
            setQuestions(res.data.questions);
            setTimeLeft(durasiDetik > 0 ? durasiDetik : 3600); 

            Swal.fire({ title: 'Siap Memulai?', text: 'Waktu akan berjalan dan browser akan masuk mode layar penuh.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Mulai Ujian', confirmButtonColor: '#1a5f51' })
            .then((result) => {
                if (result.isConfirmed) {
                    document.documentElement.requestFullscreen().then(() => setIsStarted(true)).catch(() => Swal.fire('Error', 'Gagal masuk fullscreen', 'error'));
                }
            });
        } catch (error: any) {
            Swal.fire('Gagal Masuk', error.response?.data?.message || 'Token tidak valid', 'error');
        }
    };

    const handleAnswer = (opsi: string) => { setAnswers({ ...answers, [questions[currentIndex].id]: opsi }); };
    const handleTextAnswer = (text: string) => { setAnswers({ ...answers, [questions[currentIndex].id]: text }); };

    const handleSubmitUjian = async () => {
        Swal.fire({ title: 'Mengoreksi Jawaban...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            const res = await libAxios.post('/api/cbt/submit', {
                session_id: sessionData.id,
                nama_siswa: namaSiswa,
                jawaban: answers,
                pelanggaran: cheatCount
            });
            
            // Keluar Fullscreen dan tampilkan layar Review
            if (document.fullscreenElement) document.exitFullscreen();
            
            setReviewData(res.data);
            setShowReview(true);
            Swal.close();
        } catch (error) {
            Swal.fire('Gagal Menyimpan', 'Terjadi kesalahan sistem, lapor ke pengawas!', 'error');
        }
    };

    // ==========================================
    // TAMPILAN LAYAR REVIEW HASIL (KAYA GOOGLE FORM)
    // ==========================================
    if (showReview && reviewData) {
        return (
            <div className={styles.reviewContainer}>
                <div className={styles.reviewHeader}>
                    <div className={styles.scoreCard}>
                        <h2>Skor Akhir Pilihan Ganda</h2>
                        <div className={styles.scoreNumber}>{reviewData.skor.toFixed(0)}</div>
                        <div className={styles.scoreStats}>
                            <span className={styles.statBenar}>✓ Benar: {reviewData.benar}</span>
                            <span className={styles.statSalah}>✖ Salah: {reviewData.salah}</span>
                        </div>
                    </div>
                    <p className={styles.reviewThanks}>Ujian telah selesai. Terima kasih, <strong>{namaSiswa}</strong>. Berikut adalah rekap jawaban Anda.</p>
                </div>

                <div className={styles.reviewList}>
                    {reviewData.pembahasan.map((soal: any, idx: number) => (
                        <div key={soal.id} className={`${styles.reviewCard} ${soal.jenis_soal === 'PG' ? (soal.is_correct ? styles.cardCorrect : styles.cardWrong) : styles.cardNeutral}`}>
                            <div className={styles.reviewSoalHeader}>
                                <span className={styles.questionNumber}>Soal No. {idx + 1}</span>
                                {soal.jenis_soal === 'PG' ? (
                                    soal.is_correct ? 
                                    <span className={styles.badgeBenar}>Benar</span> : 
                                    <span className={styles.badgeSalah}>Salah</span>
                                ) : (
                                    <span className={styles.badgeEsai}>Menunggu Koreksi Guru</span>
                                )}
                            </div>
                            
                            {soal.gambar && <img src={soal.gambar} alt="Soal" className={styles.soalImgReview} />}
                            <div className={styles.questionText}>{soal.pertanyaan}</div>

                            {soal.jenis_soal === 'PG' ? (
                                <div className={styles.reviewOptionsList}>
                                    {['A', 'B', 'C', 'D'].map((opsi) => {
                                        const isStudentAnswer = soal.jawaban_siswa === opsi;
                                        const isCorrectAnswer = soal.kunci_jawaban === opsi;
                                        const keyOpsi = `opsi_${opsi.toLowerCase()}`;
                                        
                                        let optionClass = styles.reviewOption;
                                        if (isCorrectAnswer) optionClass = styles.reviewOptionCorrect;
                                        else if (isStudentAnswer && !isCorrectAnswer) optionClass = styles.reviewOptionWrong;

                                        return (
                                            <div key={opsi} className={optionClass}>
                                                <div className={styles.reviewOptionLetter}>{opsi}</div>
                                                <div className={styles.reviewOptionText}>{soal[keyOpsi]}</div>
                                                {isStudentAnswer && <span className={styles.yourAnswerBadge}>Jawaban Anda</span>}
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className={styles.reviewEsaiBox}>
                                    <strong>Jawaban Anda:</strong>
                                    <p>{soal.jawaban_siswa || <em>(Tidak dijawab)</em>}</p>
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                <div className={styles.reviewFooter}>
                    <button className={styles.logoutBtn} onClick={() => window.location.href = '/'}>
                        Kembali ke Halaman Utama
                    </button>
                </div>
            </div>
        );
    }


    // ==========================================
    // TAMPILAN LOGIN
    // ==========================================
    if (!isStarted) {
        return (
            <div className={styles.loginContainer}>
                <div className={styles.loginCard}>
                    <div className={styles.logoBox}><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#1a5f51" strokeWidth="2.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg></div>
                    <h1>Portal Ujian CBT</h1>
                    <p>Masukkan token dan nama Anda dengan benar.</p>
                    <form onSubmit={handleLogin}>
                        <input type="text" className={styles.formInput} placeholder="NAMA LENGKAP" value={namaSiswa} onChange={(e) => setNamaSiswa(e.target.value)} required />
                        <input type="text" className={styles.tokenInput} placeholder="TOKEN UJIAN" value={token} onChange={(e) => setToken(e.target.value.toUpperCase())} maxLength={6} required />
                        <button type="submit" className={styles.loginBtn}>MASUK UJIAN</button>
                    </form>
                </div>
            </div>
        );
    }

    // ==========================================
    // TAMPILAN ENGINE UJIAN
    // ==========================================
    const currentQ = questions[currentIndex];

    return (
        <div className={styles.examContainer} ref={examRef}>
            <div className={styles.examHeader}>
                <div className={styles.studentInfo}><span className={styles.badgeSiswa}>Peserta: {namaSiswa}</span></div>
                {cheatCount > 0 && <div className={styles.cheatWarning}><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> Pelanggaran: {cheatCount}x</div>}
                <div className={styles.timerBox}><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Sisa Waktu: {formatTime(timeLeft)}</div>
            </div>

            <div className={styles.examLayout}>
                <div className={styles.questionSection}>
                    <div className={styles.questionNumber}>Soal No. {currentIndex + 1}</div>
                    
                    {currentQ?.gambar && (
                        <div className={styles.imageWrapper}>
                            <img src={currentQ.gambar} alt="Gambar Soal" className={styles.soalImg} />
                        </div>
                    )}

                    <div className={styles.questionText}>{currentQ?.pertanyaan}</div>
                    
                    {currentQ?.jenis_soal === 'PG' ? (
                        <div className={styles.optionsList}>
                            {['A', 'B', 'C', 'D'].map((opsi) => {
                                const keyOpsi = `opsi_${opsi.toLowerCase()}` as keyof typeof currentQ;
                                const isActive = answers[currentQ.id] === opsi;
                                return (
                                    <div key={opsi} className={`${styles.optionCard} ${isActive ? styles.optionActive : ''}`} onClick={() => handleAnswer(opsi)}>
                                        <div className={styles.optionLetter}>{opsi}</div>
                                        <div className={styles.optionText}>{currentQ[keyOpsi]}</div>
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <textarea className={styles.esaiInput} placeholder="Ketik jawaban esai Anda di sini..." value={answers[currentQ?.id] || ''} onChange={(e) => handleTextAnswer(e.target.value)} />
                    )}

                    <div className={styles.navigationBtns}>
                        <button className={styles.navBtn} disabled={currentIndex === 0} onClick={() => setCurrentIndex(prev => prev - 1)}>Soal Sebelumnya</button>
                        {currentIndex < questions.length - 1 ? (
                            <button className={styles.navBtnPrimary} onClick={() => setCurrentIndex(prev => prev + 1)}>Soal Selanjutnya</button>
                        ) : (
                            <button className={styles.submitBtn} onClick={() => {
                                Swal.fire({ title: 'Akhiri Ujian?', text: 'Pastikan semua jawaban telah terisi!', icon: 'question', showCancelButton: true, confirmButtonColor: '#1a5f51' }).then((res) => { if(res.isConfirmed) handleSubmitUjian(); });
                            }}>Selesai Ujian</button>
                        )}
                    </div>
                </div>

                <div className={styles.sidebarSection}>
                    <h3 className={styles.sidebarTitle}>Navigasi Soal</h3>
                    <div className={styles.numberGrid}>
                        {questions.map((q, idx) => (
                            <button key={q.id} className={`${styles.numBox} ${answers[q.id] ? styles.numAnswered : ''} ${currentIndex === idx ? styles.numCurrent : ''}`} onClick={() => setCurrentIndex(idx)}>
                                {idx + 1}
                            </button>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}