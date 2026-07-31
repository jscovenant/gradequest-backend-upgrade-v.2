<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sales Representative Login</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:24px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="background:#0f172a;color:#ffffff;padding:24px;">
                            <h1 style="margin:0;font-size:22px;">Welcome to GradeQuest Sales</h1>
                            <p style="margin:8px 0 0;color:#cbd5e1;">Your sales representative account has been created.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin-top:0;">Hello {{ $user?->firstname ?? 'Sales Representative' }},</p>
                            <p>You can now log in to your GradeQuest sales workspace using the details below.</p>

                            <table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;border-collapse:collapse;">
                                <tr>
                                    <td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:bold;">Login URL</td>
                                    <td style="padding:10px;border:1px solid #e5e7eb;"><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></td>
                                </tr>
                                <tr>
                                    <td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:bold;">Email</td>
                                    <td style="padding:10px;border:1px solid #e5e7eb;">{{ $user?->email }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:bold;">Sales Code</td>
                                    <td style="padding:10px;border:1px solid #e5e7eb;">{{ $representative->code }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:bold;">Temporary Password</td>
                                    <td style="padding:10px;border:1px solid #e5e7eb;">{{ $password }}</td>
                                </tr>
                            </table>

                            <p style="margin-bottom:20px;">For security, you will be required to create a new password immediately after your first login.</p>
                            <p>
                                <a href="{{ $loginUrl }}" style="display:inline-block;background:#facc15;color:#111827;text-decoration:none;font-weight:bold;padding:12px 18px;border-radius:10px;">Open Sales Workspace</a>
                            </p>
                            <p style="font-size:13px;color:#64748b;margin-bottom:0;">If you did not expect this account, please contact the GradeQuest administrator.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
