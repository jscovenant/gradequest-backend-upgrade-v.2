<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outstanding School Fees Summary</title>
</head>
<body style="margin:0; padding:0; background:#f3f6fb; font-family:Arial, Helvetica, sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f6fb; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:700px; background:#ffffff; border-radius:14px; overflow:hidden;">

                    <tr>
                        <td style="background:#0d47a1; padding:28px; text-align:center;">
                            @if(!empty($logoUrl))
                                <img src="{{ $logoUrl }}" alt="{{ $schoolName }} Logo" style="display:block; margin:0 auto 12px auto; max-width:100px; max-height:80px; background:#fff; padding:8px; border-radius:10px;">
                            @endif
                            <div style="font-size:26px; font-weight:700; color:#ffffff;">{{ $schoolName }}</div>
                            <div style="font-size:14px; color:#dbeafe; margin-top:6px;">Outstanding School Fees Summary</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px;">
                            <div style="font-size:16px; line-height:1.8; color:#374151;">
                                Dear <strong>{{ $name }}</strong>,
                            </div>

                            <div style="font-size:15px; line-height:1.8; color:#475467; margin-top:12px;">
                                This is a respectful reminder of the outstanding fees for your child or children. Kindly find the detailed breakdown below.
                            </div>

                            @foreach($children as $child)
                                <div style="margin-top:24px; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
                                    <div style="background:#f8fafc; padding:14px 16px; font-size:16px; font-weight:700; color:#111827;">
                                        {{ $child['student_name'] ?? 'Student' }}
                                    </div>

                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                                        <tr>
                                            <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; background:#fcfcfd; font-size:13px; font-weight:700; color:#667085;">Fee Type</td>
                                            <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; background:#fcfcfd; font-size:13px; font-weight:700; color:#667085;">Amount</td>
                                            <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; background:#fcfcfd; font-size:13px; font-weight:700; color:#667085;">Paid</td>
                                            <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; background:#fcfcfd; font-size:13px; font-weight:700; color:#667085;">Balance</td>
                                        </tr>

                                        @foreach(($child['items'] ?? []) as $item)
                                            <tr>
                                                <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; color:#111827;">
                                                    {{ $item['fee_title'] ?? 'Fee' }}
                                                </td>
                                                <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; color:#111827;">
                                                    ₦{{ number_format((float) ($item['amount'] ?? 0), 2) }}
                                                </td>
                                                <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; color:#111827;">
                                                    ₦{{ number_format((float) ($item['paid'] ?? 0), 2) }}
                                                </td>
                                                <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; color:#b42318; font-weight:700;">
                                                    ₦{{ number_format((float) ($item['balance'] ?? 0), 2) }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </table>
                                </div>
                            @endforeach

                            <div style="margin-top:28px; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
                                <div style="background:#f9fafb; padding:16px; font-size:17px; font-weight:700; color:#111827;">
                                    Summary Totals
                                </div>

                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                                    <tr>
                                        <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; color:#475467;">Total Amount</td>
                                        <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; color:#111827; font-weight:700;">{{ $totalAmount }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; color:#475467;">Total Paid</td>
                                        <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; color:#111827; font-weight:700;">{{ $totalPaid }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:14px; color:#475467;">Outstanding Balance</td>
                                        <td style="padding:12px 16px; border-bottom:1px solid #e5e7eb; font-size:16px; color:#b42318; font-weight:700;">{{ $totalBalance }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:12px 16px; font-size:14px; color:#475467;">Due Date</td>
                                        <td style="padding:12px 16px; font-size:14px; color:#111827; font-weight:700;">{{ $dueDate }}</td>
                                    </tr>
                                </table>
                            </div>

                            <!-- <div style="margin-top:26px; text-align:center;">
                                <a href="{{ $paymentUrl }}" style="display:inline-block; background:#0d47a1; color:#ffffff; text-decoration:none; padding:14px 28px; border-radius:999px; font-size:15px; font-weight:700;">
                                    Pay Now
                                </a>
                            </div> -->

                            <div style="margin-top:26px; padding:16px; background:#fff7ed; border:1px solid #fed7aa; border-left:4px solid #f97316; border-radius:10px; font-size:14px; line-height:1.8; color:#9a3412;">
                                If payment has already been made, please disregard this message or contact the school for reconciliation.
                            </div>

                            <div style="margin-top:28px; padding:18px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px;">
                                <div style="font-size:16px; font-weight:700; color:#111827; margin-bottom:10px;">Contact the School</div>

                                @if(!empty($schoolPhone))
                                    <div style="font-size:14px; line-height:1.8; color:#475467;"><strong>Phone:</strong> {{ $schoolPhone }}</div>
                                @endif

                                @if(!empty($schoolEmail))
                                    <div style="font-size:14px; line-height:1.8; color:#475467;"><strong>Email:</strong> {{ $schoolEmail }}</div>
                                @endif

                                @if(!empty($schoolAddress))
                                    <div style="font-size:14px; line-height:1.8; color:#475467;"><strong>Address:</strong> {{ $schoolAddress }}</div>
                                @endif

                                @if(!empty($schoolWebsite))
                                    <div style="font-size:14px; line-height:1.8; color:#475467;"><strong>Portal:</strong> {{ $schoolWebsite }}</div>
                                @endif
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:18px; text-align:center; background:#111827; color:#d0d5dd; font-size:12px; line-height:1.8;">
                            This is an automated reminder from {{ $schoolName }}.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>