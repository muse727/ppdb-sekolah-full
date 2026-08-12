'use client';

import { useEffect, useState } from 'react';
import styles from './cetak.module.css';

export default function CetakLaporanPage() {
    const [dataSiswa, setDataSiswa] = useState<any>(null);
    const [settings, setSettings] = useState<any>(null);
    const [jsonData, setJsonData] = useState<any>({});
    const [scoreInfo, setScoreInfo] = useState({ total: 0, max: 0 });

    useEffect(() => {
        const printData = localStorage.getItem('goSkool_printData');
        const printSettings = localStorage.getItem('goSkool_printSettings');

        if (printData) {
            const parsed = JSON.parse(printData);
            setDataSiswa(parsed);
            
            const json = parsed.jawaban_json || {};
            setJsonData(json);

            let t = 0;
            let m = Object.keys(json).length * 2;
            Object.keys(json).forEach(k => {
                if(json[k].jawaban === 'YA') t += 2;
                else if(json[k].jawaban === 'TIDAK KONSISTEN') t += 1;
            });
            setScoreInfo({ total: t, max: m });
        }

        if (printSettings) {
            setSettings(JSON.parse(printSettings));
        } else {
            setSettings({
                tanggal: 'Jakarta, 15 Juli 2026',
                namaKepsek: 'Amang Suryaman, M.Pd.',
                nipKepsek: '-',
                logoB64: '',
                ttdB64: ''
            });
        }
    }, []);

    useEffect(() => {
        if (dataSiswa && settings) {
            setTimeout(() => {
                window.print();
            }, 800); 
        }
    }, [dataSiswa, settings]);

    if (!dataSiswa || !settings) return <div style={{padding: '50px', textAlign: 'center', fontFamily: 'sans-serif'}}>Menyiapkan Dokumen...</div>;

    const listSaran = Object.keys(jsonData).filter(key => jsonData[key].saran && jsonData[key].saran.trim() !== '');

    return (
        <div className={styles.printContainer}>
            <div className={styles.headerKop}>
                <div className={styles.logoBox}>
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
                                <td style={{textAlign: 'center'}}>{item.jawaban === 'YA' ? '✓' : ''}</td>
                                <td style={{textAlign: 'center'}}>{item.jawaban === 'TIDAK KONSISTEN' ? '✓' : ''}</td>
                                <td style={{textAlign: 'center'}}>{item.jawaban === 'TIDAK' ? '✓' : ''}</td>
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
                    <p style={{ margin: '5px 0 0 0', fontSize: '11px', color: '#cc0000', fontStyle: 'italic' }}>* Catatan: Ananda dinyatakan Lulus Bersyarat dan membutuhkan penandatanganan surat kesepakatan khusus dari pihak sekolah.</p>
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