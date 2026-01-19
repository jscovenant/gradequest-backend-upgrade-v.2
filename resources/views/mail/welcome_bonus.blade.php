<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Welcome to GradeQuest - ₦500 Bonus Credited!</title>
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
    .email-body a {
      color: #0d6efd;
      text-decoration: none;
      font-weight: 600;
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
      <h1>Welcome to GradeQuest 🎓</h1>
    </div>

    <div class="email-body">
      <p>Hello <strong>{{ $user->name ?? ($user->firstname . ' ' . $user->surname ?? 'User') }}</strong>,</p>

      <p>Welcome to <strong>GradeQuest</strong>! 🎉</p>

      <p>
        We’re excited to have you join our community. As a token of appreciation,
        we’ve credited your wallet with a <strong>₦500 welcome bonus</strong> to help you get started.
      </p>

      <p>
        Use your wallet balance to explore our premium academic tools and resources.
      </p>

      <p>
        For step-by-step explanatory videos on how to set up and use various features,
        please visit our blog:
        <br>
        👉 <a href="https://gradequest.com.ng/blog" target="_blank">
          https://gradequest.com.ng/blog
        </a>
      </p>

      <p>
        Thank you for choosing <strong>GradeQuest</strong> — where learning meets innovation!
      </p>

      <p>— The GradeQuest Team</p>
    </div>

    <div class="email-footer">
      <p>&copy; {{ date('Y') }} <a href="{{ url('/') }}">GradeQuest</a>. All rights reserved.</p>
    </div>
  </div>
</body>
</html>
