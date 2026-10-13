'use client';

import { useEffect, useSyncExternalStore } from 'react';
import styles from './cetak.module.css';

type Jawaban = { jawaban?: string; kategori?: string; keterampilan?: string; title?: string; saran?: string };
/** E.12C — canonical rekomendasi contract; see RekomendasiAkhir in lib/domain. */
type RekomendasiAkhir = 'LULUS' | 'LULUS BERSYARAT' | 'TIDAK LULUS';
type SiswaCetak = { user_id: number; nama_siswa: string; rekomendasi_akhir?: RekomendasiAkhir | null; jawaban_json?: Record<string, Jawaban> };
type PengaturanCetak = { tanggal: string; namaKepsek: string; nipKepsek: string; logoB64: string; ttdB64: string };

const subscribe = () => () => {};
const getServerSnapshot = () => null;

// E.11: was a text check-mark glyph (U+2713) used as a table icon. Replaced with
// an inline SVG in the design system's Feather-style icon language. Stroke is an
// explicit #000 (not currentColor) and print-color-adjust is forced so the mark
// reliably renders inside the window.print() document at the 12px table size.
function CheckMark() {
    return (
        <svg
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="#000"
            strokeWidth="3"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            style={{ verticalAlign: 'middle', printColorAdjust: 'exact', WebkitPrintColorAdjust: 'exact' }}
        >
            <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
    );
}

export default function CetakLaporanPage() {
    const printData = useSyncExternalStore(subscribe, () => localStorage.getItem('goSkool_printData'), getServerSnapshot);
    const printSettings = useSyncExternalStore(subscribe, () => localStorage.getItem('goSkool_printSettings'), getServerSnapshot);
    const dataSiswa: SiswaCetak | null = printData ? JSON.parse(printData) : null;
    const settings: PengaturanCetak | null = printData ? (printSettings ? JSON.parse(printSettings) : {
        tanggal: 'Jakarta, 15 Juli 2026',
        namaKepsek: 'Amang Suryaman, M.Pd.',
        nipKepsek: '-',
        logoB64: '',
        ttdB64: ''
    }) : null;
    const jsonData = dataSiswa?.jawaban_json || {};
    const scoreInfo = {
        total: Object.values(jsonData).reduce((total, item) => total + (item.jawaban === 'YA' ? 2 : item.jawaban === 'TIDAK KONSISTEN' ? 1 : 0), 0),
        max: Object.keys(jsonData).length * 2
    };

    useEffect(() => {
        if (!printData) return;
        const timeout = setTimeout(() => window.print(), 800);
        return () => clearTimeout(timeout);
    }, [printData, printSettings]);

    if (!dataSiswa || !settings) return <div style={{padding: '50px', textAlign: 'center', fontFamily: 'sans-serif'}}>Menyiapkan Dokumen...</div>;

    const listSaran = Object.keys(jsonData).filter(key => jsonData[key].saran && jsonData[key].saran.trim() !== '');

    return (
        <div className={styles.printContainer}>
            <div className={styles.headerKop}>
                <div className={styles.logoBox}>
                    {/* P4-A.3 Group B — `settings.logoB64` is an inline base64 data URI hydrated
                        from localStorage. Next.js forces `unoptimized` for data:/blob: sources, so
                        <Image> renders the identical payload with no benefit. This is a print
                        document driven by window.print() on a timer; keeping a plain <img>
                        guarantees the logo is fully decoded at print time.
                        .logoBox img { max-width/max-height: 100%; object-fit: contain } unchanged. */}
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    {settings.logoB64 && <img src={settings.logoB64} alt="Logo Sekolah" />}
                </div>
                <div className={styles.titleBox}>
                    <h1>HASIL OBSERVASI</h1>
                    <h2>CALON PESERTA DIDIK BARU SDIT AL MUBARAK</h2>
                    <h3>TAHUN AJARAN 2026/2027</h3>
                </div>
            </div>

            <table className={styles.tableIdentity}>
                <tbody>
                    <tr>
                        <td className={styles.labelCol}>NO. PESERTA</td>
                        <td className={styles.valCol}>REG-00{dataSiswa.user_id}</td>
                        <td className={styles.labelCol}>NAMA LENGKAP</td>
                        <td className={styles.valCol}>{dataSiswa.nama_siswa}</td>
                    </tr>
                </tbody>
            </table>

            <div className={styles.sectionTitle}>KETERAMPILAN DASAR YANG DIAMATI</div>
            <table className={styles.tableMain}>
                <thead>
                    <tr>
                        <th rowSpan={2} style={{width: '5%'}}>NO</th>
                        <th rowSpan={2} style={{width: '65%'}}>KETERAMPILAN</th>
                        <th colSpan={3}>PENGUASAAN</th>
                    </tr>
                    <tr>
                        <th style={{width: '10%'}}>BAIK</th>
                        <th style={{width: '10%'}}>CUKUP</th>
                        <th style={{width: '10%'}}>PERLU BIMBINGAN</th>
                    </tr>
                </thead>
                <tbody>
                    {Object.keys(jsonData).map((key, index) => {
                        const item = jsonData[key];
                        return (
                            <tr key={key}>
                                <td style={{textAlign: 'center'}}>{index + 1}</td>
                                <td><strong>{item.kategori || 'Kategori'}:</strong> {item.keterampilan || item.title}</td>
                                <td style={{textAlign: 'center'}}>{item.jawaban === 'YA' ? <CheckMark /> : ''}</td>
                                <td style={{textAlign: 'center'}}>{item.jawaban === 'TIDAK KONSISTEN' ? <CheckMark /> : ''}</td>
                                <td style={{textAlign: 'center'}}>{item.jawaban === 'TIDAK' ? <CheckMark /> : ''}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>

            <div className={styles.sectionTitle}>SARAN</div>
            <p className={styles.saranDesc}>Berikut adalah contoh kegiatan yang dapat dilakukan untuk meningkatkan keterampilan yang belum dikuasai dengan baik oleh ananda.</p>
            <table className={styles.tableMain}>
                <thead>
                    <tr>
                        <th style={{width: '25%'}}>KETERAMPILAN</th>
                        <th style={{width: '75%'}}>KEGIATAN</th>
                    </tr>
                </thead>
                <tbody>
                    {listSaran.map((key) => {
                        const item = jsonData[key];
                        return (
                            <tr key={key}>
                                <td><strong>{item.kategori || 'Kategori'}:</strong> {item.keterampilan || item.title}</td>
                                <td>{item.saran}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>

            <div style={{ marginTop: '20px', padding: '10px', border: '1px solid #000', backgroundColor: '#f9f9f9' }}>
                <p style={{ margin: '0 0 5px 0', fontSize: '12px', fontWeight: 'bold' }}>HASIL PENILAIAN AKHIR</p>
                <p style={{ margin: '0 0 5px 0', fontSize: '12px' }}>Total Skor Observasi: <strong>{scoreInfo.total} dari {scoreInfo.max}</strong></p>
                <p style={{ margin: '0', fontSize: '12px' }}>Rekomendasi Keputusan: <strong>{dataSiswa.rekomendasi_akhir || 'LULUS'}</strong></p>
                {dataSiswa.rekomendasi_akhir === 'LULUS BERSYARAT' && (
                    <p style={{ margin: '5px 0 0 0', fontSize: '11px', color: '#cc0000', fontStyle: 'italic' }}>* Catatan: Ananda dinyatakan Lulus Bersyarat (diterima dengan syarat/ketentuan khusus — misalnya menjalani pemeriksaan psikolog dan menyerahkan laporan psikolog sebagai bagian dari persyaratan) dan membutuhkan penandatanganan surat kesepakatan khusus dari pihak sekolah.</p>
                )}
            </div>

            <div className={styles.signatureSection}>
                <table className={styles.tableSignature}>
                    <tbody>
                        <tr>
                            <td className={styles.emptyCol}></td>
                            <td className={styles.signCol}>
                                <p>{settings.tanggal}</p>
                                <br/>
                                <p>Mengetahui,</p>
                                <p>Kepala Sekolah SDIT Al Mubarak</p>
                                <div className={styles.ttdBox}>
                                    {/* P4-A.3 Group B — inline base64 data URI in the print document
                                        (see the kopSurat logo above). <Image> would pass it through
                                        unoptimized; plain <img> keeps the signature reliably decoded
                                        before window.print() fires.
                                        .ttdBox img { max-height: 70px; max-width: 150px;
                                        object-fit: contain } unchanged, preserving print layout. */}
                                    {/* eslint-disable-next-line @next/next/no-img-element */}
                                    {settings.ttdB64 && <img src={settings.ttdB64} alt="Tanda Tangan" />}
                                </div>
                                <p>({settings.namaKepsek})</p>
                                {settings.nipKepsek && <p>NIP. {settings.nipKepsek}</p>}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    );
}