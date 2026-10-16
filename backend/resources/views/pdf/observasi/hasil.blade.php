<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Hasil Observasi SDIT Al Mubarak</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1a202c; line-height: 1.4; margin: 15px; }
        .kop { border-bottom: 2px solid #1a5f51; padding-bottom: 10px; margin-bottom: 15px; }
        .kop-table { width: 100%; border-collapse: collapse; }
        .kop-table td { vertical-align: middle; }
        .kop-title { text-align: center; }
        .kop-title h1 { margin: 0; font-size: 15px; color: #1a5f51; text-transform: uppercase; font-weight: bold; }
        .kop-title h2 { margin: 2px 0 0 0; font-size: 11px; font-weight: normal; color: #4a5568; }
        .kop-title p { margin: 2px 0 0 0; font-size: 9px; color: #718096; }
        .doc-title { text-align: center; margin: 15px 0 10px 0; }
        .doc-title h3 { margin: 0; font-size: 13px; text-decoration: underline; color: #1a202c; }
        .doc-title p { margin: 2px 0 0 0; font-size: 10px; color: #4a5568; }
        table.info-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.info-table td { padding: 4px; font-size: 10px; }
        table.info-table td.label { width: 22%; font-weight: bold; color: #2d3748; }
        table.info-table td.colon { width: 3%; }
        table.score-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table.score-table th, table.score-table td { border: 1px solid #cbd5e0; padding: 6px 8px; text-align: left; }
        table.score-table th { background-color: #f7fafc; color: #2d3748; font-size: 10px; font-weight: bold; }
        .section-heading { font-weight: bold; font-size: 11px; color: #1a5f51; margin-top: 15px; margin-bottom: 5px; text-transform: uppercase; }
        .check { text-align: center; font-weight: bold; color: #1a5f51; font-size: 12px; }
        .footer-ttd { width: 100%; margin-top: 25px; border-collapse: collapse; }
        .footer-ttd td { vertical-align: top; font-size: 10px; }
    </style>
</head>
<body>
    <div class="kop">
        <table class="kop-table">
            <tr>
                @if(!empty($logoDataUri))
                <td style="width: 65px; text-align: left;">
                    <img src="{{ $logoDataUri }}" style="max-height: 55px; max-width: 55px;">
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
        <h3>LEMBAR HASIL OBSERVASI CALON PESERTA DIDIK</h3>
        <p>PENERIMAAN PESERTA DIDIK BARU TAHUN AJARAN 2026/2027</p>
    </div>

    <table class="info-table">
        <tr>
            <td class="label">Nomor Peserta</td>
            <td class="colon">:</td>
            <td>REG-00{{ $user->id }}</td>
            <td class="label">Nama Lengkap</td>
            <td class="colon">:</td>
            <td><strong>{{ $biodata->nama_lengkap ?? $user->name }}</strong></td>
        </tr>
        <tr>
            <td class="label">Jenjang Pendidikan</td>
            <td class="colon">:</td>
            <td>SDIT AL MUBARAK</td>
            <td class="label">Rekomendasi Akhir</td>
            <td class="colon">:</td>
            <td><strong>{{ $hasil->rekomendasi_akhir ?? $user->status_kelulusan }}</strong></td>
        </tr>
    </table>

    <div class="section-heading">Keterampilan Dasar yang Diamati</div>
    <table class="score-table">
        <thead>
            <tr>
                <th rowspan="2" style="width: 5%; text-align: center;">No</th>
                <th rowspan="2" style="width: 55%;">Keterampilan</th>
                <th colspan="3" style="text-align: center;">Penguasaan</th>
            </tr>
            <tr>
                <th style="width: 13%; text-align: center;">Baik</th>
                <th style="width: 13%; text-align: center;">Cukup</th>
                <th style="width: 14%; text-align: center;">Perlu Bimbingan</th>
            </tr>
        </thead>
        <tbody>
            @php $no = 1; @endphp
            @forelse($jawabanList as $item)
            <tr>
                <td style="text-align: center;">{{ $no++ }}</td>
                <td><strong>{{ $item['kategori'] ?? 'Kategori' }}:</strong> {{ $item['keterampilan'] ?? ($item['title'] ?? '-') }}</td>
                <td class="check">{{ ($item['jawaban'] ?? '') === 'YA' ? 'v' : '' }}</td>
                <td class="check">{{ ($item['jawaban'] ?? '') === 'TIDAK KONSISTEN' ? 'v' : '' }}</td>
                <td class="check">{{ ($item['jawaban'] ?? '') === 'TIDAK' ? 'v' : '' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align: center; color: #718096;">Belum ada butir observasi yang tercatat.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    @if(count($saranList) > 0)
    <div class="section-heading">Saran Stimulasi & Tindak Lanjut di Rumah</div>
    <table class="score-table">
        <thead>
            <tr>
                <th style="width: 30%;">Keterampilan</th>
                <th style="width: 70%;">Rekomendasi Stimulasi</th>
            </tr>
        </thead>
        <tbody>
            @foreach($saranList as $saran)
            <tr>
                <td><strong>{{ $saran['keterampilan'] ?? ($saran['kategori'] ?? 'Keterampilan') }}</strong></td>
                <td>{{ $saran['saran'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <table class="footer-ttd">
        <tr>
            <td style="width: 60%;"></td>
            <td style="width: 40%; text-align: center;">
                <p style="margin: 0;">Jakarta, {{ $tanggalSurat }}</p>
                <p style="margin: 4px 0 0 0; font-weight: bold;">Tim Observasi SDIT</p>
                @if(!empty($ttdDataUri))
                <div style="height: 60px; margin: 5px 0;">
                    <img src="{{ $ttdDataUri }}" style="max-height: 55px;">
                </div>
                @else
                <div style="height: 60px;"></div>
                @endif
                <p style="margin: 0; font-weight: bold; text-decoration: underline;">{{ $namaKepsek }}</p>
                <p style="margin: 2px 0 0 0; font-size: 9px; color: #4a5568;">Kepala SDIT Al Mubarak</p>
            </td>
        </tr>
    </table>
</body>
</html>
