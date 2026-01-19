<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>{{ $subjectLine }}</title>
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
      <h1>{{ $subjectLine }}</h1>
    </div>

    <div class="email-body">
      {!! $emailContent !!}
    </div>

    <div class="email-footer">
      &copy; {{ date('Y') }} GradeQuest. All rights reserved.<br>
      <a href="#">Privacy Policy</a> | <a href="#">Contact Support</a>
    </div>
  </div>
</body>
</html>
