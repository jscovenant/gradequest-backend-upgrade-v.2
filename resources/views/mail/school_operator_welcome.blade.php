<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Operator Access</title>
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
        .credentials-box {
            margin: 24px 0;
            padding: 20px;
            background: #fdf2fb;
            border: 2px dashed #d300b0;
            border-radius: 12px;
        }
        .cred-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f3d4ef;
            font-size: 14px;
        }
        .cred-row:last-child {
            border-bottom: none;
        }
        .cred-label {
            font-weight: 700;
            color: #7b1fa2;
        }
        .cred-val {
            font-weight: 600;
            color: #0b0710;
            font-family: 'Courier New', Courier, monospace;
        }
        .btn-login {
            display: inline-block;
            background: #c9a84c;
            color: #0f172a;
            text-decoration: none;
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            margin-top: 16px;
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
            <h1>SchoolProfit Portal Access</h1>
            <p>{{ $schoolName }}</p>
        </div>
        <div class="body">
            <p>Dear <strong>{{ $operator->firstname }} {{ $operator->surname }}</strong>,</p>
            <p>
                You have been registered as a <strong>{{ $title }}</strong> for <strong>{{ $schoolName }}</strong> on SchoolProfit.
            </p>
            <p>
                You can now log in to manage student registrations, upload examination results, schedule CBT exams, and coordinate daily portal operations.
            </p>

            <div class="credentials-box">
                <div class="cred-row">
                    <span class="cred-label">Login Email:</span>
                    <span class="cred-val">{{ $operator->email }}</span>
                </div>
                <div class="cred-row">
                    <span class="cred-label">Temporary Password:</span>
                    <span class="cred-val">{{ $password }}</span>
                </div>
                <div class="cred-row">
                    <span class="cred-label">Assigned Role:</span>
                    <span class="cred-val">{{ $title }}</span>
                </div>
            </div>

            <div style="text-align: center;">
                <a href="http://18.133.82.13/login" class="btn-login">Log In to School Portal</a>
            </div>

            <p style="font-size: 13px; color: #666; margin-top: 24px;">
                Please change your password immediately upon your first login for account security.
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} SchoolProfit School Management System.</p>
        </div>
    </div>
</body>
</html>
