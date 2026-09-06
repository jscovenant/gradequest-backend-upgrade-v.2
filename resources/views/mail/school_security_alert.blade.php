<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GradiosEdu Security Alert</title>
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
            background: linear-gradient(135deg, #1c0316 0%, #380528 100%);
            padding: 28px 24px;
            text-align: center;
            border-bottom: 3px solid #ff0055;
        }
        .header h1 {
            color: #ffffff;
            font-size: 20px;
            margin: 0;
            font-weight: 800;
        }
        .header p {
            color: #ffc857;
            font-size: 12px;
            margin: 6px 0 0;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .body {
            padding: 30px 26px;
            color: #333333;
            line-height: 1.6;
        }
        .alert-banner {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            border-left: 4px solid #e11d48;
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 20px;
            font-weight: 700;
            color: #9f1239;
            font-size: 15px;
        }
        .detail-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: #fcfafc;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #eee;
        }
        .detail-table td {
            padding: 10px 14px;
            font-size: 13px;
            border-bottom: 1px solid #eee;
        }
        .detail-table td.label {
            font-weight: 700;
            color: #4a3f4f;
            width: 38%;
            background: #f8f6f9;
        }
        .detail-table td.value {
            color: #1a1a2e;
            font-weight: 600;
        }
        .footer {
            background-color: #f9f9fb;
            padding: 18px;
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
            <h1>GradiosEdu Security Guard</h1>
            <p>Immediate Security Notification</p>
        </div>
        <div class="body">
            <p>Dear <strong>{{ $user->firstname ?: 'School Proprietor' }}</strong>,</p>
            <div class="alert-banner">
                {{ $eventTitle }}
            </div>
            <p style="font-size: 13.5px; color: #444;">
                The following security-critical event was just completed on the portal for <strong>{{ $schoolName }}</strong>:
            </p>

            <table class="detail-table">
                @foreach($eventDetails as $key => $val)
                <tr>
                    <td class="label">{{ ucwords(str_replace('_', ' ', $key)) }}</td>
                    <td class="value">{{ $val }}</td>
                </tr>
                @endforeach
                <tr>
                    <td class="label">Date & Time</td>
                    <td class="value">{{ now()->toDayDateTimeString() }} (WAT)</td>
                </tr>
                @if($ipAddress)
                <tr>
                    <td class="label">IP Address</td>
                    <td class="value">{{ $ipAddress }}</td>
                </tr>
                @endif
            </table>

            <p style="font-size: 13px; color: #555555; background: #fffbe6; padding: 12px; border-radius: 8px; border: 1px solid #ffe58f;">
                <strong>Did you authorize this change?</strong><br>
                If YES, no further action is required.<br>
                If NO, someone with your school portal access has performed this action. Please log in immediately and click <strong>"Revoke All Staff Sessions"</strong> in your Settings to lock out all unauthorized operators.
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} GradiosEdu School Management System.</p>
        </div>
    </div>
</body>
</html>
