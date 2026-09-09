<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Application Received</title>
</head>
<body style="margin:0;padding:0;background:#F8FAFC;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#F8FAFC;padding:32px 16px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border:1px solid #E2E8F0;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(15,39,68,0.06);">
                    <!-- Header -->
                    <tr>
                        <td style="background:linear-gradient(135deg, #0F2744 0%, #1E3A8A 100%);color:#FFFFFF;padding:32px 28px;text-align:center;">
                            <div style="font-size:24px;font-weight:800;letter-spacing:-0.02em;margin-bottom:6px;">
                                Grade<span style="color:#FBBF24;">Quest</span>
                            </div>
                            <h1 style="margin:0;font-size:20px;font-weight:700;color:#FFFFFF;">Partner Application Received</h1>
                            <p style="margin:8px 0 0;color:#CBD5E1;font-size:13.5px;">Your sales representative application is under administrative review.</p>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding:32px 28px;">
                            <p style="margin-top:0;font-size:15px;line-height:1.6;color:#1E293B;">
                                Hello <strong>{{ $user?->firstname ?? 'Sales Partner' }}</strong>,
                            </p>
                            <p style="font-size:14.5px;line-height:1.6;color:#475569;">
                                Thank you for applying to join the <strong>SchoolProfit Partner Network</strong>. We have received your application and regional credentials.
                            </p>

                            <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;padding:18px 20px;margin:24px 0;">
                                <div style="font-size:12px;font-weight:700;color:#B45309;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:10px;">
                                    Application Summary
                                </div>
                                <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13.5px;color:#334155;line-height:1.8;">
                                    <tr>
                                        <td style="width:40%;color:#64748B;">Applicant Name:</td>
                                        <td><strong>{{ $user?->firstname }} {{ $user?->surname }}</strong></td>
                                    </tr>
                                    <tr>
                                        <td style="color:#64748B;">Registered Email:</td>
                                        <td><strong>{{ $user?->email }}</strong></td>
                                    </tr>
                                    <tr>
                                        <td style="color:#64748B;">Assigned Code:</td>
                                        <td><span style="display:inline-block;background:#0F2744;color:#FBBF24;padding:2px 8px;border-radius:6px;font-weight:700;font-size:12px;">{{ $representative->code }}</span></td>
                                    </tr>
                                    <tr>
                                        <td style="color:#64748B;">Assigned Region:</td>
                                        <td><strong>{{ $representative->region ?? 'National' }}</strong></td>
                                    </tr>
                                    <tr>
                                        <td style="color:#64748B;">Verification Status:</td>
                                        <td><span style="color:#D97706;font-weight:700;">● Pending Administrative Verification</span></td>
                                    </tr>
                                </table>
                            </div>

                            <p style="font-size:14px;line-height:1.6;color:#475569;">
                                <strong>What happens next?</strong><br>
                                Our partner onboarding team reviews all applications to ensure regional coverage and verify representative credentials. You will receive an email notification as soon as your account is approved.
                            </p>

                            <div style="border-top:1px solid #E2E8F0;padding-top:20px;margin-top:28px;font-size:12.5px;color:#64748B;line-height:1.6;">
                                Need immediate assistance or have questions about partnership commissions? Reach out to our partner support team on WhatsApp at <a href="https://schoolprofit.ng" style="color:#1D4ED8;text-decoration:none;font-weight:600;">SchoolProfit Support</a>.
                            </div>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background:#F8FAFC;border-top:1px solid #E2E8F0;padding:16px 28px;text-align:center;font-size:12px;color:#94A3B8;">
                            © {{ date('Y') }} SchoolProfit Platform. All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
