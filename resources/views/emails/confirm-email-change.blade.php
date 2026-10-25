{{--
    "Confirm your new email address" (SEC #10). Standalone HTML with inline
    styles — email clients don't load external CSS, so the project's
    no-inline-CSS rule (which targets the app UI) doesn't apply here.

    Sent to the NEW address only. Opening the link is what actually moves
    `users.email`; until then the account keeps logging in — and receiving
    password resets — at the old one.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('account.email_change.mail.subject', ['app' => $appName]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="width:520px;max-width:92%;background:#ffffff;border:1px solid #e6e8eb;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="padding:20px 24px;border-bottom:1px solid #eef0f2;">
                            <div style="font-size:16px;font-weight:600;color:#1c1e21;">{{ $appName }}</div>
                            <div style="font-size:12.5px;color:#6b7280;margin-top:2px;">{{ __('account.email_change.mail.heading') }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:22px 24px;">
                            <p style="margin:0 0 14px;font-size:13.5px;line-height:1.6;color:#374151;">
                                {{ __('account.email_change.mail.body', ['name' => $user->name, 'email' => $newEmail]) }}
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0;">
                                <tr>
                                    <td style="background:#2563eb;border-radius:8px;">
                                        <a href="{{ $confirmUrl }}"
                                           style="display:inline-block;padding:11px 20px;font-size:13.5px;font-weight:600;color:#ffffff;text-decoration:none;">
                                            {{ __('account.email_change.mail.cta') }}
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 10px;font-size:12.5px;line-height:1.6;color:#6b7280;">
                                {{ __('account.email_change.mail.expiry', ['hours' => $hours]) }}
                            </p>
                            <p style="margin:0 0 14px;font-size:12.5px;line-height:1.6;color:#6b7280;">
                                {{ __('account.email_change.mail.ignore') }}
                            </p>

                            <p style="margin:0;font-size:11.5px;line-height:1.6;color:#9ca3af;word-break:break-all;">
                                {{ $confirmUrl }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
