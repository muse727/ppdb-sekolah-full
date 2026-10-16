// src/app/cbt/page.tsx
'use client';

import { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import styles from './ujian.module.css';

import { isAxiosError } from 'axios';

interface Question {
    id: number;
    jenis_soal: 'PG' | 'ESAI';
    pertanyaan: string;
    gambar: string | null;
    opsi_a: string | null;
    opsi_b: string | null;
    opsi_c: string | null;
    opsi_d: string | null;
}

interface LoginResponse {
    student_name: string;
    student_email?: string;
    attempt_token: string;
    expires_at: string;
    session?: {
        nama_ujian: string;
        ruangan?: string;
    };
    questions: Question[];
}

/**
 * P4-B — the post-submit review screen is built entirely from data the student
 * already legitimately holds: the `questions` handed out at login plus the
 * student's own `answers`.
 *
 * This deliberately does NOT model the old `pembahasan` payload. That payload
 * carried `kunci_jawaban` (the answer key) straight to the student's browser,
 * and the server no longer returns it at all — so the previous review list was
 * dead code that rendered nothing. The review is now a recap of the student's
 * own responses, which is what the surrounding copy always claimed it was
 * ("Berikut adalah rekap jawaban Anda").
 */
interface ReviewQuestion extends Question {
    jawaban_siswa?: string;
}

interface SubmitResponse {
    skor: number;
    benar: number;
    salah: number;
}

export default function StudentCBTPage() {
    const [email, setEmail] = useState('');
    const [token, setToken] = useState('');
    const [studentName, setStudentName] = useState('');
    const [attemptToken, setAttemptToken] = useState('');
    
    const [isStarted, setIsStarted] = useState(false);
    const [questions, setQuestions] = useState<Question[]>([]);
    
    const [currentIndex, setCurrentIndex] = useState(0);
    const [answers, setAnswers] = useState<Record<number, string>>({});
    
    const examRef = useRef<HTMLDivElement>(null);

    const [cheatCount, setCheatCount] = useState(0);
    const [timeLeft, setTimeLeft] = useState(0); 
    const expiresAtRef = useRef(0);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const submittedRef = useRef(false);

    // STATE UNTUK LAYAR REVIEW HASIL
    const [showReview, setShowReview] = useState(false);
    const [reviewData, setReviewData] = useState<SubmitResponse | null>(null);

    useEffect(() => {
        if (!isStarted || showReview) return; // Matikan anti-nyontek kalau udah di layar review

        const handleBlur = () => {
            if (submittedRef.current) return;
            setCheatCount(prev => prev + 1);
            Swal.fire({ title: 'PELANGGARAN TERDETEKSI!', text: 'Anda keluar dari layar ujian. Tindakan ini dicatat oleh sistem!', icon: 'error', confirmButtonColor: '#1a5f51', confirmButtonText: 'Kembali', allowOutsideClick: false, allowEscapeKey: false });
        };
        const handleFullscreenChange = () => {
            if (submittedRef.current) return;
            if (!document.fullscreenElement && !showReview) {
                setCheatCount(prev => prev + 1);
                Swal.fire({ title: 'LAYAR PENUH DITUTUP!', text: 'Anda keluar dari mode layar penuh. Segera kembali!', icon: 'warning', confirmButtonText: 'Masuk Layar Penuh', confirmButtonColor: '#1a5f51', allowOutsideClick: false, allowEscapeKey: false })
                .then(() => document.documentElement.requestFullscreen().catch(console.log));
            }
        };
        const preventDefault = (e: Event) => e.preventDefault();
        
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

    const formatTime = (seconds: number) => {
        const m = Math.floor(seconds / 60).toString().padStart(2, '0');
        const s = (seconds % 60).toString().padStart(2, '0');
        return `${m}:${s}`;
    };

    const handleLogin = async (e: React.FormEvent) => {
        e.preventDefault();
        const cleanEmail = email.trim().toLowerCase();
        const cleanToken = token.trim().toUpperCase();

        if (!cleanEmail || !cleanToken) {
            Swal.fire('Data Belum Lengkap', 'Masukkan email pendaftar dan token ujian.', 'warning');
            return;
        }

        Swal.fire({ title: 'Memverifikasi Data...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            const res = await libAxios.post<LoginResponse>('/api/cbt/login', { 
                email: cleanEmail, 
                token: cleanToken 
            });
            setStudentName(res.data.student_name);
            setAttemptToken(res.data.attempt_token);
            
            setQuestions(res.data.questions);
            expiresAtRef.current = new Date(res.data.expires_at).getTime();
            setTimeLeft(Math.max(0, Math.ceil((expiresAtRef.current - Date.now()) / 1000)));

            Swal.fire({ 
                title: 'Konfirmasi Peserta', 
                html: `
                    <p style="font-size:14px;color:#4a5568;margin-bottom:12px">Pastikan identitas di bawah ini sesuai dengan data Ananda:</p>
                    <div style="background:#f0fdf4;border:1.5px solid #86efac;padding:14px 16px;border-radius:12px;text-align:left;font-size:14px;color:#166534">
                        <div style="margin-bottom:6px"><strong>Nama Peserta:</strong> ${res.data.student_name}</div>
                        <div style="margin-bottom:6px"><strong>Email:</strong> ${cleanEmail}</div>
                        <div><strong>Sesi:</strong> ${res.data.session?.nama_ujian || 'Ujian CBT'}</div>
                    </div>
                    <p style="font-size:12px;color:#718096;margin-top:12px;font-style:italic">Browser akan beralih ke mode layar penuh saat ujian dimulai.</p>
                `, 
                icon: 'question', 
                showCancelButton: true, 
                confirmButtonText: 'Ya, Mulai Ujian', 
                cancelButtonText: 'Batal / Bukan Saya',
                confirmButtonColor: '#1a5f51',
                cancelButtonColor: '#718096',
                allowOutsideClick: false
            }).then((result) => {
                if (result.isConfirmed) {
                    document.documentElement.requestFullscreen()
                        .then(() => setIsStarted(true))
                        .catch(() => setIsStarted(true));
                }
            });
        } catch (error) {
            Swal.fire('Gagal Masuk', isAxiosError<{ message?: string }>(error) ? error.response?.data?.message || 'Data pendaftar atau token tidak valid' : 'Data pendaftar atau token tidak valid', 'error');
        }
    };

    const handleAnswer = (opsi: string) => { setAnswers({ ...answers, [questions[currentIndex].id]: opsi }); };
    const handleTextAnswer = (text: string) => { setAnswers({ ...answers, [questions[currentIndex].id]: text }); };

    const handleSubmitUjian = useCallback(async () => {
        if (submittedRef.current) return;
        submittedRef.current = true;
        Swal.fire({ title: 'Mengoreksi Jawaban...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try {
            const res = await libAxios.post<SubmitResponse>('/api/cbt/submit', {
                attempt_token: attemptToken,
                jawaban: answers,
                pelanggaran: cheatCount
            });
            
            // Keluar Fullscreen dan tampilkan layar Review
            if (document.fullscreenElement) document.exitFullscreen();
            
            setReviewData(res.data);
            setShowReview(true);
            Swal.close();
        } catch {
            submittedRef.current = false;
            Swal.fire('Gagal Menyimpan', 'Terjadi kesalahan sistem, lapor ke pengawas!', 'error');
        }
    }, [attemptToken, answers, cheatCount]);

    useEffect(() => {
        if (!isStarted || showReview) return;
        // Send before the server deadline so network latency does not make an
        // otherwise timely attempt arrive after the authoritative expiry.
        if (timeLeft <= 5) {
            const timeout = setTimeout(() => { void handleSubmitUjian(); }, 0);
            return () => clearTimeout(timeout);
        }
        timerRef.current = setInterval(() => setTimeLeft(Math.max(0, Math.ceil((expiresAtRef.current - Date.now()) / 1000))), 1000);
        return () => { if (timerRef.current) clearInterval(timerRef.current); };
    }, [isStarted, showReview, timeLeft, handleSubmitUjian]);

    /**
     * P4-B: recap data is joined from state the student already legitimately
     * holds — the questions delivered at login and the student's own answers.
     * No field from a server "explanation" payload is used, because none is sent.
     */
    const reviewQuestions: ReviewQuestion[] = useMemo(
        () => questions.map((soal) => ({ ...soal, jawaban_siswa: answers[soal.id] })),
        [questions, answers]
    );

    // ==========================================
    // TAMPILAN LAYAR REVIEW HASIL (KAYA GOOGLE FORM)
    // ==========================================
    if (showReview && reviewData) {
        return (
            <div className={styles.reviewContainer}>
                <div className={styles.reviewHeader}>
                    <div className={styles.scoreCard}>
                        <h2>Skor Akhir Pilihan Ganda</h2>
                        <div className={styles.scoreNumber}>{Number(reviewData.skor).toFixed(0)}</div>
                        <div className={styles.scoreStats}>
                            <span className={styles.statBenar} style={{display: 'inline-flex', alignItems: 'center', gap: '4px'}}><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" style={{flexShrink: 0}}><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg> Benar: {reviewData.benar}</span>
                            <span className={styles.statSalah} style={{display: 'inline-flex', alignItems: 'center', gap: '4px'}}><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" style={{flexShrink: 0}}><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg> Salah: {reviewData.salah}</span>
                            <span style={{display: 'inline-flex', alignItems: 'center', gap: '4px', background: cheatCount > 0 ? '#fee2e2' : '#e6f0ee', color: cheatCount > 0 ? '#991b1b' : '#1a5f51', padding: '4px 10px', borderRadius: '8px', fontSize: '13px', fontWeight: 800}}>
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" style={{flexShrink: 0}}><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                                Pelanggaran: {cheatCount}x
                            </span>
                        </div>
                    </div>
                    <p className={styles.reviewThanks}>Ujian telah selesai. Terima kasih, <strong>{studentName}</strong>. Berikut adalah rekap jawaban Anda.</p>
                </div>

                {/*
                 * P4-B: the recap is derived locally from `questions` (already
                 * sent to the student at login) and `answers` (the student's own
                 * input). Nothing here reads an answer key — the server returns
                 * only the aggregate score, so per-question correctness is never
                 * disclosed to the student.
                 *
                 * Styling therefore stays neutral: `cardCorrect`/`cardWrong` and
                 * `reviewOptionCorrect`/`reviewOptionWrong` are deliberately not
                 * applied, because green/red would leak which options were right.
                 * The only highlighted option is the one the student selected.
                 */}
                <div className={styles.reviewList}>
                    {reviewQuestions.map((soal, idx: number) => (
                        <div key={soal.id} className={`${styles.reviewCard} ${soal.jenis_soal === 'PG' ? '' : styles.cardNeutral}`}>
                            <div className={styles.reviewSoalHeader}>
                                <span className={styles.questionNumber}>Soal No. {idx + 1}</span>
                                {soal.jenis_soal === 'PG' ? (
                                    soal.jawaban_siswa ?
                                    <span className={styles.badgeEsai}>Dijawab</span> :
                                    <span className={styles.badgeSalah}>Tidak Dijawab</span>
                                ) : (
                                    <span className={styles.badgeEsai}>Menunggu Koreksi Guru</span>
                                )}
                            </div>
                            
                            {/* P4-A.3 Group B — `soal.gambar` is an inline base64 data URI
                                produced by canvas.toDataURL() at upload time. Next.js forces
                                `unoptimized` for data:/blob: sources, so <Image> renders the
                                identical payload with no compression benefit. Kept as <img> to
                                preserve exact rendering semantics. */}
                            {/* eslint-disable-next-line @next/next/no-img-element */}
                            {soal.gambar && <img src={soal.gambar} alt="Soal" className={styles.soalImgReview} />}
                            <div className={styles.questionText}>{soal.pertanyaan}</div>

                            {soal.jenis_soal === 'PG' ? (
                                <div className={styles.reviewOptionsList}>
                                    {['A', 'B', 'C', 'D'].map((opsi) => {
                                        const isStudentAnswer = soal.jawaban_siswa === opsi;
                                        const keyOpsi = `opsi_${opsi.toLowerCase()}` as keyof Question;

                                        return (
                                            <div key={opsi} className={isStudentAnswer ? styles.reviewOptionChosen : styles.reviewOption}>
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
                    <p>Masukkan email yang didaftarkan ke sistem dan token ujian dari pengawas.</p>
                    <form onSubmit={handleLogin}>
                        <div style={{ textAlign: 'left', marginBottom: '16px' }}>
                            <label style={{ display: 'block', fontSize: '13px', fontWeight: 700, color: '#2d3748', marginBottom: '6px' }}>
                                Email Akun Pendaftar
                            </label>
                            <input 
                                type="email" 
                                className={styles.formInput} 
                                placeholder="nama@email.com" 
                                value={email} 
                                onChange={(e) => setEmail(e.target.value)} 
                                required 
                                autoFocus
                            />
                        </div>

                        <div style={{ textAlign: 'left', marginBottom: '20px' }}>
                            <label style={{ display: 'block', fontSize: '13px', fontWeight: 700, color: '#2d3748', marginBottom: '6px' }}>
                                Token Ujian (6 Karakter)
                            </label>
                            <input 
                                type="text" 
                                className={styles.tokenInput} 
                                placeholder="TOKEN" 
                                value={token} 
                                onChange={(e) => setToken(e.target.value.toUpperCase())} 
                                maxLength={6} 
                                required 
                            />
                        </div>

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
                <div className={styles.studentInfo}><span className={styles.badgeSiswa}>Peserta: {studentName}</span></div>
                {cheatCount > 0 && <div className={styles.cheatWarning}><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> Pelanggaran: {cheatCount}x</div>}
                <div className={styles.timerBox}><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Sisa Waktu: {formatTime(timeLeft)}</div>
            </div>

            <div className={styles.examLayout}>
                <div className={styles.questionSection}>
                    <div className={styles.questionNumber}>Soal No. {currentIndex + 1}</div>
                    
                    {currentQ?.gambar && (
                        <div className={styles.imageWrapper}>
                            {/* P4-A.3 Group B — inline base64 data URI (see the review
                                screen above); <Image> would pass it through unoptimized. */}
                            {/* eslint-disable-next-line @next/next/no-img-element */}
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