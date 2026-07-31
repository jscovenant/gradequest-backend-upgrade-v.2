<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>School Admin Login</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:24px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="background:#0f172a;color:#ffffff;padding:24px;">
                            <h1 style="margin:0;font-size:22px;">Welcome to GradeQuest</h1>
                            <p style="margin:8px 0 0;color:#cbd5e1;">Your school admin account has been created for {{ $school->school_name }}.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <p style="margin-top:0;">Hello {{ $admin->firstname ?? 'School Admin' }},</p>
                            <p>You can now log in to manage your school portal using the details below.</p>

                            <table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;border-collapse:collapse;">
                                <tr>
                                    <td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:bold;">School</td>
                                    <td style="padding:10px;border:1px solid #e5e7eb;">{{ $school->school_name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:bold;">Login URL</td>
                                    <td style="padding:10px;border:1px solid #e5e7eb;"><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></td>
                                </tr>
                                <tr>
                                    <td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:bold;">Email</td>
                                    <td style="padding:10px;border:1px solid #e5e7eb;">{{ $admin->email }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:bold;">School Code</td>
                                    <td style="padding:10px;border:1px solid #e5e7eb;">{{ $admin->reg_no }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:bold;">Temporary Password</td>
                                    <td style="padding:10px;border:1px solid #e5e7eb;">{{ $password }}</td>
                                </tr>
                            </table>

                            <div style="margin:20px 0;padding:14px;border:1px solid #fde68a;background:#fffbeb;border-radius:12px;color:#78350f;">
                                <strong>Welcome wallet credit:</strong> Complete onboarding to claim your &#8358;5,000 GradeQuestPlus wallet credit.
                                The credit is added only after activation and expires 30 days after it is claimed if it is not used.
                            </div>

                            <p style="margin-bottom:20px;">For security, you will be required to create a new password immediately after your first login.</p>
                            <p>
                                <a href="{{ $loginUrl }}" style="display:inline-block;background:#facc15;color:#111827;text-decoration:none;font-weight:bold;padding:12px 18px;border-radius:10px;">Open School Portal</a>
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
