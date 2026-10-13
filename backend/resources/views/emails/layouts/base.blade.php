{{--++
    E.9 — SPMB Al-Mubarak shared email layout (editorial billing-style).

    Composition follows the approved reference: soft neutral page background,
    one centered white card, a single dominant solid-color panel carrying the
    headline, secondary information in quiet rows, whitespace as the main
    separator, minimal footer. Solid colors only — no gradients, no images
    required for meaning, no JS, no forms, no external CSS.

    Palette:
      primary       #107163
      dark green    #004C3A
      white         #FFFFFF
      page bg       #E8F1EE
      body text     #24382F (charcoal-green)
      muted text    #6E857C
      hairline      #E4EEEA
      warning       #B45309 on #FBF4E8

    Slots (via @extends(..., [...]) + @section('body')):
      $title       — headline inside the solid panel
      $subtitle    — optional one-line support text under the headline
      $heroColor   — panel background override (default #107163)
      $ctaUrl      — single primary CTA (omit for none)
      $ctaLabel    — CTA label
      $footerNote  — optional extra footer line
--}}
<!DOCTYPE html>
<html lang="id" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $title ?? 'SPMB Al-Mubarak' }}</title>
    <!--[if mso]>
    <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
    <![endif]-->
</head>
<body style="margin:0; padding:0; width:100%; background-color:#E8F1EE; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">

    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#E8F1EE;">
        <tr>
            <td align="center" style="padding:40px 16px 48px 16px;">

                {{-- Wordmark --}}
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" style="width:600px; max-width:100%;">
                    <tr>
                        <td align="center" style="padding-bottom:26px; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
                            <span style="font-size:13px; font-weight:700; letter-spacing:3px; color:#004C3A;">AL&#8209;MUBARAK</span>
                        </td>
                    </tr>
                </table>

                {{-- Card --}}
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" style="width:600px; max-width:100%; background-color:#FFFFFF; border-radius:16px; overflow:hidden;">

                    {{-- Solid headline panel --}}
                    <tr>
                        <td align="center" style="background-color:{{ $heroColor ?? '#107163' }}; padding:46px 36px 42px 36px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                <tr>
                                    <td align="center" valign="middle" width="56" height="56" style="width:56px; height:56px; border:1px solid #FFFFFF; border-radius:999px; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size:16px; font-weight:700; color:#FFFFFF;">
                                        AM
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding-top:22px; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size:26px; line-height:1.35; font-weight:700; color:#FFFFFF;">
                                        {{ $title ?? '' }}
                                    </td>
                                </tr>
                                @if(!empty($subtitle))
                                <tr>
                                    <td align="center" style="padding-top:10px; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size:14px; line-height:1.6; color:#D9EBE4;">
                                        {{ $subtitle }}
                                    </td>
                                </tr>
                                @endif
                            </table>
                        </td>
                    </tr>

                    {{-- Content --}}
                    <tr>
                        <td style="padding:40px 44px 12px 44px; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
                            @yield('body')
                        </td>
                    </tr>

                    {{-- Single primary CTA --}}
                    @if(!empty($ctaUrl))
                    <tr>
                        <td align="center" style="padding:26px 44px 44px 44px;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" bgcolor="#107163" style="border-radius:10px;">
                                        <a href="{{ $ctaUrl }}" target="_blank"
                                           style="display:inline-block; padding:15px 40px; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size:15px; font-weight:700; color:#FFFFFF; text-decoration:none; border-radius:10px; background-color:#107163;">
                                            {{ $ctaLabel ?? 'Buka SPMB Al-Mubarak' }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif
                </table>

                {{-- Footer --}}
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" style="width:600px; max-width:100%;">
                    <tr>
                        <td align="center" style="padding-top:30px; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
                            <p style="margin:0 0 6px 0; font-size:13px; font-weight:700; color:#004C3A;">
                                Yayasan Pendidikan Islam Al-Mubarak
                            </p>
                            <p style="margin:0; font-size:12px; line-height:1.7; color:#6E857C;">
                                Email otomatis dari Sistem SPMB — mohon tidak membalas.<br>
                                @if(!empty($footerNote)){{ $footerNote }}<br>@endif
                                &copy; {{ date('Y') }} Yayasan Pendidikan Islam Al-Mubarak.
                            </p>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
