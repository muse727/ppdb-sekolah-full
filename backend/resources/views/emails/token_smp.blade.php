{{-- E.9 — email token SMPIT, editorial composition on the shared base layout.
     Dynamic data (contract TokenSmpMail, unchanged):
       $data['nama']   — nama calon peserta didik / orang tua
       $data['token']  — kode sekali pakai (hanya ada di email ini)
       $data['expiry'] — masa berlaku (teks)
     Keamanan token (random_bytes, HMAC-SHA256 digest, TTL 24 jam, single-use,
     resend invalidation) tetap di SmpEmailTokenService — tidak disentuh.
     Token ditampilkan sebagai satu fokus visual: panel solid kontras, huruf
     monospace besar, mudah diblok dan disalin manual. Tanpa JavaScript. --}}
@extends('emails.layouts.base', [
    'title'    => 'Kode Token Pendaftaran',
    'subtitle' => 'Sistem Penerimaan Murid Baru SMPIT Al-Mubarak',
    'ctaUrl'   => rtrim(config('app.frontend_url'), '/') . '/login',
    'ctaLabel' => 'Lanjutkan Pendaftaran',
])

@section('body')
    <p style="margin:0 0 14px 0; font-size:16px; color:#24382F;">
        Ahlan wa Sahlan, <strong>{{ $data['nama'] ?? '' }}</strong>.
    </p>
    <p style="margin:0 0 30px 0; font-size:15px; line-height:1.7; color:#4A5F56;">
        Terima kasih telah mendaftar di SMPIT Al-Mubarak. Berikut kode token untuk
        menyelesaikan pendaftaran akun Anda.
    </p>

    {{-- Panel token: satu-satunya fokus visual --}}
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#004C3A; border-radius:14px;">
        <tr>
            <td align="center" style="padding:30px 20px 26px 20px;">
                <span style="font-family:'Courier New', Courier, monospace; font-size:26px; font-weight:700; letter-spacing:2px; color:#FFFFFF; line-height:1.45; word-break:break-all;">
                    {{ $data['token'] ?? '' }}
                </span>
            </td>
        </tr>
    </table>
    <p style="margin:12px 0 30px 0; font-size:13px; line-height:1.6; color:#6E857C; text-align:center;">
        Berlaku hingga {{ $data['expiry'] ?? '' }} (WIB) dan hanya dapat digunakan satu kali.
    </p>

    <p style="margin:0 0 8px 0; font-size:15px; font-weight:700; color:#24382F;">
        Cara menggunakannya
    </p>
    <p style="margin:0 0 28px 0; font-size:14px; line-height:1.85; color:#4A5F56;">
        Buka halaman pendaftaran, pilih menu Daftar Akun, lalu pilih jalur Email.
        Tempelkan kode di atas beserta email orang tua yang Anda daftarkan, dan
        lengkapi biodata hingga selesai.
    </p>

    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#FBF4E8; border-radius:12px;">
        <tr>
            <td style="padding:16px 20px;">
                <p style="margin:0; font-size:13px; line-height:1.65; color:#8A4B12;">
                    Kode ini bersifat rahasia. Jangan membagikannya kepada siapa pun —
                    pihak sekolah tidak akan pernah memintanya di luar halaman pendaftaran.
                </p>
            </td>
        </tr>
    </table>
@endsection
