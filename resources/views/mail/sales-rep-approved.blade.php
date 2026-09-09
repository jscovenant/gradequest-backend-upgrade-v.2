<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Account Approved</title>
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
                            <h1 style="margin:0;font-size:22px;font-weight:700;color:#FFFFFF;">🎉 Application Approved!</h1>
                            <p style="margin:8px 0 0;color:#CBD5E1;font-size:14px;">Welcome to the SchoolProfit Sales Representative Network.</p>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding:32px 28px;">
                            <p style="margin-top:0;font-size:15px;line-height:1.6;color:#1E293B;">
                                Hello <strong>{{ $user?->firstname ?? 'Sales Representative' }}</strong>,
                            </p>
                            <p style="font-size:14.5px;line-height:1.6;color:#475569;">
                                We are thrilled to inform you that your <strong>SchoolProfit Sales Representative Account</strong> has been fully verified and approved by the platform administration!
                            </p>

                            <!-- Partner Details Box -->
                            <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:12px;padding:20px 22px;margin:24px 0;">
                                <div style="font-size:12px;font-weight:700;color:#047857;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:12px;">
                                    Your Sales Partner Credentials
                                </div>
                                <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13.5px;color:#1E293B;line-height:1.8;">
                                    <tr>
                                        <td style="width:40%;color:#065F46;">Unique Sales Code:</td>
                                        <td><strong style="color:#0F2744;font-size:15px;">{{ $representative->code }}</strong></td>
                                    </tr>
                                    <tr>
                                        <td style="color:#065F46;">First-Term Commission:</td>
                                        <td><strong style="color:#047857;">{{ $representative->term_1_commission_rate ?? 30 }}%</strong></td>
                                    </tr>
                                    <tr>
                                        <td style="color:#065F46;">Termly Retention:</td>
                                        <td><strong style="color:#047857;">{{ $representative->retention_commission_rate ?? 12 }}%</strong></td>
                                    </tr>
                                    <tr>
                                        <td style="color:#065F46;">Login Email:</td>
                                        <td><strong>{{ $user?->email }}</strong></td>
                                    </tr>
                                </table>
                            </div>

                            <p style="font-size:14px;line-height:1.6;color:#475569;">
                                You can now log in using your registered password to access your dedicated <strong>Sales Workspace</strong>, track prospective school leads, generate branded referral landing pages, and monitor earned commissions in real time.
                            </p>

                            <div style="text-align:center;margin:32px 0 24px;">
                                <a href="{{ $loginUrl }}" style="display:inline-block;background:linear-gradient(135deg, #0F2744 0%, #1E3A8A 100%);color:#FFFFFF;text-decoration:none;font-weight:700;font-size:14.5px;padding:14px 32px;border-radius:10px;box-shadow:0 4px 14px rgba(15,39,68,0.2);">
                                    Sign In to Sales Workspace →
                                </a>
                            </div>

                            <div style="border-top:1px solid #E2E8F0;padding-top:20px;margin-top:28px;font-size:12.5px;color:#64748B;line-height:1.6;">
                                Need help getting started? Check your marketing kit in the sales workspace or contact our partner growth team.
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
