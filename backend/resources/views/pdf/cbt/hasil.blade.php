<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Hasil Seleksi CBT SMPIT Al Mubarak</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1a202c; line-height: 1.5; margin: 20px; }
        .kop { border-bottom: 2px solid #1a5f51; padding-bottom: 12px; margin-bottom: 20px; }
        .kop-table { width: 100%; border-collapse: collapse; }
        .kop-table td { vertical-align: middle; }
        .kop-title { text-align: center; }
        .kop-title h1 { margin: 0; font-size: 16px; color: #1a5f51; text-transform: uppercase; font-weight: bold; }
        .kop-title h2 { margin: 2px 0 0 0; font-size: 12px; font-weight: normal; color: #4a5568; }
        .kop-title p { margin: 2px 0 0 0; font-size: 10px; color: #718096; }
        .doc-title { text-align: center; margin: 25px 0 15px 0; }
        .doc-title h3 { margin: 0; font-size: 14px; text-decoration: underline; color: #1a202c; }
        .doc-title p { margin: 3px 0 0 0; font-size: 11px; color: #4a5568; }
        table.info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.info-table td { padding: 6px 4px; font-size: 11px; }
        table.info-table td.label { width: 22%; font-weight: bold; color: #2d3748; }
        table.info-table td.colon { width: 3%; }
        table.score-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        table.score-table th, table.score-table td { border: 1px solid #cbd5e0; padding: 10px 12px; text-align: left; }
        table.score-table th { background-color: #f7fafc; color: #2d3748; font-size: 11px; font-weight: bold; }
        .score-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 15px; margin-top: 20px; text-align: center; }
        .score-big { font-size: 28px; font-weight: bold; color: #166534; margin: 5px 0; }
        .footer-ttd { width: 100%; margin-top: 40px; border-collapse: collapse; }
        .footer-ttd td { vertical-align: top; font-size: 11px; }
    </style>
</head>
<body>
    <div class="kop">
        <table class="kop-table">
            <tr>
                @if(!empty($logoDataUri))
                <td style="width: 70px; text-align: left;">
                    <img src="{{ $logoDataUri }}" style="max-height: 60px; max-width: 60px;">
                </td>
                @endif
                <td class="kop-title">
                    <h1>{{ $namaSekolah }}</h1>
                    <h2>{{ $tagline }}</h2>
                    <p>{{ $alamat }} &bull; {{ $kontak }}</p>
                </td>
            </tr>
        </table>
    </div>

    <div class="doc-title">
        <h3>LEMBAR HASIL SELEKSI CBT (COMPUTER-BASED TEST)</h3>
        <p>PENERIMAAN PESERTA DIDIK BARU TAHUN AJARAN 2026/2027</p>
    </div>

    <table class="info-table">
        <tr>
            <td class="label">Nomor Peserta</td>
            <td class="colon">:</td>
            <td>REG-00{{ $user->id }}</td>
            <td class="label">Sesi Ujian</td>
            <td class="colon">:</td>
            <td>{{ $cbt->session->nama_ujian ?? 'Ujian Seleksi CBT' }}</td>
        </tr>
        <tr>
            <td class="label">Nama Lengkap</td>
            <td class="colon">:</td>
            <td><strong>{{ $biodata->nama_lengkap ?? $user->name }}</strong></td>
            <td class="label">Tanggal Pelaksanaan</td>
            <td class="colon">:</td>
            <td>{{ $tanggalUjian }}</td>
        </tr>
        <tr>
            <td class="label">Jenjang Pendidikan</td>
            <td class="colon">:</td>
            <td>SMPIT AL MUBARAK</td>
            <td class="label">Status Kelulusan</td>
            <td class="colon">:</td>
            <td><strong style="color: {{ $user->status_kelulusan === 'LULUS' ? '#166534' : '#991b1b' }};">{{ $user->status_kelulusan }}</strong></td>
        </tr>
    </table>

    <table class="score-table">
        <thead>
            <tr>
                <th style="width: 5%; text-align: center;">No</th>
                <th style="width: 45%;">Komponen Penilaian</th>
                <th style="width: 25%; text-align: center;">Keterangan</th>
                <th style="width: 25%; text-align: center;">Skor Diperoleh</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="text-align: center;">1</td>
                <td>Pilihan Ganda (PG)</td>
                <td style="text-align: center;">Benar: {{ $cbt->benar }} &bull; Salah: {{ $cbt->salah }}</td>
                <td style="text-align: center; font-weight: bold;">{{ number_format($cbt->skor, 1) }}</td>
            </tr>
            <tr>
                <td style="text-align: center;">2</td>
                <td>Penilaian Uraian / Esai</td>
                <td style="text-align: center;">{{ $cbt->is_koreksi_esai ? 'Telah Dikoreksi' : 'Dalam Penilaian' }}</td>
                <td style="text-align: center; font-weight: bold;">{{ number_format($cbt->skor_esai, 1) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="score-box">
        <div style="font-size: 12px; color: #166534; font-weight: bold;">TOTAL NILAI AKHIR CBT</div>
        <div class="score-big">{{ number_format($totalSkor, 1) }}</div>
        <div style="font-size: 11px; color: #4a5568;">Hasil akumulasi dari seluruh butir instrumen ujian berbasis komputer.</div>
    </div>

    <table class="footer-ttd">
        <tr>
            <td style="width: 60%;"></td>
            <td style="width: 40%; text-align: center;">
                <p style="margin: 0;">Jakarta, {{ $tanggalSurat }}</p>
                <p style="margin: 4px 0 0 0; font-weight: bold;">Panitia Seleksi SPMB SMPIT</p>
                @if(!empty($ttdDataUri))
                <div style="height: 65px; margin: 5px 0;">
                    <img src="{{ $ttdDataUri }}" style="max-height: 60px;">
                </div>
                @else
                <div style="height: 65px;"></div>
                @endif
                <p style="margin: 0; font-weight: bold; text-decoration: underline;">{{ $namaKepsek }}</p>
                <p style="margin: 2px 0 0 0; font-size: 10px; color: #4a5568;">Kepala SMPIT Al Mubarak</p>
            </td>
        </tr>
    </table>
</body>
</html>
