# GradeQuest Backend

Laravel 11 API backend for GradeQuest, a SaaS school management platform built for African schools. It supports multi-school operations, subscriptions, per-student billing, school fee collection, result management, attendance, finance, parent/student portals, notifications, and Super Admin controls.

## Core Features

- Multi-school SaaS architecture with role-based access for Super Admin, Admin, Teacher, Bursar, Parent, and Student users.
- Dynamic subscription plans with feature access, student limits, and per-student pricing.
- GradeQuest revenue protection for online and offline billing models.
- Student management, teacher management, classes, sections, departments, subjects, terms, and academic sessions.
- Result entry, upload, batch monitoring, report cards, promotion, PINs, and academic alerts.
- School fee setup, parent payment links, Paystack integration, payment receipts, and platform fee tracking.
- Staff attendance with school-generated live QR, logged-in staff identity, GPS distance verification, and attendance logs.
- WhatsApp messaging, reminders, credits, and package-based access.
- Super Admin billing policy, temporary access, suspicious billing flags, package management, and invoice oversight.

## Tech Stack

- PHP 8.2+
- Laravel 11
- Laravel Sanctum
- MySQL
- Paystack
- Twilio / WhatsApp integrations
- DomPDF
- Maatwebsite Excel
- Spatie Laravel Permission

## Local Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

The API runs by default at:

```text
http://localhost:8000
```

## Important Environment Variables

Configure these in `.env`:

```env
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gradequest
DB_USERNAME=root
DB_PASSWORD=

PAYSTACK_PUBLIC_KEY=
PAYSTACK_SECRET_KEY=
PAYSTACK_PAYMENT_URL=https://api.paystack.co

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=

TWILIO_SID=
TWILIO_AUTH_TOKEN=
TWILIO_WHATSAPP_FROM=
```

Do not commit real `.env` values.

## Billing Model

GradeQuest supports:

- Online revenue model: platform fees are recovered from parent school-fee payments.
- Offline revenue model: GradeQuest generates term invoices based on active students.
- Legacy subscription credit handling for existing schools.
- Transition invoices when a school switches revenue model with uncovered students.
- Temporary access and policy controls from the Super Admin dashboard.

## Staff Attendance Model

The old personal teacher QR flow has been removed. The secure flow is:

1. Admin configures school location and allowed radius.
2. Admin generates a short-lived live QR.
3. Staff/Teacher logs in on their own device.
4. Staff scans the school QR.
5. Backend identifies staff from the authenticated user.
6. Backend validates QR, expiry, school ownership, and GPS distance.
7. Attendance is marked as check-in or check-out.

Relevant API routes:

```text
GET    /api/staff-attendance/session
POST   /api/staff-attendance/session
POST   /api/staff-attendance/mark
GET    /api/staff-attendance/logs
```

## Useful Commands

```bash
php artisan migrate
php artisan route:list
php artisan test
php artisan cache:clear
php artisan config:clear
php artisan optimize:clear
```

## Security Notes

- Keep `.env` out of Git.
- Use HTTPS in production.
- Restrict Paystack webhook routes to verified signatures.
- Run migrations before deploying new billing or attendance changes.
- Confirm school ownership and `school_id` consistency when creating users.

