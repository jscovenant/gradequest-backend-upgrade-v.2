<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Payment Confirmation</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background-color: #f7f9fb;
      margin: 0;
      padding: 0;
    }
    .email-container {
      max-width: 600px;
      margin: 30px auto;
      background-color: #ffffff;
      border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
      overflow: hidden;
    }
    .email-header {
      background-color: #0d6efd;
      color: white;
      text-align: center;
      padding: 25px 20px;
    }
    .email-header img {
      max-width: 120px;
      margin-bottom: 10px;
    }
    .email-header h1 {
      margin: 0;
      font-size: 22px;
    }
    .email-body {
      padding: 25px;
      color: #333;
    }
    .email-body p {
      font-size: 16px;
      line-height: 1.6;
      margin: 15px 0;
    }
    .email-body ul {
      list-style: none;
      padding: 0;
      margin: 20px 0;
    }
    .email-body li {
      font-size: 16px;
      margin-bottom: 10px;
    }
    .email-body li strong {
      color: #0d6efd;
    }
    .email-footer {
      text-align: center;
      font-size: 14px;
      color: #aaa;
      padding: 15px 20px;
      border-top: 1px solid #eaeaea;
    }
    .email-footer a {
      color: #0d6efd;
      text-decoration: none;
    }
  </style>
</head>
<body>
  <div class="email-container">
    <div class="email-header">
      <img src="{{ asset('frontend/logo/gradequest_log.png') }}" alt="GradeQuest Logo">
      <h1>Payment Confirmation</h1>
    </div>

    <div class="email-body">
      <p>Hello <strong>{{ $user->name ?? ($user->firstname . ' ' . $user->surname ?? 'User') }}</strong>,</p>
      <p>We’ve received your subscription payment successfully.</p>

      <ul>
        <li><strong>Amount:</strong> ₦{{ number_format($payment->amount ?? 0) }}</li>
        <li><strong>Plan:</strong> {{ $payment->plan->name ?? 'N/A' }}</li>
        <li><strong>Reference:</strong> {{ $payment->reference ?? 'N/A' }}</li>
        <li><strong>Paid At:</strong> 
          {{ $payment->starts_at ? \Carbon\Carbon::parse($payment->starts_at)->format('d M, Y h:i A') : 'N/A' }}
        </li>
      </ul>

      @if(isset($subscription) && $subscription->end_date)
        <p>Your subscription is now active until 
          <strong>{{ \Carbon\Carbon::parse($subscription->end_date)->toFormattedDateString() }}</strong>.
        </p>
      @else
        <p>Your subscription is now active!</p>
      @endif

      <p>Thank you for your continued trust in us!</p>
      <p>— The Support Team</p>
    </div>

    <div class="email-footer">
      <p>&copy; {{ date('Y') }} <a href="{{ url('/') }}">GradeQuest</a>. All rights reserved.</p>
    </div>
  </div>
</body>
</html>
