{{-- E.9 — quiet summary row: label kiri (muted), nilai kanan (semibold).
     Pemisah antarbaris hanya hairline; whitespace pemisah utama.
     Usage: @include('emails.components.info_row', ['label' => ..., 'value' => ..., 'mono' => false, 'last' => false]) --}}
<tr>
    <td style="padding:11px 0; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size:14px; line-height:1.5; @if(empty($last)) border-bottom:1px solid #E4EEEA; @endif">
        <span style="color:#6E857C;">{{ $label }}</span>
    </td>
    <td align="right" style="padding:11px 0; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size:14px; line-height:1.5; color:#24382F; font-weight:600; @if(!empty($mono)) font-family:'Courier New', Courier, monospace; @endif word-break:break-word;">
        {{ $value }}
    </td>
</tr>
