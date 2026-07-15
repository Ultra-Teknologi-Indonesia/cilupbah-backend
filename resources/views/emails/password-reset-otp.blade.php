@component('emails.layouts.base', ['subject' => 'Kode Reset Kata Sandi'])
<h1 style="margin:0 0 16px 0; font-size:22px; line-height:28px; font-weight:700; color:#111827; letter-spacing:-0.01em;">
    Kode Reset Kata Sandi
</h1>

<p style="margin:0 0 20px 0; font-size:15px; line-height:24px; color:#374151;">
    Halo {{ $userName ?: 'pengguna' }},
</p>

<p style="margin:0 0 20px 0; font-size:15px; line-height:24px; color:#374151;">
    Kami menerima permintaan untuk mengatur ulang kata sandi akun Anda. Gunakan kode berikut untuk melanjutkan proses reset kata sandi:
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">
    <tr>
        <td align="center">
            <div style="display:inline-block; padding:20px 32px; background-color:#f4f5f7; border:1px solid #e5e7eb; border-radius:10px;">
                <div style="font-family:'SF Mono','Menlo','Consolas',monospace; font-size:32px; line-height:40px; font-weight:700; letter-spacing:0.35em; color:#111827;">
                    {{ $otp }}
                </div>
            </div>
        </td>
    </tr>
</table>

<p style="margin:0 0 12px 0; font-size:14px; line-height:22px; color:#374151;">
    Kode ini berlaku selama <strong>{{ $ttlMinutes }} menit</strong>. Setelah itu, Anda perlu meminta kode baru.
</p>

<div style="margin:24px 0; padding:16px; background-color:#fef3c7; border-left:3px solid #f59e0b; border-radius:6px;">
    <p style="margin:0; font-size:13px; line-height:20px; color:#78350f;">
        <strong>Jangan bagikan kode ini kepada siapa pun.</strong> Tim {{ config('app.name', 'Cilupbah') }} tidak akan pernah meminta kode OTP Anda melalui telepon, email, atau chat.
    </p>
</div>

<p style="margin:0 0 8px 0; font-size:14px; line-height:22px; color:#6b7280;">
    Jika Anda tidak meminta reset kata sandi, abaikan email ini — akun Anda tetap aman dan tidak ada perubahan yang terjadi.
</p>
@endcomponent
