<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subject ?? config('app.name') }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f5f7; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif; color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f5f7; padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; background-color:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(17,24,39,0.05);">
                    <tr>
                        <td style="padding:28px 32px 20px 32px; background-color:#ffffff; border-bottom:1px solid #eef0f3;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="left" style="font-size:20px; font-weight:700; letter-spacing:-0.01em; color:#111827;">
                                        {{ config('app.name', 'Cilupbah') }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:32px;">
                            {{ $slot }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 32px 28px 32px; background-color:#fafbfc; border-top:1px solid #eef0f3;">
                            <p style="margin:0 0 6px 0; font-size:12px; line-height:18px; color:#6b7280;">
                                Email ini dikirim otomatis oleh sistem {{ config('app.name', 'Cilupbah') }}. Mohon tidak membalas email ini.
                            </p>
                            <p style="margin:0; font-size:12px; line-height:18px; color:#9ca3af;">
                                &copy; {{ date('Y') }} {{ config('app.name', 'Cilupbah') }}. Seluruh hak cipta dilindungi.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
