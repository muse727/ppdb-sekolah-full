{{-- E.10 — reset password email on the Al-Mubarak editorial base layout.
     Dynamic data from ResetPasswordMail:
       $data['nama']   — nama pemilik akun
       $data['url']    — URL reset (frontend URL tervalidasi + token broker sekali pakai)
       $data['expiry'] — tenggat token (WIB)
     Tidak pernah menyertakan password. Tidak ada JavaScript. --}}
@extends('emails.layouts.base', [
    'title'    => 'Reset Password Anda',
    'subtitle' => 'Sistem Penerimaan Murid Baru SMPIT Al-Mubarak',
    'ctaUrl'   => $data['url'],
    'ctaLabel' => 'Buat Password Baru',
    'footerNote' => 'Tautan ini berlaku 60 menit dan hanya dapat digunakan satu kali.',
])

@section('body')
    <p style="margin:0 0 14px 0; font-size:16px; color:#24382F;">
        Yth. <strong>{{ $data['nama'] ?? '' }}</strong>,
    </p>
    <p style="margin:0 0 28px 0; font-size:15px; line-height:1.7; color:#4A5F56;">
        Kami menerima permintaan reset password untuk akun SPMB Anda. Klik tombol
        di bawah ini untuk membuat password baru.
    </p>

    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#004C3A; border-radius:14px;">
        <tr>
            <td align="center" style="padding:30px 20px 26px 20px; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
                <span style="font-size:14px; line-height:1.6; color:#D9EBE4;">
                    Tautan berlaku hingga<br>
                    <strong style="color:#FFFFFF;">{{ $data['expiry'] ?? '' }} WIB</strong>
                </span>
            </td>
        </tr>
    </table>

    <p style="margin:24px 0 8px 0; font-size:15px; font-weight:700; color:#24382F;">
        Jika tombol tidak berfungsi
    </p>
    <p style="margin:0 0 26px 0; font-size:13px; line-height:1.7; color:#6E857C; word-break:break-all;">
        Salin dan tempel tautan berikut ke browser Anda:<br>
        <a href="{{ $data['url'] }}" style="color:#107163; text-decoration:underline;">{{ $data['url'] }}</a>
    </p>

    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#FBF4E8; border-radius:12px;">
        <tr>
            <td style="padding:16px 20px;">
                <p style="margin:0; font-size:13px; line-height:1.65; color:#8A4B12;">
                    Jika Anda tidak meminta reset password, abaikan email ini — password Anda tidak akan berubah.
                    Semua sesi login akan otomatis keluar setelah password berhasil diganti.
                </p>
            </td>
        </tr>
    </table>
@endsection
