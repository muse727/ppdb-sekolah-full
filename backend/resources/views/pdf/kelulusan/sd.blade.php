<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Surat Kelulusan {{ $namaSekolah }}</title>
<style>
    /*
     * APPROVED SDIT graduation letter (A4 portrait, formal).
     * Disesuaikan untuk kompatibilitas Dompdf.
     */
    @page { margin: 0; }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'Times-Roman', 'Times New Roman', serif;
        color: #1a1a2e;
        font-size: 11.5pt;
    }

    .content {
        padding: 12mm 20mm 45mm 20mm;
    }

    /* ---- HEADER --------------------------------------------------------- */
    .header { text-align: center; }
    .logo-img { height: 26mm; width: auto; margin-bottom: 2mm; }
    
    .school-name {
        font-family: 'Times-Roman', 'Times New Roman', serif;
        font-size: 26pt;
        font-weight: 900; /* Dibuat sangat tebal */
        /* Trik gradasi untuk teks di webkit/dompdf */
        background: linear-gradient(180deg, #ea580c 0%, #f59e0b 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        color: #d97706; /* Fallback jika gradasi tidak didukung penuh */
        letter-spacing: 1pt;
        text-shadow: 1px 1px 0px rgba(0,0,0,0.15); 
        margin-top: 1mm;
    }

    .surat-title {
        font-family: 'Helvetica', 'Arial', sans-serif;
        text-align: center;
        font-size: 13pt;
        font-weight: bold;
        color: #333333;
        margin-top: 5mm;
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
        margin-top: 3.5mm;
    }

    /* ---- BODY ----------------------------------------------------------- */
    .intro {
        margin-top: 7mm;
        text-align: justify;
        line-height: 1.55;
    }

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
        margin: 7mm 0;
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
        margin-top: 8mm; 
    }
    .signature-table td { vertical-align: bottom; }
    
    .signature-block-container {
        /* Memaksa blok tanda tangan ke sisi paling kanan */
        float: right; 
        width: 60mm; /* Lebar pasti untuk area teks tanda tangan */
    }
    .signature-block {
        text-align: left;
    }
    
    .signature-space { height: 22mm; margin: 2mm 0; }
    .signature-img   { height: 20mm; width: auto; }
    .signature-name  { font-weight: bold; }
    .signature-role  { margin-bottom: 1mm; }

    /* Membersihkan efek float */
    .clear { clear: both; }

    /* ---- ORNAMENTS (Bintang Kembar) ------------------------------------ */
    .page-ornament {
        position: absolute;
        right: 15mm;
        bottom: 36mm;
        width: 35mm;
        height: 15mm;
    }
    .ornament-img { display: block; width: 100%; height: 100%; }

    /* ---- FOOTER -------------------------------------------------------- */
    .footer {
        position: fixed;
        left: 0; right: 0; bottom: 0;
        height: 42mm; 
    }
    .footer-bg {
        position: absolute;
        left: 0; bottom: 0;
        width: 100%;
        height: 42mm;
        z-index: -1;
    }
    .footer-content {
        position: absolute;
        left: 15mm; right: 15mm; bottom: 8mm; /* Posisi lebih ke bawah agar pas dengan wave */
    }
    .footer-table { 
        width: 100%; 
        border-collapse: collapse; 
    }
    .footer-table td { vertical-align: bottom; } /* Sejajarkan ke bawah */
    
    .footer-contact {
        font-family: 'Helvetica', 'Arial', sans-serif;
        font-size: 8pt; 
        line-height: 1.4;
        color: #4c1d95; 
        text-align: left;
    }
    .footer-motto {
        font-family: 'Times-Roman', serif;
        font-size: 11pt;
        font-style: italic;
        font-weight: bold;
        color: #4c1d95; 
        text-align: right;
        white-space: nowrap;
    }
</style>
</head>
<body>

{{-- ORNAMENTS --}}
<div class="page-ornament" aria-hidden="true">
    @php
        $ornamentSvg = <<<'SVG'
<svg width="200" height="80" viewBox="0 0 200 80" xmlns="http://www.w3.org/2000/svg">
    <g stroke="#3f3d56" stroke-width="2" fill="none">
        <g transform="translate(50, 40)">
            <polygon points="-22,-22 22,-22 22,22 -22,22" />
            <polygon points="-22,-22 22,-22 22,22 -22,22" transform="rotate(45)" />
            <circle cx="0" cy="0" r="14" stroke="#c8871a" stroke-width="2"/>
        </g>
        <g transform="translate(150, 40)">
            <polygon points="-22,-22 22,-22 22,22 -22,22" />
            <polygon points="-22,-22 22,-22 22,22 -22,22" transform="rotate(45)" />
            <circle cx="0" cy="0" r="14" stroke="#c8871a" stroke-width="2"/>
        </g>
    </g>
</svg>
SVG;
    @endphp
    <img class="ornament-img" src="{{ \App\Support\SuratKelulusanViewHelper::svgDataUri($ornamentSvg) }}" alt="">
</div>

{{-- FOOTER --}}
<div class="footer">
    @php
        $footerWaveSvg = <<<'SVG'
<svg width="2100" height="400" viewBox="0 0 2100 400" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
    <path d="M0,150 C600,280 1500,-20 2100,120 L2100,400 L0,400 Z" fill="#e9d8fd" />
    <path d="M0,150 C600,280 1500,-20 2100,120" fill="none" stroke="#f59e0b" stroke-width="12" />
</svg>
SVG;
    @endphp
    <img class="footer-bg" src="{{ \App\Support\SuratKelulusanViewHelper::svgDataUri($footerWaveSvg) }}" alt="">
    
    <div class="footer-content">
        <table class="footer-table">
            <tr>
                <td class="footer-contact">
                    {{ $alamat }}<br>
                    {{ $kontak }}
                </td>
                <td class="footer-motto">&#8226; bertakwa &#8226; mandiri &#8226; kreatif &#8226; cerdas</td>
            </tr>
        </table>
    </div>
</div>

<div class="content">
    {{-- HEADER --}}
    <div class="header">
        @if ($logoDataUri)
            <img class="logo-img" src="{{ $logoDataUri }}" alt="Logo {{ $namaSekolah }}">
        @endif
        <div class="school-name">{{ $namaSekolah }}</div>
    </div>

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
    <div class="signature-block-container">
        <div class="signature-block">
            <div class="signature-role">Kepala Sekolah</div>
            <div class="signature-space">
                @if ($ttdDataUri)
                    <img class="signature-img" src="{{ $ttdDataUri }}" alt="Tanda tangan">
                @endif
            </div>
            <div class="signature-name">{{ $namaKepsek }}</div>
        </div>
    </div>
    <div class="clear"></div>
</div>

</body>
</html>