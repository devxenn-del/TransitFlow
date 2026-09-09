<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f3f5;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#212529;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f5;padding:32px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">
                    <tr>
                        <td style="background:#10151d;color:#f5a623;padding:20px 28px;font-weight:700;font-size:18px;letter-spacing:.02em;">
                            {{ config('app.name') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 14px;font-size:16px;">Hi {{ $admin->name }},</p>

                            <p style="margin:0 0 14px;line-height:1.55;">
                                A company account for <strong>{{ $company->name }}</strong> has been created for you on
                                {{ config('app.name') }}. You are its Company Admin. Use the credentials below to sign in,
                                then change your password from your profile.
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" width="100%"
                                   style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;margin:8px 0 20px;">
                                <tr>
                                    <td style="padding:14px 16px;font-size:14px;">
                                        <div style="color:#6c757d;">Sign-in email</div>
                                        <div style="font-weight:600;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $admin->email }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 16px 14px;font-size:14px;">
                                        <div style="color:#6c757d;">Temporary password</div>
                                        <div style="font-weight:600;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $temporaryPassword }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 16px 14px;font-size:14px;">
                                        <div style="color:#6c757d;">Company code</div>
                                        <div style="font-weight:600;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $company->code }}</div>
                                    </td>
                                </tr>
                            </table>

                            <a href="{{ $loginUrl }}"
                               style="display:inline-block;background:#14213b;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:600;">
                                Sign in to {{ config('app.name') }}
                            </a>

                            <p style="margin:20px 0 0;font-size:13px;color:#6c757d;line-height:1.5;">
                                If the button doesn't work, open this link:<br>
                                <a href="{{ $loginUrl }}" style="color:#3b5ba9;">{{ $loginUrl }}</a>
                            </p>

                            <p style="margin:18px 0 0;font-size:13px;color:#adb5bd;">
                                Didn't expect this email? You can safely ignore it.
                            </p>
                        </td>
                    </tr>
                </table>
                <p style="margin:16px 0 0;font-size:12px;color:#adb5bd;">&copy; {{ date('Y') }} {{ config('app.name') }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
