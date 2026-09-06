<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Receipt</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #1e293b;
            background-color: #f8fafc;
            margin: 0;
            padding: 24px 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .header {
            background-color: #050008;
            color: #ffffff;
            padding: 24px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .header p {
            margin: 6px 0 0 0;
            font-size: 13px;
            color: #cbd5e1;
        }
        .content {
            padding: 24px;
        }
        .status-badge {
            display: inline-block;
            background-color: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 16px;
        }
        .amount-card {
            background-color: #f1f5f9;
            border-radius: 6px;
            padding: 16px;
            text-align: center;
            margin-bottom: 20px;
        }
        .amount-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
        }
        .amount-value {
            font-size: 26px;
            font-weight: 800;
            color: #050008;
            margin-top: 4px;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .details-table th, .details-table td {
            padding: 10px 12px;
            text-align: left;
            font-size: 13px;
        }
        .details-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .details-table th {
            color: #64748b;
            font-weight: 600;
            border-bottom: 1px solid #e2e8f0;
        }
        .footer {
            background-color: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 16px 24px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $data['school']->school_name ?? 'GradeQuest Portal' }}</h1>
            <p>Official School Fee Payment Receipt</p>
        </div>
        <div class="content">
            <div style="text-align: center;">
                <span class="status-badge">&#10003; Payment Successful</span>
            </div>

            <div class="amount-card">
                <div class="amount-label">Amount Paid</div>
                <div class="amount-value">&#8358;{{ number_format((float) $data['amount'], 2) }}</div>
            </div>

            <p style="font-size: 14px; line-height: 1.6;">
                @if($isSchoolAdmin)
                    Hello Administrator, a fee payment has been successfully completed for <strong>{{ $data['student']->firstname ?? '' }} {{ $data['student']->surname ?? '' }}</strong>.
                @else
                    Hello <strong>{{ $data['payer_name'] ?: ($data['student']->firstname ?? 'Valued Parent') }}</strong>, thank you for your payment. Below are your official receipt details:
                @endif
            </p>

            <table class="details-table">
                <tr>
                    <td><strong>Receipt Number:</strong></td>
                    <td>{{ $data['receipt_no'] ?? $data['reference'] }}</td>
                </tr>
                <tr>
                    <td><strong>Reference:</strong></td>
                    <td><code>{{ $data['reference'] }}</code></td>
                </tr>
                <tr>
                    <td><strong>Student Name:</strong></td>
                    <td>{{ $data['student']->firstname ?? '' }} {{ $data['student']->surname ?? '' }}</td>
                </tr>
                <tr>
                    <td><strong>Admission / Reg No:</strong></td>
                    <td>{{ $data['student']->reg_no ?? 'N/A' }}</td>
                </tr>
                @if(!empty($data['student']->level->name))
                <tr>
                    <td><strong>Class:</strong></td>
                    <td>{{ $data['student']->level->name }}</td>
                </tr>
                @endif
                <tr>
                    <td><strong>Payment Date:</strong></td>
                    <td>{{ \Carbon\Carbon::parse($data['paid_at'])->format('d M Y, h:i A') }}</td>
                </tr>
                <tr>
                    <td><strong>Remaining Fee Balance:</strong></td>
                    <td><strong style="color: {{ (float) $data['remaining_balance'] > 0 ? '#b91c1c' : '#047857' }}">&#8358;{{ number_format((float) $data['remaining_balance'], 2) }}</strong></td>
                </tr>
            </table>

            @if(!empty($data['items']))
            <h3 style="font-size: 14px; margin-top: 20px; margin-bottom: 8px; color: #050008;">Fee Breakdown:</h3>
            <table class="details-table" style="border: 1px solid #e2e8f0;">
                <thead>
                    <tr style="background-color: #f1f5f9;">
                        <th>Item</th>
                        <th>Session / Term</th>
                        <th style="text-align: right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['items'] as $item)
                    <tr>
                        <td>{{ $item['name'] }}</td>
                        <td>{{ $item['session'] }} {{ $item['term'] ? '(' . $item['term'] . ')' : '' }}</td>
                        <td style="text-align: right;">&#8358;{{ number_format((float) $item['amount'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif

            <p style="font-size: 12px; color: #64748b; margin-top: 20px;">
                An official PDF copy of this receipt is attached to this email for your records.
            </p>
        </div>
        <div class="footer">
            <p style="margin: 0;">Powered by GradiosEdu School Management System &bull; <a href="https://gradequest.com.ng" style="color: #050008; text-decoration: none;">gradequest.com.ng</a></p>
        </div>
    </div>
</body>
</html>
