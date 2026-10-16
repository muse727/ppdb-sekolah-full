// src/app/pendaftar/biodata/page.tsx
'use client';

import { useState, useEffect, useRef } from 'react';
import { useRouter } from 'next/navigation';
import libAxios from '@/lib/axios';
import Swal from 'sweetalert2';
import { httpErrorMessage, type BiodataFormState, type BiodataRecord } from '@/lib/domain';
import { JALUR_PENDAFTARAN_OPTIONS } from '@/lib/jalur-pendaftaran';
import styles from './biodata.module.css';

// =================================================================
// 1. DATABASE MINI WILAYAH JAKARTA (Bisa ditambahin / diganti API nanti)
// =================================================================
const dataWilayah: Record<string, Record<string, string[]>> = {
    "Jakarta Selatan": {
        "Kebayoran Baru": ["Selong", "Gunung", "Kramat Pela", "Gandaria Utara", "Cipete Utara", "Melawai", "Petogogan", "Rawa Barat", "Senayan"],
        "Cilandak": ["Cipete Selatan", "Gandaria Selatan", "Cilandak Barat", "Lebak Bulus", "Pondok Labu"],
        "Pasar Minggu": ["Pejaten Barat", "Pejaten Timur", "Pasar Minggu", "Kebagusan", "Jati Padang", "Ragunan", "Cilandak Timur"]
    },
    "Jakarta Timur": {
        "Duren Sawit": ["Pondok Bambu", "Duren Sawit", "Pondok Kelapa", "Pondok Kopi", "Malaka Jaya", "Malaka Sari", "Klender"],
        "Jatinegara": ["Bali Mester", "Kampung Melayu", "Bidara Cina", "Cipinang Cempedak", "Rawa Bunga", "Cipinang Besar Utara"],
        "Makasar": ["Pinang Ranti", "Makasar", "Halim Perdanakusuma", "Cipinang Melayu", "Kebon Pala"]
    },
    "Jakarta Pusat": {
        "Menteng": ["Menteng", "Pegangsaan", "Cikini", "Kebon Sirih", "Gondangdia"],
        "Gambir": ["Gambir", "Cideng", "Petojo Selatan", "Petojo Utara", "Kebon Kelapa", "Duri Pulo"],
        "Tanah Abang": ["Bendungan Hilir", "Karet Tengsin", "Kebon Melati", "Kebon Kacang", "Kampung Bali", "Petamburan"]
    },
    "Jakarta Barat": {
        "Kembangan": ["Kembangan Utara", "Kembangan Selatan", "Meruya Utara", "Srengseng", "Joglo", "Meruya Selatan"],
        "Kebon Jeruk": ["Duri Kepa", "Kedoya Selatan", "Kedoya Utara", "Kebon Jeruk", "Sukabumi Utara", "Kelapa Dua"],
        "Cengkareng": ["Kedaung Kali Angke", "Kapuk", "Cengkareng Barat", "Cengkareng Timur", "Rawa Buaya", "Duri Kosambi"]
    },
    "Jakarta Utara": {
        "Kelapa Gading": ["Kelapa Gading Barat", "Kelapa Gading Timur", "Pegangsaan Dua"],
        "Tanjung Priok": ["Tanjung Priok", "Kebon Bawang", "Sungai Bambu", "Papanggo", "Warakas", "Sunter Agung", "Sunter Jaya"],
        "Penjaringan": ["Penjaringan", "Pluit", "Pejagalan", "Kapuk Muara", "Kamal Muara"]
    }
};

const listPekerjaan = ["PNS", "TNI / Polri", "BUMN / BUMD", "Pegawai Swasta", "Wiraswasta / Pengusaha", "Guru / Dosen", "Dokter / Tenaga Medis", "Buruh / Petani / Nelayan", "Ibu Rumah Tangga", "Pekerja Lepas / Freelance"];
const listPenghasilan = ["Tidak Berpenghasilan", "< Rp 1.000.000", "Rp 1.000.000 - Rp 3.000.000", "Rp 3.000.000 - Rp 5.000.000", "Rp 5.000.000 - Rp 10.000.000", "Rp 10.000.000 - Rp 20.000.000", "> Rp 20.000.000"];

// =================================================================
// 2. KOMPONEN CUSTOM DROPDOWN PINTAR (Searchable & Custom Input)
// =================================================================
const CustomSearchSelect = ({ options, value, onChange, placeholder, allowCustom = true, name }: { options: string[], value: string, onChange: (val: string, name: string) => void, placeholder: string, allowCustom?: boolean, name: string }) => {
    const [isOpen, setIsOpen] = useState(false);
    const [search, setSearch] = useState(value);
    const wrapperRef = useRef<HTMLDivElement>(null);

    useEffect(() => { setSearch(value); }, [value]);

    // Handle klik di luar kotak buat nutup dropdown
    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target as Node)) setIsOpen(false);
        }
        document.addEventListener("mousedown", handleClickOutside);
        return () => document.removeEventListener("mousedown", handleClickOutside);
    }, []);

    const filteredOptions = options.filter(opt => opt.toLowerCase().includes(search.toLowerCase()));

    return (
        <div ref={wrapperRef} style={{ position: 'relative', width: '100%' }}>
            <input 
                className={styles.inputField} style={{ paddingLeft: '15px', width: '100%', paddingRight: '30px' }}
                placeholder={placeholder} value={search}
                onChange={(e) => {
                    setSearch(e.target.value);
                    if (allowCustom) onChange(e.target.value, name);
                }}
                onFocus={() => setIsOpen(true)}
                required
            />
            {/* Panah Dropdown */}
            <svg style={{ position: 'absolute', right: '15px', top: '15px', pointerEvents: 'none', color: '#a0aec0' }} width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
            
            {/* Menu Pop-up */}
            {isOpen && (
                <ul style={{
                    position: 'absolute', top: '100%', left: 0, right: 0, zIndex: 50, background: 'white',
                    border: '1px solid #edf2f7', borderRadius: '12px', boxShadow: '0 10px 25px rgba(0,0,0,0.1)',
                    maxHeight: '200px', overflowY: 'auto', padding: '5px 0', margin: '5px 0 0 0', listStyle: 'none'
                }}>
                    {filteredOptions.length > 0 ? filteredOptions.map((opt, i) => (
                        <li key={i} 
                            style={{ padding: '10px 15px', cursor: 'pointer', fontSize: '13px', color: '#2d3748', borderBottom: '1px solid #f8fafc' }}
                            onMouseDown={() => { onChange(opt, name); setSearch(opt); setIsOpen(false); }}
                            onMouseEnter={(e) => e.currentTarget.style.background = '#e8f5e9'}
                            onMouseLeave={(e) => e.currentTarget.style.background = 'white'}
                        >{opt}</li>
                    )) : (
                        <li style={{ padding: '10px 15px', fontSize: '12px', color: '#a0aec0', textAlign: 'center' }}>
                            {allowCustom ? 'Ketik manual untuk menambahkan' : 'Data tidak ditemukan'}
                        </li>
                    )}
                </ul>
            )}
        </div>
    );
};


// =================================================================
// 2b. VALIDASI FIELD IDENTITAS (konsisten dengan backend: digits:10/16)
// =================================================================
// Semua nomor identitas tetap string agar nol di depan tidak hilang.
const DIGIT_FIELDS: Record<string, number> = { nisn: 10, nik_siswa: 16, no_kk: 16, nik_ibu: 16, nik_ayah: 16 };
const FIELD_LABELS: Record<string, string> = { nisn: 'NISN', nik_siswa: 'NIK Siswa', no_kk: 'No. Kartu Keluarga', nik_ibu: 'NIK Ibu', nik_ayah: 'NIK Ayah' };
const STEP_FIELDS: Record<number, string[]> = {
    1: ['asal_sekolah', 'jalur_pendaftaran', 'nama_lengkap', 'nama_panggilan', 'jenis_kelamin', 'nisn', 'nik_siswa', 'no_kk', 'tempat_lahir', 'tanggal_lahir', 'anak_ke', 'status_peserta_didik'],
    2: ['nama_ibu', 'nik_ibu', 'pendidikan_ibu', 'pekerjaan_ibu', 'penghasilan_ibu', 'nama_ayah', 'nik_ayah', 'pendidikan_ayah', 'pekerjaan_ayah', 'penghasilan_ayah'],
    3: ['alamat_lengkap', 'kota', 'kecamatan', 'kelurahan', 'rt', 'rw', 'no_hp_ortu', 'email_siswa', 'nama_wali', 'no_hp_wali'],
};
const stepOf = (field: string): number | null => {
    for (const [step, fields] of Object.entries(STEP_FIELDS)) if (fields.includes(field)) return Number(step);
    return null;
};
// Validasi satu field digit: kosong boleh hanya jika tidak wajib; jika diisi
// harus ASCII digit dan tepat sepanjang aturan backend.
const validateDigitField = (name: string, raw: string, required: boolean): string | null => {
    const value = raw.trim();
    const label = FIELD_LABELS[name];
    const length = DIGIT_FIELDS[name];
    if (value === '') return required ? `${label} wajib diisi.` : null;
    if (!/^[0-9]+$/.test(value)) return `${label} hanya boleh berisi angka.`;
    if (value.length !== length) return `${label} harus tepat ${length} digit.`;
    return null;
};
// Field digit yang wajib (mengikuti aturan form yang sudah ada).
const REQUIRED_DIGIT_FIELDS = ['nik_siswa', 'no_kk', 'nik_ibu', 'nik_ayah'];
const collectDigitErrors = (fields: string[], data: BiodataFormState): Record<string, string> => {
    const errors: Record<string, string> = {};
    for (const name of fields) {
        if (!(name in DIGIT_FIELDS)) continue;
        const message = validateDigitField(name, data[name as keyof BiodataFormState] ?? '', REQUIRED_DIGIT_FIELDS.includes(name));
        if (message) errors[name] = message;
    }
    return errors;
};

export default function BiodataPage() {
    const router = useRouter();
    const [currentTab, setCurrentTab] = useState(1);
    const [isLoading, setIsLoading] = useState(true);
    const [isOtherChildOrder, setIsOtherChildOrder] = useState(false);

    const [formData, setFormData] = useState<BiodataFormState>({
        asal_sekolah: '', jalur_pendaftaran: '', nama_lengkap: '', nama_panggilan: '', jenis_kelamin: '', 
        nisn: '', nik_siswa: '', no_kk: '', tempat_lahir: '', tanggal_lahir: '', anak_ke: '', status_peserta_didik: '',
        nama_ibu: '', nik_ibu: '', pendidikan_ibu: '', pekerjaan_ibu: '', penghasilan_ibu: '',
        nama_ayah: '', nik_ayah: '', pendidikan_ayah: '', pekerjaan_ayah: '', penghasilan_ayah: '',
        alamat_lengkap: '', kota: '', kecamatan: '', kelurahan: '', rt: '', rw: '',
        no_hp_ortu: '', email_siswa: '', nama_wali: '', no_hp_wali: ''
    });

    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [apiError, setApiError] = useState('');

    useEffect(() => {
        libAxios.get<BiodataRecord>('/api/biodata').then(res => {
            if(res.data) {
                const safeData: BiodataFormState = { ...formData };
                // Object.keys() widens to string[]; the row's own keys are the
                // only ones iterated, so narrow to keyof BiodataRecord to index
                // safely. Non-form columns (id, timestamps) ride along into the
                // state as before — the backend saves only its own whitelist.
                (Object.keys(res.data) as (keyof BiodataRecord)[]).forEach(key => {
                    const value = res.data[key];
                    safeData[key] = value === null || value === undefined ? '' : String(value);
                });
                safeData.asal_sekolah = safeData.asal_sekolah.toUpperCase();
                setIsOtherChildOrder(safeData.anak_ke !== '' && !/^(?:[1-9]|10)$/.test(safeData.anak_ke));
                setFormData(safeData);
            }
        }).catch(err => console.log(err)).finally(() => setIsLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // 3. FUNGSI HANDLE KHUSUS BUAT CUSTOM DROPDOWN
    const handleCustomChange = (value: string, name: string) => {
        setFormData((prev) => {
            const newData: BiodataFormState = { ...prev, [name]: value };
            
            // LOGIKA CASCADING: Kalau Kota ganti, Kecamatan & Kelurahan di-reset!
            if (name === 'kota') { newData.kecamatan = ''; newData.kelurahan = ''; }
            // LOGIKA CASCADING: Kalau Kecamatan ganti, Kelurahan di-reset!
            if (name === 'kecamatan') { newData.kelurahan = ''; }
            
            return newData;
        });
    };

    const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
        const { name, value } = e.target;
        setFormData({ ...formData, [name]: value });
        // Error pada field yang sedang diperbaiki di-refresh / dihapus saat valid.
        setFieldErrors(prev => {
            const updated = { ...prev };
            if (name in DIGIT_FIELDS) {
                const message = validateDigitField(name, value, REQUIRED_DIGIT_FIELDS.includes(name));
                if (message) updated[name] = message; else delete updated[name];
            } else if (name in updated) {
                delete updated[name];
            }
            return updated;
        });
    };

    const handleSchoolChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const input = e.currentTarget;
        const value = input.value;
        const start = input.selectionStart;
        const end = input.selectionEnd;
        setFormData(prev => ({ ...prev, asal_sekolah: value.toUpperCase() }));
        // Uppercasing a controlled input must not move the caret when editing mid-text.
        requestAnimationFrame(() => {
            if (document.activeElement === input && start !== null && end !== null) {
                input.setSelectionRange(value.slice(0, start).toUpperCase().length, value.slice(0, end).toUpperCase().length);
            }
        });
    };

    const handleChildOrderChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const value = e.target.value;
        setIsOtherChildOrder(value === 'LAINNYA');
        setFormData(prev => ({ ...prev, anak_ke: value === 'LAINNYA' ? '' : value }));
    };

    const nextTab = () => {
        const form = document.getElementById('biodataForm') as HTMLFormElement;
        if (!form.checkValidity()) { form.reportValidity(); return; }
        // Validasi digit (wajib + opsional yang terisi) untuk step aktif —
        // sebelum request apa pun dikirim.
        const digitErrors = collectDigitErrors(STEP_FIELDS[currentTab] ?? [], formData);
        if (Object.keys(digitErrors).length > 0) {
            setFieldErrors(prev => ({ ...prev, ...digitErrors }));
            const first = document.querySelector<HTMLInputElement>(`[name="${Object.keys(digitErrors)[0]}"]`);
            if (first) { first.focus(); first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            return;
        }
        setFieldErrors({});
        setCurrentTab(prev => prev + 1); window.scrollTo(0, 0); 
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        // Validasi digit seluruh form sebelum submit (backend tetap sumber kebenaran).
        const digitErrors = collectDigitErrors(
            [...STEP_FIELDS[1], ...STEP_FIELDS[2], ...STEP_FIELDS[3]],
            formData,
        );
        if (Object.keys(digitErrors).length > 0) {
            setFieldErrors(digitErrors);
            const failingStep = stepOf(Object.keys(digitErrors)[0]);
            if (failingStep) setCurrentTab(failingStep);
            const first = document.querySelector<HTMLInputElement>(`[name="${Object.keys(digitErrors)[0]}"]`);
            if (first) { first.focus(); first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            return;
        }
        setFieldErrors({});
        setApiError('');
        Swal.fire({ title: 'Menyimpan Data...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});
        try {
            await libAxios.post('/api/biodata', {
                ...formData,
                asal_sekolah: formData.asal_sekolah.trim().toUpperCase(),
                nisn: formData.nisn.trim(),
                nik_siswa: formData.nik_siswa.trim(),
                no_kk: formData.no_kk.trim(),
                nik_ibu: formData.nik_ibu.trim(),
                nik_ayah: formData.nik_ayah.trim(),
            });
            Swal.fire({ icon: 'success', title: 'Data Tersimpan!', text: 'Biodata berhasil dilengkapi.', confirmButtonColor: '#1a5f51' })
                .then(() => router.push('/pendaftar'));
        } catch (error: unknown) {
            // 422: petakan errors.<field> ke field terkait; input tidak dihapus.
            const maybe = typeof error === 'object' && error !== null
                ? (error as { response?: { status?: number; data?: { message?: unknown; errors?: Record<string, unknown> } } })
                : null;
            const serverErrors = maybe?.response?.data?.errors;
            if (maybe?.response?.status === 422 && serverErrors && typeof serverErrors === 'object') {
                const mapped: Record<string, string> = {};
                for (const [key, value] of Object.entries(serverErrors)) {
                    mapped[key] = Array.isArray(value) && typeof value[0] === 'string' ? value[0] : 'Nilai tidak valid.';
                }
                setFieldErrors(mapped);
                const firstKey = Object.keys(mapped).find(key => stepOf(key) !== null) ?? Object.keys(mapped)[0];
                if (firstKey) {
                    const step = stepOf(firstKey);
                    if (step) setCurrentTab(step);
                    const el = document.querySelector<HTMLInputElement>(`[name="${firstKey}"]`);
                    if (el) { el.focus(); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                }
                Swal.close();
                return;
            }
            const message = httpErrorMessage(error, 'Terjadi kesalahan sistem.');
            setApiError(message);
            Swal.fire({ icon: 'error', title: 'Gagal', text: message, confirmButtonColor: '#1a5f51' });
        }
    };

    if (isLoading) return <div style={{textAlign: 'center', marginTop: '50px'}}>Memuat Form...</div>;

    // AMBIL LIST KECAMATAN & KELURAHAN BERDASARKAN KOTA YANG DIPILIH
    const listKota = Object.keys(dataWilayah);
    const listKecamatan = formData.kota && dataWilayah[formData.kota] ? Object.keys(dataWilayah[formData.kota]) : [];
    const listKelurahan = formData.kota && formData.kecamatan && dataWilayah[formData.kota] && dataWilayah[formData.kota][formData.kecamatan] 
                            ? dataWilayah[formData.kota][formData.kecamatan] : [];

    // Pesan error di bawah field (dari validasi step atau mapping 422 backend).
    const fieldError = (name: string): React.ReactNode => fieldErrors[name]
        ? <p role="alert" className={styles.fieldError}>{fieldErrors[name]}</p>
        : null;

    return (
        <div className={styles.mobileContainer}>
            <div className={styles.headerMini}>
                <div className={styles.backBtn} onClick={() => router.push('/pendaftar')}>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="2"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                </div>
                <div>
                    <h2 className={styles.headerTitle}>Isi Biodata Siswa</h2>
                    <p className={styles.headerSubtitle}>Langkah 2 Pendaftaran</p>
                </div>
            </div>

            <div style={{ display: 'flex', justifyContent: 'center', gap: '10px', marginTop: '-20px', position: 'relative', zIndex: 10 }}>
                {[1, 2, 3].map(step => (
                    <div key={step} style={{
                        width: '10px', height: '10px', borderRadius: '50%',
                        background: currentTab === step ? '#f39c12' : currentTab > step ? '#27ae60' : '#cbd5e0',
                        transition: '0.3s'
                    }}></div>
                ))}
            </div>

            <form id="biodataForm" className={styles.formCard} onSubmit={handleSubmit} style={{ marginTop: '20px' }}>
                {apiError && <p role="alert" className={styles.apiError}>{apiError}</p>}
                
                {/* === TAB 1: IDENTITAS === */}
                {currentTab === 1 && (
                    <>
                        <h3 className={styles.sectionTitle}>Identitas Calon Siswa</h3>
                        <div className={styles.inputGroup}><label className={styles.label}>Asal Sekolah <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" name="asal_sekolah" required value={formData.asal_sekolah} onChange={handleSchoolChange} /></div>
                        <div className={styles.inputGroup}><label className={styles.label}>Jalur Pendaftaran <span className={styles.required}>*</span></label><select className={`${styles.inputField} ${styles.selectField}`} style={{paddingLeft: '15px'}} name="jalur_pendaftaran" required value={formData.jalur_pendaftaran} onChange={handleChange}>
                            <option value="">Pilih Jalur Pendaftaran</option>
                            {JALUR_PENDAFTARAN_OPTIONS.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>{fieldError('jalur_pendaftaran')}</div>
                        <div className={styles.inputGroup}><label className={styles.label}>Nama Lengkap <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" name="nama_lengkap" required value={formData.nama_lengkap} onChange={handleChange} /></div>
                        <div className={styles.twoColGrid}>
                            <div className={styles.inputGroup}><label className={styles.label}>Nama Panggilan</label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" name="nama_panggilan" value={formData.nama_panggilan} onChange={handleChange} /></div>
                            <div className={styles.inputGroup}><label className={styles.label}>Jenis Kelamin <span className={styles.required}>*</span></label><select className={`${styles.inputField} ${styles.selectField}`} style={{paddingLeft: '15px'}} name="jenis_kelamin" required value={formData.jenis_kelamin} onChange={handleChange}><option value="">Pilih</option><option value="L">Laki-laki</option><option value="P">Perempuan</option></select></div>
                        </div>
                        <div className={styles.twoColGrid}>
                            <div className={styles.inputGroup}><label className={styles.label}>NISN (opsional)</label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" inputMode="numeric" autoComplete="off" name="nisn" value={formData.nisn} onChange={handleChange} />{fieldError('nisn')}</div>
                            <div className={styles.inputGroup}><label className={styles.label}>NIK Siswa <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" inputMode="numeric" autoComplete="off" name="nik_siswa" required value={formData.nik_siswa} onChange={handleChange} />{fieldError('nik_siswa')}</div>
                        </div>
                        <div className={styles.inputGroup}><label className={styles.label}>No. Kartu Keluarga <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" inputMode="numeric" autoComplete="off" name="no_kk" required value={formData.no_kk} onChange={handleChange} />{fieldError('no_kk')}</div>
                        <div className={styles.twoColGrid}>
                            <div className={styles.inputGroup}><label className={styles.label}>Tempat Lahir <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" name="tempat_lahir" required value={formData.tempat_lahir} onChange={handleChange} /></div>
                            <div className={styles.inputGroup}><label className={styles.label}>Tanggal Lahir <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="date" name="tanggal_lahir" required value={formData.tanggal_lahir} onChange={handleChange} /></div>
                        </div>
                        <div className={styles.twoColGrid}>
                            <div className={styles.inputGroup}>
                                <label className={styles.label} htmlFor="anak-ke">Anak Ke- <span className={styles.required}>*</span></label>
                                <select id="anak-ke" className={`${styles.inputField} ${styles.selectField}`} style={{paddingLeft: '15px'}} required value={isOtherChildOrder ? 'LAINNYA' : formData.anak_ke} onChange={handleChildOrderChange}>
                                    <option value="">Pilih</option>
                                    {Array.from({ length: 10 }, (_, index) => index + 1).map(order => (
                                        <option key={order} value={String(order)}>Anak ke-{order}</option>
                                    ))}
                                    <option value="LAINNYA">Lainnya</option>
                                </select>
                                {isOtherChildOrder && (
                                    <div style={{marginTop: '15px'}}>
                                        <label className={styles.label} htmlFor="nomor-anak">Nomor Anak</label>
                                        <input id="nomor-anak" className={styles.inputField} style={{paddingLeft: '15px'}} type="number" name="anak_ke" required min="1" step="1" value={formData.anak_ke} onChange={handleChange} />
                                    </div>
                                )}
                            </div>
                            <div className={styles.inputGroup}><label className={styles.label}>Status Anak <span className={styles.required}>*</span></label><select className={`${styles.inputField} ${styles.selectField}`} style={{paddingLeft: '15px'}} name="status_peserta_didik" required value={formData.status_peserta_didik} onChange={handleChange}><option value="">Pilih</option><option value="Lengkap">Lengkap</option><option value="Yatim">Yatim</option><option value="Piatu">Piatu</option><option value="Yatim Piatu">Yatim Piatu</option></select></div>
                        </div>
                        <button type="button" className={styles.submitBtn} onClick={nextTab}>Selanjutnya</button>
                    </>
                )}

                {/* === TAB 2: DATA ORANG TUA === */}
                {currentTab === 2 && (
                    <>
                        <h3 className={styles.sectionTitle} style={{color: '#d35400'}}>Data Ibu Kandung</h3>
                        <div className={styles.inputGroup}><label className={styles.label}>Nama Ibu <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" name="nama_ibu" required value={formData.nama_ibu} onChange={handleChange} /></div>
                        <div className={styles.inputGroup}><label className={styles.label}>NIK Ibu <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" inputMode="numeric" autoComplete="off" name="nik_ibu" required value={formData.nik_ibu} onChange={handleChange} />{fieldError('nik_ibu')}</div>
                        <div className={styles.twoColGrid}>
                            <div className={styles.inputGroup}>
                                <label className={styles.label}>Pend. Ibu <span className={styles.required}>*</span></label>
                                <select className={`${styles.inputField} ${styles.selectField}`} style={{paddingLeft: '15px'}} name="pendidikan_ibu" required value={formData.pendidikan_ibu} onChange={handleChange}>
                                    <option value="">Pilih</option><option value="SD">SD</option><option value="SMP">SMP</option><option value="SMA">SMA</option><option value="D3">D3</option><option value="S1">S1</option><option value="S2">S2</option><option value="S3">S3</option>
                                </select>
                            </div>
                            <div className={styles.inputGroup}>
                                <label className={styles.label}>Pekerjaan <span className={styles.required}>*</span></label>
                                <CustomSearchSelect name="pekerjaan_ibu" options={listPekerjaan} value={formData.pekerjaan_ibu} onChange={handleCustomChange} placeholder="Pilih/Ketik..." />
                            </div>
                        </div>
                        <div className={styles.inputGroup}>
                            <label className={styles.label}>Penghasilan Per Bulan Ibu <span className={styles.required}>*</span></label>
                            <CustomSearchSelect name="penghasilan_ibu" options={listPenghasilan} value={formData.penghasilan_ibu} onChange={handleCustomChange} placeholder="Pilih/Ketik nominal..." />
                        </div>

                        <h3 className={styles.sectionTitle} style={{marginTop: '25px', color: '#2980b9'}}>Data Ayah Kandung</h3>
                        <div className={styles.inputGroup}><label className={styles.label}>Nama Ayah <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" name="nama_ayah" required value={formData.nama_ayah} onChange={handleChange} /></div>
                        <div className={styles.inputGroup}><label className={styles.label}>NIK Ayah <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" inputMode="numeric" autoComplete="off" name="nik_ayah" required value={formData.nik_ayah} onChange={handleChange} />{fieldError('nik_ayah')}</div>
                        <div className={styles.twoColGrid}>
                            <div className={styles.inputGroup}>
                                <label className={styles.label}>Pend. Ayah <span className={styles.required}>*</span></label>
                                <select className={`${styles.inputField} ${styles.selectField}`} style={{paddingLeft: '15px'}} name="pendidikan_ayah" required value={formData.pendidikan_ayah} onChange={handleChange}>
                                    <option value="">Pilih</option><option value="SD">SD</option><option value="SMP">SMP</option><option value="SMA">SMA</option><option value="D3">D3</option><option value="S1">S1</option><option value="S2">S2</option><option value="S3">S3</option>
                                </select>
                            </div>
                            <div className={styles.inputGroup}>
                                <label className={styles.label}>Pekerjaan <span className={styles.required}>*</span></label>
                                <CustomSearchSelect name="pekerjaan_ayah" options={listPekerjaan} value={formData.pekerjaan_ayah} onChange={handleCustomChange} placeholder="Pilih/Ketik..." />
                            </div>
                        </div>
                        <div className={styles.inputGroup}>
                            <label className={styles.label}>Penghasilan Per Bulan Ayah <span className={styles.required}>*</span></label>
                            <CustomSearchSelect name="penghasilan_ayah" options={listPenghasilan} value={formData.penghasilan_ayah} onChange={handleCustomChange} placeholder="Pilih/Ketik nominal..." />
                        </div>

                        <div style={{ display: 'flex', gap: '10px' }}>
                            <button type="button" className={styles.submitBtn} style={{background: '#cbd5e0', color: '#2d3748', flex: 1, boxShadow: 'none'}} onClick={() => { setCurrentTab(1); window.scrollTo(0,0); }}>Kembali</button>
                            <button type="button" className={styles.submitBtn} style={{flex: 2}} onClick={nextTab}>Selanjutnya</button>
                        </div>
                    </>
                )}

                {/* === TAB 3: ALAMAT & WALI === */}
                {currentTab === 3 && (
                    <>
                        <h3 className={styles.sectionTitle} style={{color: '#8e44ad'}}>Alamat & Kontak</h3>
                        <div className={styles.inputGroup}>
                            <label className={styles.label}>Alamat Lengkap <span className={styles.required}>*</span></label>
                            <textarea className={styles.inputField} style={{paddingLeft: '15px', height: '80px', resize: 'none'}} name="alamat_lengkap" placeholder="Nama Jalan, Blok, Perumahan" required value={formData.alamat_lengkap} onChange={handleChange} />
                        </div>
                        
                        <div className={styles.twoColGrid}>
                            <div className={styles.inputGroup}>
                                <label className={styles.label}>Kota / Kabupaten <span className={styles.required}>*</span></label>
                                <CustomSearchSelect name="kota" options={listKota} value={formData.kota} onChange={handleCustomChange} placeholder="Pilih Kota..." />
                            </div>
                            <div className={styles.inputGroup}>
                                <label className={styles.label}>Kecamatan <span className={styles.required}>*</span></label>
                                <CustomSearchSelect name="kecamatan" options={listKecamatan} value={formData.kecamatan} onChange={handleCustomChange} placeholder="Pilih / Ketik..." />
                            </div>
                        </div>

                        <div className={styles.addressRowGrid}>
                            <div className={styles.inputGroup}>
                                <label className={styles.label}>Kelurahan <span className={styles.required}>*</span></label>
                                <CustomSearchSelect name="kelurahan" options={listKelurahan} value={formData.kelurahan} onChange={handleCustomChange} placeholder="Pilih / Ketik..." />
                            </div>
                            <div className={styles.subTwoColGrid}>
                                <div className={styles.inputGroup}><label className={styles.label}>RT <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" name="rt" required value={formData.rt} onChange={handleChange} /></div>
                                <div className={styles.inputGroup}><label className={styles.label}>RW <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" name="rw" required value={formData.rw} onChange={handleChange} /></div>
                            </div>
                        </div>

                        <div className={styles.inputGroup} style={{marginTop:'15px'}}><label className={styles.label}>No HP Ortu (WA) <span className={styles.required}>*</span></label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="tel" name="no_hp_ortu" required value={formData.no_hp_ortu} onChange={handleChange} /></div>
                        <div className={styles.inputGroup}><label className={styles.label}>Email Siswa (Opsional)</label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="email" name="email_siswa" value={formData.email_siswa} onChange={handleChange} /></div>

                        <h3 className={styles.sectionTitle} style={{marginTop: '20px'}}>Data Wali (Jika Ada)</h3>
                        <div className={styles.inputGroup}><label className={styles.label}>Nama Wali</label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="text" name="nama_wali" value={formData.nama_wali} onChange={handleChange} /></div>
                        <div className={styles.inputGroup}><label className={styles.label}>No HP Wali</label><input className={styles.inputField} style={{paddingLeft: '15px'}} type="tel" name="no_hp_wali" value={formData.no_hp_wali} onChange={handleChange} /></div>

                        <div style={{ display: 'flex', gap: '10px', marginTop: '20px' }}>
                            <button type="button" className={styles.submitBtn} style={{background: '#cbd5e0', color: '#2d3748', flex: 1, boxShadow: 'none'}} onClick={() => { setCurrentTab(2); window.scrollTo(0,0); }}>Kembali</button>
                            <button type="submit" className={styles.submitBtn} style={{flex: 2}}>Kirim & Simpan</button>
                        </div>
                    </>
                )}
            </form>
        </div>
    );
}