'use client';

import React, { useState, useRef, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import gsap from 'gsap';
import { useGSAP } from '@gsap/react';
import Swal from 'sweetalert2';
import libAxios from '@/lib/axios';
import type { AntreanObservasi, KategoriObservasi, KomponenObservasi } from '@/lib/domain';
import { beginObservasiIdempotentOp, clearObservasiIdempotentOp } from '@/lib/observasiIdempotency';
import { handleObservasiSubmitError } from '@/lib/observasiSubmitErrors';
import styles from './observasi.module.css';

// Jawaban observasi per komponen — bentuk yang dikirim sebagai jawaban_json.
interface ObservasiAnswer {
    jawaban: string;
    skor: number;
    catatan: string;
    kategori: string | null;
    keterampilan: string | null;
}

// Soal "flatten" untuk kartu penilaian (dari komponen instrumen).
interface FlatSoal {
    id: number;
    kategori: string;
    kode: string;
    keterampilan: string | null;
    title: string | null;
    petunjuk: string | null;
    lampiran: string | null;
    saran: string | null;
}

// E.12D — INSTRUMEN_MOCK DIHAPUS. Instrumen observasi SD mengacu pada kode
// yang tercetak pada instrumen FISIK (kertas); arena ini hanya merekam hasil
// berdasarkan instrumen yang benar-benar terdaftar. Sebelumnya, jika API gagal
// atau data kosong, arena memakai satu soal contoh ("Soal Kosong", kode K1)
// dan guru BISA menyelesaikan tes palsu atas nama calon murid nyata —
// hasilnya tersimpan ke database, terhitung dalam skor, muncul di rekapan,
// dan tercetak di PDF resmi. Sekarang: data kosong → tampilan kosong yang
// jelas; API gagal → pesan error yang jelas. Tidak ada hasil observasi
// sintetis yang bisa tersimpan.

export default function ObservasiPage() {
    const router = useRouter();
    
    const [students, setStudents] = useState<AntreanObservasi[]>([]);
    const [questions, setQuestions] = useState<FlatSoal[]>([]);
    const [isLoadingData, setIsLoadingData] = useState(true);
    // E.12D: dibedakan eksplisit — "API gagal" vs "belum ada instrumen".
    // Keduanya menampilkan pesan berbeda dan keduanya memblokir mulai tes.
    const [loadError, setLoadError] = useState<string | null>(null);
    
    const [filterStatus, setFilterStatus] = useState('BELUM'); 
    const [searchTerm, setSearchTerm] = useState('');

    const [selectedStudent, setSelectedStudent] = useState<AntreanObservasi | null>(null);
    const [isStarted, setIsStarted] = useState(false);
    const [currentIndex, setCurrentIndex] = useState(0);
    const [answers, setAnswers] = useState<Record<string, ObservasiAnswer>>({});
    const [catatan, setCatatan] = useState('');
    const [isRecording, setIsRecording] = useState(false);
    
    const [showSummary, setShowSummary] = useState(false);
    const [isTransitioning, setIsTransitioning] = useState(false);
    const [transitionText, setTransitionText] = useState('Melanjutkan!');

    const introRef = useRef<HTMLDivElement>(null);
    const cardRef = useRef<HTMLDivElement>(null);
    const swipeRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const fetchData = async () => {
            // E.12D: try/catch di setiap request secara terpisah — kegagalan
            // salah satu API tidak lagi disamarkan menjadi "data kosong"
            // (dulu: .catch(() => ({ data: [] })) membuat error jaringan
            // tampak seperti "tidak ada antrean/instrumen").
            try {
                const resSiswa = await libAxios.get<AntreanObservasi[]>('/api/guru/antrean-observasi');
                setStudents(Array.isArray(resSiswa.data) ? resSiswa.data : []);
            } catch {
                setLoadError('Gagal memuat antrean calon murid. Periksa koneksi lalu muat ulang halaman.');
                setIsLoadingData(false);
                return;
            }

            try {
                // E.12D: instrumen observasi adalah instrumen SD (fisik,
                // berkode). Filter jenjang eksplisit agar arena observasi
                // tidak pernah memuat — apalagi menilai dengan — komponen
                // jenjang lain. Alur CBT SMP terpisah dan tidak tersentuh.
                const resInstrumen = await libAxios.get<KategoriObservasi[]>('/api/guru/instrumen/kategori?jenjang=SD');
                const validInstrumen: KategoriObservasi[] = Array.isArray(resInstrumen.data) ? resInstrumen.data : [];

                const flatSoal: FlatSoal[] = [];
                validInstrumen.forEach((kat: KategoriObservasi) => {
                    if (kat.komponen && Array.isArray(kat.komponen)) {
                        kat.komponen.forEach((komp: KomponenObservasi) => {
                            flatSoal.push({
                                id: komp.id,
                                kategori: kat.nama_kategori,
                                kode: komp.kode,
                                keterampilan: komp.keterampilan,
                                title: komp.deskripsi,
                                petunjuk: komp.petunjuk,
                                lampiran: komp.lampiran,
                                saran: komp.saran_jika_kurang
                            });
                        });
                    }
                });
                // E.12D: instrumen kosong → questions kosong (bukan mock). UI
                // di bawah menampilkan pesan "belum ada instrumen" dan tidak
                // memungkinkan memulai tes atas calon murid nyata.
                setQuestions(flatSoal);
            } catch {
                setLoadError('Gagal memuat data instrumen observasi SD. Periksa koneksi lalu muat ulang halaman.');
                setIsLoadingData(false);
                return;
            }

            setIsLoadingData(false);
        };
        fetchData();
    }, []);

    useGSAP(() => {
        gsap.to('.floating-item', { y: '-=20', duration: 3, yoyo: true, repeat: -1, ease: "sine.inOut", stagger: 0.2 });
        if (!isStarted && selectedStudent) {
            const tl = gsap.timeline();
            tl.to('.intro-anim', { y: 0, opacity: 1, duration: 0.8, stagger: 0.2, ease: "back.out(1.7)" })
              .to('.start-btn', { scale: 1, opacity: 1, duration: 0.5, ease: "elastic.out(1, 0.3)" });
        }
    }, [isStarted, selectedStudent]);

    const handleStart = () => { gsap.to(introRef.current, { y: '-100%', opacity: 0, duration: 0.6, ease: "power3.inOut", onComplete: () => setIsStarted(true) }); };

    const triggerGameTransition = (callback: () => void, text: string) => {
        if (isTransitioning) return;
        setIsTransitioning(true); setTransitionText(text);
        const tl = gsap.timeline();
        tl.to(swipeRef.current, { left: 0, duration: 0.4, ease: "power3.inOut" })
          .call(() => callback())
          .to(swipeRef.current, { left: '100%', duration: 0.4, delay: 0.3, ease: "power3.inOut", onComplete: () => {
              gsap.set(swipeRef.current, { left: '-100%' }); setIsTransitioning(false);
          }});
    };

    const toggleMic = () => {
        // Web Speech API — tidak ada di semua TS DOM lib; batasi di satu helper.
        type SpeechRecognitionLike = {
            lang: string; interimResults: boolean;
            onstart: (() => void) | null;
            onresult: ((event: { results: { transcript: string }[][] }) => void) | null;
            onerror: (() => void) | null;
            onend: (() => void) | null;
            start: () => void;
        };
        const w = window as unknown as { SpeechRecognition?: new () => SpeechRecognitionLike; webkitSpeechRecognition?: new () => SpeechRecognitionLike };
        const SpeechRecognition = w.SpeechRecognition || w.webkitSpeechRecognition;
        if (!SpeechRecognition) { Swal.fire('Oops!', 'Gunakan Chrome/Edge.', 'info'); return; }
        if (isRecording) { setIsRecording(false); return; }
        const recognition = new SpeechRecognition();
        recognition.lang = 'id-ID'; recognition.interimResults = false;
        recognition.onstart = () => setIsRecording(true);
        recognition.onresult = (e) => setCatatan((prev) => prev ? prev + ' ' + e.results[0][0].transcript : e.results[0][0].transcript);
        recognition.onerror = () => setIsRecording(false);
        recognition.onend = () => setIsRecording(false);
        recognition.start();
    };

    const handleAnswer = (value: string) => {
        if(questions.length === 0) return;
        const currentSoal = questions[currentIndex];
        
        let skor = 0;
        if (value === 'YA') skor = 2;
        else if (value === 'TIDAK KONSISTEN') skor = 1;
        else skor = 0;
        
        setAnswers((prev) => ({ 
            ...prev, 
            [currentSoal.kode]: { 
                jawaban: value, 
                skor: skor,
                catatan: catatan,
                kategori: currentSoal.kategori,
                keterampilan: currentSoal.keterampilan || currentSoal.title
            } 
        }));
        
        setCatatan(''); 
        if (currentIndex < questions.length - 1) triggerGameTransition(() => setCurrentIndex(currentIndex + 1), "Berikutnya!");
        else triggerGameTransition(() => setShowSummary(true), "Review Penilaian");
    };

    const handlePrev = () => {
        if (currentIndex > 0) {
            setCatatan(answers[questions[currentIndex - 1].kode]?.catatan || '');
            triggerGameTransition(() => setCurrentIndex(currentIndex - 1), "Kembali");
        } else {
            Swal.fire({
                title: 'Batalkan Tes?',
                text: 'Data observasi yang belum dikirim akan hilang.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e53e3e',
                cancelButtonColor: '#cbd5e0',
                confirmButtonText: 'Ya, Batalkan',
                cancelButtonText: 'Lanjut Tes'
            }).then((result) => {
                if(result.isConfirmed) {
                    triggerGameTransition(() => {
                        setIsStarted(false);
                        setSelectedStudent(null);
                        setAnswers({});
                        setCurrentIndex(0);
                    }, "Membatalkan...");
                }
            });
        }
    };

    const selectStudent = (siswa: AntreanObservasi) => {
        // E.12D: tanpa instrumen terdaftar tidak ada tes yang sah untuk
        // dimulai — kode instrumen fisik SD harus sudah terdaftar dulu di
        // halaman Instrumen. Blokir di sini, bukan membiarkan sesi kosong.
        if (questions.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Belum Ada Instrumen',
                text: 'Instrumen observasi SD belum terdaftar di aplikasi. Daftarkan kode instrumen fisik terlebih dahulu melalui menu Instrumen sebelum memulai tes.',
                confirmButtonColor: '#1a5f51',
            });
            return;
        }
        triggerGameTransition(() => setSelectedStudent(siswa), siswa.name);
    };

    const submitFinal = () => {
        Swal.fire({ title: 'Kirim Penilaian?', text: 'Data akan disimpan ke database', icon: 'question', showCancelButton: true, confirmButtonColor: '#1a5f51', confirmButtonText: 'Ya, Kirim!'
        }).then(async (result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Menyimpan...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                try {
                    if (!selectedStudent) return;
                    // E.12G Phase 4 — idempotent submit: key persisted BEFORE the
                    // request; a refresh mid-flight retries with the SAME key so
                    // the server replays the stored response instead of double-
                    // writing the hasil or double-bumping the version.
                    const payload = { user_id: selectedStudent.id, jawaban_json: answers };
                    const idemKey = beginObservasiIdempotentOp('observasi_submit', selectedStudent.id, payload);
                    const resp = await libAxios.post('/api/guru/observasi/submit', payload, {
                        headers: { 'Idempotency-Key': idemKey },
                    });
                    clearObservasiIdempotentOp('observasi_submit', selectedStudent.id);
                    Swal.fire('Berhasil!', 'Penilaian tersimpan.', 'success').then(() => window.location.reload());
                } catch (err: unknown) {
                    handleObservasiSubmitError(err);
                }
            }
        });
    };

    if (isLoadingData) return <div style={{ height: '100vh', width: '100vw', display: 'flex', justifyContent: 'center', alignItems: 'center', backgroundColor: '#ffffff', color: '#1a5f51', fontWeight: 'bold', position: 'fixed', top: 0, left: 0, zIndex: 900 }}>Menyiapkan Arena Tes...</div>;

    // E.12D: kegagalan API ditampilkan eksplisit — tidak pernah disamarkan
    // menjadi "data kosong" yang bisa disalahartikan guru.
    if (loadError) return (
        <div style={{ height: '100vh', width: '100vw', display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', backgroundColor: '#ffffff', color: '#c53030', fontWeight: 'bold', position: 'fixed', top: 0, left: 0, zIndex: 900, gap: '16px', padding: '24px', textAlign: 'center' }}>
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#c53030" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            <h2 style={{ margin: 0 }}>Gagal Memuat Data</h2>
            <p style={{ color: '#4a5568', fontWeight: 'normal', maxWidth: '480px', margin: 0 }}>{loadError}</p>
            <button onClick={() => window.location.reload()} style={{ padding: '12px 24px', backgroundColor: '#1a5f51', color: 'white', border: 'none', borderRadius: '8px', fontWeight: 'bold', cursor: 'pointer' }}>Muat Ulang</button>
        </div>
    );

    // E.12D: instrumen kosong → tampilan kosong yang jelas + tombol ke
    // halaman Instrumen. Tidak ada tes palsu yang bisa dimulai.
    if (questions.length === 0) return (
        <div style={{ height: '100vh', width: '100vw', display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', backgroundColor: '#ffffff', color: '#1a5f51', position: 'fixed', top: 0, left: 0, zIndex: 900, gap: '16px', padding: '24px', textAlign: 'center' }}>
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#1a5f51" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
            <h2 style={{ margin: 0 }}>Belum Ada Instrumen Observasi</h2>
            <p style={{ color: '#4a5568', maxWidth: '480px', margin: 0 }}>Instrumen observasi SD (berdasarkan kode yang tercetak pada instrumen fisik) belum terdaftar di aplikasi. Silakan daftarkan kategori dan komponen instrumen terlebih dahulu.</p>
            <button onClick={() => router.push('/guru/instrumen')} style={{ padding: '12px 24px', backgroundColor: '#1a5f51', color: 'white', border: 'none', borderRadius: '8px', fontWeight: 'bold', cursor: 'pointer' }}>Buka Halaman Instrumen</button>
        </div>
    );

    const currentSoal = questions[currentIndex];
    const progress = questions.length > 0 ? ((currentIndex + 1) / questions.length) * 100 : 0;

    const filteredStudents = students.filter(s => {
        const matchStatus = filterStatus === 'BELUM' ? !s.sudah_dites : filterStatus === 'SELESAI' ? s.sudah_dites : true;
        const matchSearch = s.name.toLowerCase().includes(searchTerm.toLowerCase()) || s.id.toString().includes(searchTerm);
        return matchStatus && matchSearch;
    });

    return (
        <div className={styles.gameContainer}>
            <div ref={swipeRef} className={styles.swipeOverlay}><h2>{transitionText}</h2></div>

            <div className={styles.gameBackground}>
                <div className={`${styles.ornament} floating-item`} style={{ top: '8%', left: '5%', width: '80px' }}>
                    <svg viewBox="0 0 100 100" fill="none" stroke="rgba(26,188,156,0.3)" strokeWidth="6"><circle cx="50" cy="50" r="40"/></svg>
                </div>
                <div className={`${styles.ornament} floating-item`} style={{ top: '60%', left: '10%', width: '90px' }}>
                    <svg viewBox="0 0 100 100" fill="none" stroke="rgba(26,188,156,0.3)" strokeWidth="6" strokeLinecap="round" strokeLinejoin="round"><polygon points="50,15 90,85 10,85"/></svg>
                </div>
                <div className={`${styles.ornament} floating-item`} style={{ top: '15%', right: '8%', width: '100px' }}>
                    <svg viewBox="0 0 100 100" fill="none" stroke="rgba(26,188,156,0.3)" strokeWidth="6"><rect x="20" y="20" width="60" height="60" rx="10"/></svg>
                </div>
                <div className={`${styles.ornament} floating-item`} style={{ bottom: '10%', right: '5%', width: '120px' }}>
                     <svg viewBox="0 0 100 100" fill="none" stroke="rgba(26,188,156,0.3)" strokeWidth="6"><path d="M50 10 L50 90 M10 50 L90 50" strokeLinecap="round"/></svg>
                </div>
                <div className={`${styles.ornament} floating-item`} style={{ bottom: '20%', left: '30%', width: '60px' }}>
                    <svg viewBox="0 0 100 100" fill="none" stroke="rgba(26,188,156,0.3)" strokeWidth="6" strokeLinecap="round" strokeLinejoin="round"><polyline points="10,50 30,20 70,80 90,50"/></svg>
                </div>
            </div>

            {!selectedStudent && (
                <div className={styles.studentSelectionScreen}>
                    <div className={styles.topNav}>
                         <button onClick={() => router.push('/guru')} className={styles.backDashBtn}>
                             <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                             Kembali ke Dashboard
                         </button>
                    </div>
                    
                    <div className={styles.selectionHeader}>
                        <h1>Pilih Peserta Didik</h1>
                        
                        <div className={styles.searchContainer}>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#a0aec0" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input 
                                type="text" 
                                className={styles.searchInput} 
                                placeholder="Cari nama atau No. Reg siswa..." 
                                value={searchTerm} 
                                onChange={(e) => setSearchTerm(e.target.value)} 
                            />
                        </div>

                        <div className={styles.filterGroup}>
                            <button className={`${styles.filterBtn} ${filterStatus === 'BELUM' ? styles.filterActive : ''}`} onClick={() => setFilterStatus('BELUM')}>Belum Tes</button>
                            <button className={`${styles.filterBtn} ${filterStatus === 'SELESAI' ? styles.filterActive : ''}`} onClick={() => setFilterStatus('SELESAI')}>Sudah Tes</button>
                            <button className={`${styles.filterBtn} ${filterStatus === 'SEMUA' ? styles.filterActive : ''}`} onClick={() => setFilterStatus('SEMUA')}>Semua Siswa</button>
                        </div>
                    </div>
                    
                    {filteredStudents.length === 0 ? (
                        <div className={styles.emptyStateContainer}>
                            <div style={{ marginBottom: '20px', display: 'flex', justifyContent: 'center' }}>
                                <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#1a5f51" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"></path><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            </div>
                            <h3 style={{ color: '#1a5f51', fontSize: '22px', margin: '0 0 10px 0' }}>Tidak Ada Data</h3>
                            <p style={{ color: '#4a5568' }}>Siswa yang dicari tidak ditemukan atau belum terdaftar.</p>
                        </div>
                    ) : (
                        <div className={styles.studentGrid}>
                            {filteredStudents.map((siswa) => (
                                <div key={siswa.id} className={styles.studentCard} onClick={() => selectStudent(siswa)}>
                                    {siswa.sudah_dites && (
                                        <div className={styles.badgeSelesai}>Selesai Dites</div>
                                    )}
                                    <div className={styles.studentAvatar}>
                                        <svg width="50" height="50" viewBox="0 0 24 24" fill="none" stroke="#1a5f51" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                    </div>
                                    <h2>{siswa.name}</h2>
                                    <p className={styles.studentID}>Reg: #{siswa.id}</p>
                                    <button className={`${styles.startBtnSmall} ${siswa.sudah_dites ? styles.btnUlang : ''}`}>
                                        {siswa.sudah_dites ? 'Tes Ulang Siswa' : 'Mulai Tes'}
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {selectedStudent && !isStarted && (
                <div ref={introRef} className={styles.introScreen}>
                    <div className={`${styles.introIcon} intro-anim`}>
                        <svg viewBox="0 0 200 200" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="100" cy="100" r="100" fill="#1a5f51" fillOpacity="0.2"/>
                            <path d="M100 40L140 100L100 160L60 100L100 40Z" fill="#1a5f51" stroke="#1a5f51" strokeWidth="8" strokeLinejoin="round"/>
                        </svg>
                    </div>
                    <h1 className={`${styles.introTitle} intro-anim`}>Sistem Penilaian</h1>
                    <p className={`${styles.introDesc} intro-anim`}>Siapkan alat peraga Anda. Mari observasi anak <strong>{selectedStudent.name}</strong>.</p>
                    <button className={`${styles.startBtn} start-btn`} onClick={handleStart}>MULAI TES SEKARANG</button>
                </div>
            )}

            {isStarted && showSummary && selectedStudent && (
                <div className={styles.summaryBoard}>
                    <div className={styles.summaryHeader}>
                        <h2>Rangkuman Penilaian</h2>
                        <p>Peserta: <strong>{selectedStudent.name}</strong></p>
                    </div>
                    <div className={styles.summaryList}>
                        {questions.map((soal, i) => (
                            <div key={soal.id} className={styles.summaryItem}>
                                <div className={styles.sumLeft}>
                                    <span className={styles.sumKode}>{soal.kode}</span>
                                    <div>
                                        <h4>{soal.keterampilan || soal.title}</h4>
                                        {answers[soal.kode]?.catatan && <p className={styles.sumNote}>Catatan: &quot;{answers[soal.kode].catatan}&quot;</p>}
                                    </div>
                                </div>
                                <div className={styles.sumRight}>
                                    <span className={`${styles.sumAns} ${answers[soal.kode]?.jawaban === 'YA' ? styles.sumAnsYa : answers[soal.kode]?.jawaban === 'TIDAK' ? styles.sumAnsTidak : styles.sumAnsRagu}`}>
                                        {answers[soal.kode]?.jawaban || 'KOSONG'}
                                    </span>
                                    <button className={styles.editBtn} onClick={() => triggerGameTransition(() => { setShowSummary(false); setCurrentIndex(i); }, `Edit Soal ${soal.kode}`)}>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className={styles.summaryActions}>
                        <button className={styles.cancelBtn} onClick={() => triggerGameTransition(() => setShowSummary(false), "Kembali")}>Cek Ulang</button>
                        <button className={styles.submitFinalBtn} onClick={submitFinal}>KIRIM & SIMPAN HASIL</button>
                    </div>
                </div>
            )}

            {isStarted && !showSummary && selectedStudent && currentSoal && (
                <div className={styles.mainBoard}>
                    <div className={styles.headerGlass}>
                        <button className={styles.cancelTestBtn} onClick={handlePrev}>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            {currentIndex === 0 ? 'Batal' : 'Kembali'}
                        </button>
                        <div className={styles.headerInfo}>
                            <h2>{selectedStudent.name}</h2>
                            <div className={styles.progressBarWrapper}>
                                <div className={styles.progressBarFill} style={{ width: `${progress}%` }}></div>
                            </div>
                            <p>Pertanyaan {currentIndex + 1} / {questions.length}</p>
                        </div>
                    </div>

                    <div className={styles.centerStage}>
                        <div ref={cardRef} className={styles.quizCard}>
                            <div className={styles.kategoriBadge}>{currentSoal.kategori} ({currentSoal.kode})</div>
                            
                            {currentSoal.lampiran && (
                                <div className={styles.lampiranBadge}>
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{marginRight: '6px', verticalAlign: 'middle'}}><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                    Siapkan: {currentSoal.lampiran}
                                </div>
                            )}

                            <h2 className={styles.soalKeterampilan}>{currentSoal.keterampilan || 'Keterampilan Belum Diisi'}</h2>
                            <p className={styles.soalDesc}>{currentSoal.title}</p>
                            
                            {currentSoal.petunjuk && (
                                <div className={styles.petunjukBox}>
                                    <span style={{display:'flex', alignItems:'center', gap:'5px', marginBottom:'5px', color:'#1a5f51', fontWeight: '800'}}>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                        Petunjuk Pelaksanaan
                                    </span>
                                    {currentSoal.petunjuk}
                                </div>
                            )}
                            
                            <div className={styles.catatanWrapper}>
                                <textarea className={styles.catatanInput} placeholder="Tuliskan catatan jika ada (opsional)..." value={catatan} onChange={(e) => setCatatan(e.target.value)} />
                                <button className={`${styles.micBtn} ${isRecording ? styles.recording : ''}`} onClick={toggleMic} title="Dikte dengan Suara">
                                    {isRecording ? <svg width="20" height="20" viewBox="0 0 24 24" fill="white" stroke="white" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg> : <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>}
                                </button>
                            </div>

                            <div className={styles.actionGrid}>
                                <button className={`${styles.ansBtn} ${styles.ansYa}`} onClick={() => handleAnswer('YA')}>
                                    <span className={styles.pillLetter}>A</span> Mampu Melakukan
                                </button>
                                <button className={`${styles.ansBtn} ${styles.ansRagu}`} onClick={() => handleAnswer('TIDAK KONSISTEN')}>
                                    <span className={styles.pillLetter}>B</span> Belum Konsisten
                                </button>
                                <button className={`${styles.ansBtn} ${styles.ansTidak}`} onClick={() => handleAnswer('TIDAK')}>
                                    <span className={styles.pillLetter}>C</span> Belum Mampu
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}