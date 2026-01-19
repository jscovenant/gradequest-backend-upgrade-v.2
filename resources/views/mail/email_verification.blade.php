<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification</title>
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
        .email-body {
            padding: 20px;
            color: #333333;
        }
        .verification-code {
            background-color: #f9f9f9;
            border: 1px dashed #0d6efd;
            color: #0d6efd;
            font-weight: bold;
            font-size: 24px;
            text-align: center;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            letter-spacing: 4px;
        }
        .email-footer {
            text-align: center;
            padding: 10px;
            font-size: 12px;
            color: #888888;
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
            <div>Email Verification</div>
        </div>
        <div class="email-body">
            <p>Hi {{ $user->name ?? 'User' }},</p>
            <p>Thank you for registering with <strong>GradeQuest</strong>!</p>
            <p>Please verify your email address using the code below:</p>
            <div class="verification-code">
                {{ $user->email_verification_code }}
            </div>
            <p>This code will expire in 30 minutes. If you didn't request this, please ignore the email.</p>
            <p>Best regards,<br><strong>GradeQuest Team</strong></p>
        </div>
        <div class="email-footer">
            © {{ date('Y') }} GradeQuest. All rights reserved.
            <br>
            <a href="#">Privacy Policy</a> | <a href="#">Contact Support</a>
        </div>
    </div>
</body>
</html>
