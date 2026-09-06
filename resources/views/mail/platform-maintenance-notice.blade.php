<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduled Platform Maintenance Notice</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }
        .wrapper {
            max-width: 620px;
            margin: 30px auto;
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        }
        .header {
            background: linear-gradient(135deg, #1d151f 0%, #3b1836 50%, #d300b0 100%);
            padding: 32px 28px;
            color: #ffffff;
            text-align: center;
        }
        .header h1 {
            margin: 0 0 8px;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .header p {
            margin: 0;
            color: #f7c948;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        .content {
            padding: 32px 28px;
        }
        .greeting {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
            color: #0f172a;
        }
        .alert-box {
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 16px;
            border-radius: 8px;
            margin: 20px 0;
            font-size: 14px;
            color: #92400e;
        }
        .details-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #cbd5e1;
            font-size: 13.5px;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 700;
            color: #64748b;
        }
        .detail-val {
            font-weight: 800;
            color: #0f172a;
            text-align: right;
        }
        .guidelines {
            margin: 24px 0;
        }
        .guidelines h3 {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 10px;
        }
        .guideline-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 10px;
            font-size: 13.5px;
            color: #334155;
        }
        .guideline-icon {
            margin-right: 10px;
            font-size: 16px;
        }
        .footer {
            background: #f1f5f9;
            padding: 22px 28px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <p>GradiosEdu Platform Operations</p>
            <h1>Scheduled System Maintenance Notice</h1>
        </div>

        <div class="content">
            <div class="greeting">
                Dear {{ $admin->name ?? 'School Administrator' }},
            </div>

            <p style="font-size: 14px; color: #334155; margin-bottom: 16px;">
                We are writing to notify you that GradiosEdu will be undergoing scheduled infrastructure and database maintenance to optimize performance and improve reliability across all school portals.
            </p>

            <div class="alert-box">
                <strong>Notice:</strong> {{ $customMessage }}
            </div>

            <div class="details-card">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="border-bottom: 1px dashed #cbd5e1;">
                        <td style="padding: 8px 0; font-size: 13.5px; color: #64748b; font-weight: 700;">Status:</td>
                        <td style="padding: 8px 0; font-size: 13.5px; color: #0f172a; font-weight: 800; text-align: right;">
                            {{ $isImmediate ? 'Underway / Immediate' : 'Scheduled Ahead' }}
                        </td>
                    </tr>
                    @if($startTime)
                    <tr style="border-bottom: 1px dashed #cbd5e1;">
                        <td style="padding: 8px 0; font-size: 13.5px; color: #64748b; font-weight: 700;">Start Time:</td>
                        <td style="padding: 8px 0; font-size: 13.5px; color: #0f172a; font-weight: 800; text-align: right;">
                            {{ \Carbon\Carbon::parse($startTime)->format('d M Y, h:i A') }} (WAT)
                        </td>
                    </tr>
                    @endif
                    @if($endTime)
                    <tr>
                        <td style="padding: 8px 0; font-size: 13.5px; color: #64748b; font-weight: 700;">Estimated Completion:</td>
                        <td style="padding: 8px 0; font-size: 13.5px; color: #0f172a; font-weight: 800; text-align: right;">
                            {{ \Carbon\Carbon::parse($endTime)->format('d M Y, h:i A') }} (WAT)
                        </td>
                    </tr>
                    @endif
                </table>
            </div>

            <div class="guidelines">
                <h3>What this means for your school:</h3>
                <div class="guideline-item">
                    <span class="guideline-icon">&#10004;</span>
                    <div><strong>Read-Only Portal Access:</strong> You and your staff can still log in and view student lists, schedules, and past records without interruption.</div>
                </div>
                <div class="guideline-item">
                    <span class="guideline-icon">&#9208;</span>
                    <div><strong>Temporary Write Pause:</strong> To ensure zero data corruption, new result score uploads, CBT exam sessions, and online fee payments are temporarily paused until maintenance concludes.</div>
                </div>
                <div class="guideline-item">
                    <span class="guideline-icon">&#128274;</span>
                    <div><strong>Data Security:</strong> All your school records, student grades, and financial ledger data remain 100% safe and encrypted.</div>
                </div>
            </div>

            <p style="font-size: 13.5px; color: #475569; margin-top: 24px;">
                We apologize for any temporary inconvenience and appreciate your partnership as we upgrade our system. If you have any urgent questions, please reach out to our platform support desk.
            </p>
        </div>

        <div class="footer">
            &copy; {{ date('Y') }} GradiosEdu Smart School Management Platform. All rights reserved.
        </div>
    </div>
</body>
</html>
