{{-- E.9 — payment status email, invoice-style composition on the shared base.
     Data dinamis dari PaymentStatusMail (server-authoritative):
       kind (issued|verified|rejected), nama, invoice, category, jenjang,
       amount, reason, instructions (bank_name, rekening, owner) --}}
@php
    $hero = match ($data['kind']) {
        'verified' => ['#004C3A', 'Pembayaran Diterima', 'Biaya pendaftaran Anda telah diverifikasi.'],
        'rejected' => ['#B45309', 'Pembayaran Belum Dapat Diverifikasi', 'Mohon periksa rincian berikut dan unggah ulang bukti pembayaran.'],
        default    => ['#107163', 'Bukti Pembayaran Diterima', 'Pembayaran Anda sedang kami verifikasi.'],
    };
@endphp
@extends('emails.layouts.base', [
    'title'    => $hero[1],
    'subtitle' => $hero[2],
    'heroColor'=> $hero[0],
    'ctaUrl'   => rtrim(config('app.frontend_url'), '/') . '/pendaftar/pembayaran',
    'ctaLabel' => 'Lihat Status Pembayaran',
])

@section('body')
    @if($data['nama'])
        <p style="margin:0 0 14px 0; font-size:16px; color:#24382F;">
            Yth. <strong>{{ $data['nama'] }}</strong>,
        </p>
    @endif

    @if($data['kind'] === 'verified')
        <p style="margin:0 0 30px 0; font-size:15px; line-height:1.7; color:#4A5F56;">
            Pembayaran Anda sudah kami terima dan verifikasi. Silakan lanjutkan
            tahapan SPMB berikutnya sesuai jadwal yang berlaku.
        </p>
    @elseif($data['kind'] === 'rejected')
        <p style="margin:0 0 30px 0; font-size:15px; line-height:1.7; color:#4A5F56;">
            Mohon maaf, bukti pembayaran yang Anda unggah belum dapat kami
            verifikasi. Berikut rinciannya beserta alasan penolakan.
        </p>
    @else
        <p style="margin:0 0 30px 0; font-size:15px; line-height:1.7; color:#4A5F56;">
            Bukti pembayaran Anda sudah masuk dan menunggu verifikasi petugas.
            Simpan rincian berikut sebagai referensi.
        </p>
    @endif

    {{-- Ringkasan transaksi: baris rapi ala invoice --}}
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
        @include('emails.components.info_row', ['label' => 'Nomor referensi', 'value' => $data['invoice'], 'mono' => true])
        @include('emails.components.info_row', ['label' => 'Jenis pembayaran', 'value' => $data['category']])
        @include('emails.components.info_row', ['label' => 'Jenjang', 'value' => $data['jenjang']])
        @include('emails.components.info_row', ['label' => 'Status', 'value' => match($data['kind']) { 'verified' => 'LUNAS', 'rejected' => 'DITOLAK', default => 'MENUNGGU VERIFIKASI' }, 'last' => true])
        <tr>
            <td style="padding:18px 0 6px 0; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size:14px; color:#6E857C;">
                Total dibayar
            </td>
            <td align="right" style="padding:18px 0 6px 0; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size:22px; font-weight:700; color:#107163;">
                Rp {{ number_format((float) $data['amount'], 0, ',', '.') }}
            </td>
        </tr>
    </table>

    @if($data['kind'] === 'issued' && !empty($data['instructions']))
        <p style="margin:26px 0 8px 0; font-size:15px; font-weight:700; color:#24382F;">
            Tujuan transfer
        </p>
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
            @include('emails.components.info_row', ['label' => 'Bank', 'value' => $data['instructions']['bank_name'] ?? '-'])
            @include('emails.components.info_row', ['label' => 'Nomor rekening', 'value' => $data['instructions']['rekening'] ?? '-', 'mono' => true])
            @include('emails.components.info_row', ['label' => 'Atas nama', 'value' => $data['instructions']['owner'] ?? '-', 'last' => true])
        </table>
    @endif

    @if($data['kind'] === 'rejected')
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#FBF4E8; border-radius:12px; margin-top:26px;">
            <tr>
                <td style="padding:16px 20px;">
                    <p style="margin:0; font-size:13px; line-height:1.65; color:#8A4B12;">
                        <strong>Alasan penolakan:</strong> {{ $data['reason'] }}
                    </p>
                </td>
            </tr>
        </table>
    @endif
@endsection
