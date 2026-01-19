<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Code</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .email-header {
            background-color: #0d6efd;
            color: #ffffff;
            text-align: center;
            padding: 20px;
        }
        .email-header img {
            max-width: 150px;
            margin-bottom: 10px;
        }
        .email-header h1 {
            margin: 0;
            font-size: 24px;
        }
        .email-body {
            padding: 20px;
            color: #333333;
        }
        .email-body p {
            font-size: 16px;
            line-height: 1.6;
            margin: 10px 0;
        }
        .reset-code {
            font-size: 20px;
            font-weight: bold;
            color: #0d6efd;
            text-align: center;
            margin: 20px 0;
        }
        .email-footer {
            text-align: center;
            font-size: 14px;
            color: #888888;
            padding: 10px 20px;
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
            <img src="{{ asset('frontend/logo/gradequest_log.png') }}" alt="Your Logo">
            <h1>Password Reset</h1>
        </div>
        <div class="email-body">
            <p>Dear {{ $user->name }},</p>
            <p>You requested a password reset. Use the following code to reset your password:</p>
            <div class="reset-code">{{ $code }}</div>
            <p>This code will expire in 15 minutes. If you did not request a password reset, please ignore this email or contact support if you suspect unauthorized activity.</p>
            <p>Thank you,</p>
            <p><strong>{{ config('app.name') }}</strong></p>
        </div>
        <div class="email-footer">
            &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved. <br>
            <a href="#">Privacy Policy</a> | <a href="#">Contact Support</a>
        </div>
    </div>
</body>
</html>
