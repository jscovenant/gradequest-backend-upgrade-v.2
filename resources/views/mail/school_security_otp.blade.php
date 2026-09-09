<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Security Verification Code</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #0b0710;
            color: #222222;
            margin: 0;
            padding: 24px;
        }
        .email-card {
            max-width: 580px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .header {
            background: linear-gradient(135deg, #0d0614 0%, #1e092b 100%);
            padding: 32px 24px;
            text-align: center;
            border-bottom: 3px solid #d300b0;
        }
        .header h1 {
            color: #ffffff;
            font-size: 22px;
            margin: 0;
            font-weight: 800;
            letter-spacing: 0.5px;
        }
        .header p {
            color: #ffc857;
            font-size: 13px;
            margin: 8px 0 0;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .body {
            padding: 32px 28px;
            color: #333333;
            line-height: 1.6;
        }
        .greeting {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 12px;
        }
        .otp-container {
            margin: 28px 0;
            padding: 20px;
            background: #fdf2fb;
            border: 2px dashed #d300b0;
            border-radius: 12px;
            text-align: center;
        }
        .otp-title {
            font-size: 12px;
            text-transform: uppercase;
            color: #7b1fa2;
            font-weight: 800;
            letter-spacing: 1.5px;
            margin-bottom: 8px;
        }
        .otp-code {
            font-size: 38px;
            font-weight: 900;
            letter-spacing: 8px;
            color: #0b0710;
            font-family: 'Courier New', Courier, monospace;
            margin: 6px 0;
        }
        .otp-expiry {
            font-size: 12px;
            color: #c2185b;
            font-weight: 700;
            margin-top: 6px;
        }
        .alert-box {
            background-color: #fff9e6;
            border-left: 4px solid #f59e0b;
            padding: 14px 16px;
            border-radius: 8px;
            font-size: 13px;
            color: #78350f;
            margin: 20px 0;
        }
        .footer {
            background-color: #f9f9fb;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #777777;
            border-top: 1px solid #eeeeee;
        }
    </style>
</head>
<body>
    <div class="email-card">
        <div class="header">
            <h1>SchoolProfit Security Guard</h1>
            <p>Proprietor Verification Required</p>
        </div>
        <div class="body">
            <p class="greeting">Dear {{ $user->firstname ?: 'School Proprietor' }},</p>
            <p>
                A high-security action has been initiated on your school portal for <strong>{{ $schoolName }}</strong>:
            </p>
            <p style="background: #f4f4f7; padding: 10px 14px; border-radius: 8px; font-weight: 600; color: #1e092b;">
                {{ $actionDescription }}
            </p>

            <div class="otp-container">
                <div class="otp-title">Your 6-Digit Verification Code</div>
                <div class="otp-code">{{ $otp }}</div>
                <div class="otp-expiry">Expires in 10 minutes</div>
            </div>

            <div class="alert-box">
                <strong>Important Security Notice:</strong><br>
                Never share this code with anyone, including staff members or third-party computer assistants, unless you specifically authorized them to make this change on your behalf.
            </div>

            <p style="font-size: 13px; color: #666666;">
                If you did not initiate or authorize this action, someone with access to your school portal may be attempting unauthorized changes. Please log in immediately and revoke all active sessions.
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} SchoolProfit School Management System. All rights reserved.</p>
            <p>This is an automated security email sent to the registered school proprietor.</p>
        </div>
    </div>
</body>
</html>
