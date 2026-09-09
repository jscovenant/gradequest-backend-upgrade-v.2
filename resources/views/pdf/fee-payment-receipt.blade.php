<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Payment Receipt - {{ $receipt_no ?? $reference }}</title>
<style>
  @page {
    margin: 15mm 15mm;
    size: a4 portrait;
  }
  body {
    font-family: 'DejaVu Sans', sans-serif;
    color: #1e293b;
    font-size: 11px;
    line-height: 1.5;
    margin: 0;
    padding: 0;
  }
  .receipt-container {
    width: 100%;
    max-width: 720px;
    margin: 0 auto;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 24px;
    background: #ffffff;
  }
  .header-table {
    width: 100%;
    border-collapse: collapse;
    border-bottom: 2px solid #050008;
    padding-bottom: 14px;
    margin-bottom: 18px;
  }
  .school-logo {
    max-width: 80px;
    max-height: 80px;
    object-fit: contain;
  }
  .school-name {
    font-size: 18px;
    font-weight: bold;
    color: #050008;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .school-meta {
    font-size: 10px;
    color: #64748b;
    margin-top: 3px;
  }
  .receipt-badge-wrap {
    text-align: right;
    vertical-align: top;
  }
  .receipt-title {
    font-size: 14px;
    font-weight: 900;
    color: #050008;
    letter-spacing: 1px;
    text-transform: uppercase;
  }
  .status-pill {
    display: inline-block;
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
    border-radius: 20px;
    padding: 3px 12px;
    font-weight: bold;
    font-size: 10px;
    margin-top: 4px;
    text-transform: uppercase;
  }
  .info-grid {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 18px;
  }
  .info-box {
    width: 50%;
    vertical-align: top;
    padding: 8px 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
  }
  .info-title {
    font-size: 10px;
    font-weight: bold;
    color: #475569;
    text-transform: uppercase;
    border-bottom: 1px solid #cbd5e1;
    padding-bottom: 4px;
    margin-bottom: 6px;
  }
  .info-row {
    font-size: 10.5px;
    margin-bottom: 4px;
  }
  .info-row strong {
    color: #334155;
    display: inline-block;
    width: 110px;
  }
  .items-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    margin-bottom: 18px;
  }
  .items-table th {
    background: #050008;
    color: #ffffff;
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
    padding: 8px 10px;
    text-align: left;
  }
  .items-table td {
    padding: 8px 10px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 10.5px;
  }
  .items-table tr:nth-child(even) td {
    background: #faf8f5;
  }
  .text-right {
    text-align: right;
  }
  .text-center {
    text-align: center;
  }
  .totals-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 6px;
    margin-bottom: 16px;
  }
  .totals-table td {
    padding: 5px 10px;
    font-size: 11px;
  }
  .grand-total {
    background: #f1f5f9;
    border-top: 2px solid #050008;
    border-bottom: 2px solid #050008;
    font-size: 13px !important;
    font-weight: bold;
    color: #050008;
  }
  .grand-total-val {
    color: #047857;
  }
  .stamp-box {
    margin-top: 16px;
    padding: 12px 16px;
    background: #f0fdf4;
    border: 1px dashed #86efac;
    border-radius: 6px;
    text-align: center;
  }
  .stamp-text {
    font-weight: bold;
    color: #166534;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .stamp-meta {
    font-size: 9.5px;
    color: #16a34a;
    margin-top: 2px;
  }
  .footer {
    margin-top: 22px;
    padding-top: 10px;
    border-top: 1px solid #e2e8f0;
    font-size: 9px;
    color: #94a3b8;
    text-align: center;
  }
</style>
</head>
<body>

<div class="receipt-container">
  <table class="header-table">
    <tr>
      @if(!empty($logoBase64))
        <td style="width: 85px; vertical-align: middle;">
          <img src="{!! $logoBase64 !!}" class="school-logo" alt="School Logo">
        </td>
      @endif
      <td style="vertical-align: middle;">
        <div class="school-name">{{ $school->school_name ?? $school->name ?? 'SchoolProfit Partner School' }}</div>
        <div class="school-meta">
          @if(!empty($school->address)) {{ $school->address }} <br> @endif
          @if(!empty($school->email)) Email: {{ $school->email }} @endif
          @if(!empty($school->phone)) | Phone: {{ $school->phone }} @endif
        </div>
      </td>
      <td class="receipt-badge-wrap">
        <div class="receipt-title">PAYMENT RECEIPT</div>
        <div><span class="status-pill">&#10003; PAID ONLINE</span></div>
        <div style="font-size: 10px; color: #64748b; margin-top: 5px;">
          Receipt No: <strong>{{ $receipt_no }}</strong>
        </div>
      </td>
    </tr>
  </table>

  <table class="info-grid">
    <tr>
      <td class="info-box" style="padding-right: 10px;">
        <div class="info-title">Student Information</div>
        <div class="info-row"><strong>Student Name:</strong> {{ $student->name ?? trim(($student->firstname ?? '').' '.($student->surname ?? '')) }}</div>
        <div class="info-row"><strong>Admission No:</strong> {{ $student->reg_no ?? 'N/A' }}</div>
        <div class="info-row"><strong>Class / Level:</strong> {{ $student->level->name ?? $student->class ?? 'N/A' }}</div>
        @if(!empty($student->section->name) || !empty($student->section))
          <div class="info-row"><strong>Section:</strong> {{ $student->section->name ?? $student->section }}</div>
        @endif
      </td>
      <td style="width: 14px;"></td>
      <td class="info-box" style="padding-left: 10px;">
        <div class="info-title">Transaction Details</div>
        <div class="info-row"><strong>Reference:</strong> {{ $reference }}</div>
        <div class="info-row"><strong>Payment Date:</strong> {{ $paid_at ? \Carbon\Carbon::parse($paid_at)->format('d M Y, h:i A') : now()->format('d M Y, h:i A') }}</div>
        <div class="info-row"><strong>Payment Method:</strong> Online Card / Bank Transfer</div>
        @if(!empty($payer_name))
          <div class="info-row"><strong>Payer Name:</strong> {{ $payer_name }}</div>
        @endif
        @if(!empty($payer_email))
          <div class="info-row"><strong>Payer Email:</strong> {{ $payer_email }}</div>
        @endif
      </td>
    </tr>
  </table>

  <table class="items-table">
    <thead>
      <tr>
        <th style="width: 40%;">Fee Description</th>
        <th style="width: 25%;">Academic Period</th>
        <th class="text-right" style="width: 35%;">Amount Paid</th>
      </tr>
    </thead>
    <tbody>
      @forelse($items as $item)
        <tr>
          <td>
            <strong>{{ $item['name'] ?? $item['fee_name'] ?? 'School Fee' }}</strong>
          </td>
          <td>
            {{ $item['session'] ?? $item['session_name'] ?? '' }}
            @if(!empty($item['term']) || !empty($item['term_name']))
              - {{ $item['term'] ?? $item['term_name'] ?? '' }}
            @endif
          </td>
          <td class="text-right">
            <strong>&#8358;{{ number_format((float) ($item['amount'] ?? $item['amount_paid'] ?? 0), 2) }}</strong>
          </td>
        </tr>
      @empty
        <tr>
          <td><strong>School Fee Payment</strong></td>
          <td>Current Term</td>
          <td class="text-right"><strong>&#8358;{{ number_format((float) $amount, 2) }}</strong></td>
        </tr>
      @endforelse
    </tbody>
  </table>

  <table class="totals-table">
    <tr class="grand-total">
      <td style="width: 65%; text-transform: uppercase;">Total Amount Paid</td>
      <td class="text-right grand-total-val" style="width: 35%;">&#8358;{{ number_format((float) $amount, 2) }}</td>
    </tr>
    @if(isset($remaining_balance) && (float) $remaining_balance > 0)
      <tr>
        <td style="color: #64748b;">Remaining Student Balance</td>
        <td class="text-right" style="font-weight: bold; color: #dc2626;">&#8358;{{ number_format((float) $remaining_balance, 2) }}</td>
      </tr>
    @elseif(isset($remaining_balance) && (float) $remaining_balance == 0)
      <tr>
        <td style="color: #047857; font-weight: bold;">Outstanding Balance</td>
        <td class="text-right" style="font-weight: bold; color: #047857;">&#8358;0.00 (Fully Settled)</td>
      </tr>
    @endif
  </table>

  <div class="stamp-box">
    <div class="stamp-text">&#10004; OFFICIAL ELECTRONIC RECEIPT &bull; PAYMENT CONFIRMED</div>
    <div class="stamp-meta">Processed securely via SchoolProfit (schoolprofit.ng) &bull; Valid without physical signature</div>
  </div>

  <div class="footer">
    Generated automatically by SchoolProfit on {{ now()->format('d M Y, h:i A') }} &bull; Transaction Ref: {{ $reference }}
  </div>
</div>

</body>
</html>
