<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Surat Kelulusan {{ $namaSekolah }}</title>
<style>
    /*
     * SMPIT Al Mubarak graduation letter (A4 portrait, formal).
     * Disesuaikan 100% untuk kompatibilitas ketat Dompdf.
     */
    @page { margin: 0; }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'Times-Roman', 'Times New Roman', serif;
        color: #1a1a2e;
        font-size: 11.5pt;
    }

    .content {
        padding: 12mm 20mm 30mm 20mm;
    }

    /* ---- HEADER KOP SURAT (MURNI TABEL AGAR ANTI-PECAH) ---------------- */
    .header-table { 
        width: 100%; 
        border-collapse: collapse; 
    }
    
    .school-name {
        font-family: 'Times-Roman', 'Times New Roman', serif;
        font-size: 20pt; /* Dikecilkan agar tidak turun baris */
        font-weight: bold;
        color: #2b3a8c; /* Navy Blue */
        letter-spacing: 0.5pt;
    }

    .tagline {
        font-family: 'Times-Roman', 'Times New Roman', serif;
        font-size: 12pt;
        font-style: italic;
        color: #2b3a8c;
        margin-top: 1mm;
    }

    .address-contact {
        font-family: 'Helvetica', 'Arial', sans-serif;
        font-size: 9.5pt;
        color: #333333;
        margin-top: 2mm;
        line-height: 1.4;
    }

    /* Garis ganda Kop Surat */
    .rule-outer {
        border-bottom: 2.5pt solid #2b3a8c;
        margin-top: 4mm;
    }
    .rule-inner {
        border-bottom: 1pt solid #2b3a8c;
        margin-top: 2pt;
    }

    /* ---- BODY SURAT --------------------------------------------------- */
    .surat-title {
        font-family: 'Helvetica', 'Arial', sans-serif;
        text-align: center;
        font-size: 13pt;
        font-weight: bold;
        color: #333333;
        margin-top: 8mm;
        letter-spacing: 0.5pt;
    }
    .surat-no {
        text-align: center;
        font-family: 'Helvetica', 'Arial', sans-serif;
        font-size: 10.5pt;
        color: #333333;
        margin-top: 1.5mm;
    }
    .bismillah {
        text-align: center;
        font-style: italic;
        font-size: 11.5pt;
        color: #1a1a2e;
        margin-top: 6mm;
    }

    .intro {
        margin-top: 7mm;
        text-align: justify;
        line-height: 1.55;
    }

    /* Tabel Identitas */
    .identity-table {
        margin: 5mm 0 0 10mm;
        border-collapse: collapse;
    }
    .identity-table td {
        padding: 1.5mm 0;
        vertical-align: top;
        font-size: 11.5pt;
    }
    .td-bullet { width: 5mm; text-align: left; }
    .td-label { width: 32mm; }
    .td-colon { width: 3mm; }

    .verdict {
        text-align: center;
        font-family: 'Helvetica', 'Arial', sans-serif;
        font-size: 15pt;
        font-weight: bold;
        color: #1a1a2e;
        margin: 8mm 0;
        letter-spacing: 1.5pt;
    }

    .letter-paragraph {
        text-align: justify;
        line-height: 1.6;
        margin-bottom: 6mm;
    }
    .letter-paragraph p { margin: 0 0 3mm 0; }

    /* ---- SIGNATURE ----------------------------------------------------- */
    .signature-table { 
        width: 100%; 
        border-collapse: collapse; 
        margin-top: 15mm; 
    }
    .signature-table td { vertical-align: bottom; }
    .signature-block {
        text-align: left;
        display: inline-block;
    }
    .signature-space { height: 22mm; margin: 2mm 0; }
    .signature-img   { height: 20mm; width: auto; }
    .signature-name  { font-weight: bold; }
    .signature-role  { margin-bottom: 1mm; }

    /* ---- ORNAMEN BINTANG KANAN BAWAH (3 BINTANG) ----------------------- */
    .page-ornament {
        position: fixed;
        right: 12mm;
        bottom: 12mm;
        width: 35mm;
        height: 35mm;
    }
    .ornament-img { display: block; width: 100%; height: 100%; }

</style>
</head>
<body>

{{-- ORNAMEN: 3 Bintang Islam (Rub el Hizb) sesuai foto --}}
<div class="page-ornament" aria-hidden="true">
    @php
        $ornamentSvg = <<<'SVG'
<svg width="100" height="100" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
    <g fill="#2b3a8c">
        <!-- Bintang Atas Kanan -->
        <g transform="translate(80, 20)">
            <polygon points="-12,-12 12,-12 12,12 -12,12" />
            <polygon points="-12,-12 12,-12 12,12 -12,12" transform="rotate(45)" />
            <circle cx="0" cy="0" r="3.5" fill="#f59e0b" />
        </g>
        <!-- Bintang Bawah Kiri -->
        <g transform="translate(35, 80)">
            <polygon points="-12,-12 12,-12 12,12 -12,12" />
            <polygon points="-12,-12 12,-12 12,12 -12,12" transform="rotate(45)" />
            <circle cx="0" cy="0" r="3.5" fill="#f59e0b" />
        </g>
        <!-- Bintang Bawah Kanan -->
        <g transform="translate(80, 80)">
            <polygon points="-12,-12 12,-12 12,12 -12,12" />
            <polygon points="-12,-12 12,-12 12,12 -12,12" transform="rotate(45)" />
            <circle cx="0" cy="0" r="3.5" fill="#f59e0b" />
        </g>
    </g>
</svg>
SVG;
    @endphp
    <img class="ornament-img" src="{{ \App\Support\SuratKelulusanViewHelper::svgDataUri($ornamentSvg) }}" alt="">
</div>

<div class="content">

    {{-- HEADER KOP SURAT (DILOCK DENGAN WIDTH ATRIBUT HTML AGAR ANTI PECAH) --}}
    <table class="header-table" width="100%">
        <tr>
            <td width="20%" align="left" valign="middle">
                @if ($logoDataUri)
                    {{-- Batas maksimal gambar dilock di inline CSS --}}
                    <img src="{{ $logoDataUri }}" alt="Logo" style="max-width: 80px; max-height: 85px;">
                @endif
            </td>
            <td width="60%" align="center" valign="middle">
                <div class="school-name">SMPIT AL MUBARAK</div>
                <div class="tagline">Sholih - Tangguh - Berdaya Guna</div>
                <div class="address-contact">
                    {{ $alamat }}<br>
                    {{ $kontak }}
                </div>
            </td>
            {{-- Sel kosong agar teks benar-benar di tengah --}}
            <td width="20%"></td>
        </tr>
    </table>
    
    {{-- Garis Ganda Pembatas Kop --}}
    <div class="rule-outer"></div>
    <div class="rule-inner"></div>

    {{-- TITLE & BISMILLAH --}}
    <div class="surat-title">SURAT KEPUTUSAN</div>
    @if ($nomorSurat !== '')
        <div class="surat-no">No. : {{ $nomorSurat }}</div>
    @endif
    <div class="bismillah">Bismillaahirrahmaanirrahiim</div>

    {{-- INTRO PARAGRAPH --}}
    <div class="intro">
        Berdasarkan hasil rekomendasi Panitia Sistem Penerimaan Murid Baru
        {{ $namaSekolah }} Tahun Pelajaran 2026/2027, maka dengan ini
        dinyatakan bahwa:
    </div>

    {{-- IDENTITY --}}
    <table class="identity-table">
        <tr>
            <td class="td-bullet">&#8226;</td>
            <td class="td-label">Nama</td>
            <td class="td-colon">:</td>
            <td><strong>{{ $namaMurid }}</strong></td>
        </tr>
        @if ($asalSekolah)
        <tr>
            <td class="td-bullet">&#8226;</td>
            <td class="td-label">Asal Sekolah</td>
            <td class="td-colon">:</td>
            <td>{{ $asalSekolah }}</td>
        </tr>
        @endif
    </table>

    {{-- VERDICT --}}
    <div class="verdict">
        {{ $status === 'LULUS' ? 'DITERIMA' : 'TIDAK DITERIMA' }}
    </div>

    {{-- BODY PARAGRAPH(S) --}}
    <div class="letter-paragraph">
        {!! $isiHtml !!}
    </div>

    {{-- SIGNATURE --}}
    <table class="signature-table">
        <tr>
            <td style="width: 60%;"></td>
            <td style="width: 40%; text-align: left;">
                <div class="signature-block">
                    <div class="signature-role">Kepala Sekolah</div>
                    <div class="signature-space">
                        @if ($ttdDataUri)
                            <img class="signature-img" src="{{ $ttdDataUri }}" alt="Tanda tangan">
                        @endif
                    </div>
                    <div class="signature-name">{{ $namaKepsek }}</div>
                </div>
            </td>
        </tr>
    </table>
</div>

</body>
</html>