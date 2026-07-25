# GradeQuest SaaS School Management System Design

## Product Vision

GradeQuest is a multi-tenant SaaS school management platform built for African schools. It is designed for private schools, public schools, school groups, faith-based schools, low-connectivity schools, and multi-campus institutions.

The platform helps schools manage admissions, students, staff, fees, attendance, results, communication, payroll, inventory, transport, hostel, library, health records, and reporting from one system.

The system should be:

- Cloud-based
- Mobile-first
- Offline-aware
- Multi-tenant
- Multi-campus
- Affordable for African schools
- Integrated with local payments
- Integrated with SMS, WhatsApp, email, and push notifications
- Secure and role-based

## Target Market

The platform targets schools across Africa, where common realities include:

- Unstable internet access
- Heavy mobile phone usage
- Low-cost Android devices
- Fee payment challenges
- Parent communication challenges
- Manual result processing
- Paper-based attendance
- Multiple local payment methods
- Term-based school calendars
- Multi-branch school operations

## SaaS Model

GradeQuest should be built as a SaaS product where many schools use the same platform, but each school has isolated data and its own configuration.

```text
Platform Owner / Super Admin
        |
        v
Schools / Tenants
        |
        v
Campuses / Branches
        |
        v
Users, Students, Staff, Finance, Academics
```

Each school is a tenant.

A tenant can have:

```text
School
  -> Campuses
  -> Academic Sessions
  -> Terms
  -> Classes
  -> Arms / Streams
  -> Students
  -> Staff
  -> Parents
```

## Core Users

### Platform Users

- Super Admin
- Support Admin
- Sales Admin
- Finance Admin

### School Users

- School Owner / Proprietor
- Principal / Head Teacher
- Bursar / Accountant
- Teachers
- Students
- Parents / Guardians
- Librarian
- Transport Manager
- Hostel Manager
- Nurse
- Inventory Officer
- Security Staff

## Core SaaS Features

### Tenant Management

- Create school account
- School profile
- School logo and branding
- Custom subdomain, for example `brightstars.gradequest.com`
- Optional custom domain, for example `portal.brightstarschool.com`
- Subscription plan
- Trial period
- Tenant status: active, trial, suspended, cancelled
- School settings
- Country, currency, and timezone settings
- Data isolation per school

### Subscription Billing

- Monthly, termly, or yearly plans
- Per-student pricing
- Module-based pricing
- Free trial
- Automatic renewal
- SaaS invoice generation
- Payment reminders
- Grace period before suspension
- Subscription upgrade and downgrade
- Usage tracking

### Platform Super Admin Dashboard

The platform owner should be able to manage:

- All schools
- Active subscriptions
- Trial schools
- Suspended schools
- Revenue
- Failed renewals
- Support tickets
- System usage
- SMS and WhatsApp wallet usage
- Payment provider settings
- Country and currency settings
- Global announcements

### School Admin Dashboard

Each school gets its own dashboard to manage:

- Students
- Staff
- Parents
- Classes
- Subjects
- Attendance
- Fees
- Payments
- Results
- Report cards
- Announcements
- Notifications
- Timetable
- Reports
- School settings

## Major System Modules

### 1. School and Branch Management

- School profile
- Multi-campus setup
- Academic sessions
- Terms / semesters
- Class structure
- Arms / streams
- Departments
- Subject setup
- Grading systems
- Promotion rules
- Staff roles
- Custom school policies

### 2. Admissions and Enrollment

- Online admission forms
- Application fee payment
- Entrance exam scheduling
- Interview tracking
- Admission workflow
- Auto-generate admission number
- Document upload
- Parent/guardian linking
- Student medical/profile records
- Transfer student support

Automation:

- Send SMS/email when application is received
- Auto-create invoice after admission
- Auto-assign student to class after payment confirmation
- Auto-generate student portal login

### 3. Student Information System

- Bio-data
- Guardian details
- Academic history
- Attendance history
- Fee history
- Health records
- Behavior records
- Documents
- Passport photo
- Sibling linking
- House/club assignment
- Hostel/transport enrollment

### 4. Staff and HR Management

- Staff profile
- Employment records
- Roles and permissions
- Department assignment
- Teacher-subject allocation
- Staff attendance
- Leave management
- Payroll
- Payslips
- Tax/pension deductions
- Staff performance records

### 5. Academic Management

- Subject setup
- Teacher assignment
- Lesson planning
- Scheme of work
- Class timetable
- Assignment management
- Continuous assessment
- Exam score entry
- Psychomotor/affective grading
- Comments
- Result computation
- Report card generation
- Student promotion

### 6. Attendance Management

- Student attendance
- Staff attendance
- Daily attendance
- Subject attendance
- Late arrival tracking
- Absence reasons
- Attendance reports
- QR code support
- Optional biometric support

Offline support:

- Teacher marks attendance offline
- App syncs when internet returns
- Conflict resolution by timestamp and teacher role

Automation:

- Send SMS/WhatsApp to parent if child is absent
- Notify principal of repeated lateness
- Generate weekly attendance summary

### 7. Finance and Fee Management

- Fee structure by class/session/term
- Custom student discounts
- Scholarships
- Installment payments
- Invoice generation
- Receipts
- Debt tracking
- Wallet/credit balance
- Expense tracking
- Income tracking
- Bank account tracking
- Payment reconciliation
- Financial reports

Payment integrations:

- Paystack
- Flutterwave
- Monnify
- Interswitch
- M-Pesa
- Mobile Money
- Bank transfer
- POS/manual payment
- USSD where available

Automation:

- Auto-generate invoices at start of term
- Auto-send payment reminders
- Auto-block result access for debtors if enabled
- Auto-reconcile payment webhook
- Auto-send receipt after payment
- Notify bursar of failed or partial payments

### 8. Exams, Results, and Report Cards

- Exam setup
- Continuous assessment setup
- CA/exam weighting
- Grade boundaries
- Subject comments
- Class teacher comments
- Principal comments
- Position/ranking
- Report card templates
- PDF export
- Parent portal access
- Result approval workflow

Workflow:

```text
Teacher enters scores
  -> Head of department reviews
    -> Principal approves
      -> Result is published
        -> Parents/students notified
```

### 9. Parent and Student Portals

Parent portal:

- Pay fees
- View invoices and receipts
- View attendance
- View results
- View announcements
- Message school
- Track assignments
- View discipline records
- Manage multiple children

Student portal:

- View assignments
- Submit homework
- Check timetable
- View results
- Access learning materials
- Receive announcements

### 10. Communication System

Channels:

- SMS
- Email
- WhatsApp
- Push notifications
- In-app announcements
- Optional voice call integration

Features:

- Bulk SMS
- Class announcements
- Parent messaging
- Staff messaging
- Emergency alerts
- Fee reminders
- Attendance alerts
- Birthday messages
- Result release alerts

### 11. Timetable and Scheduling

- Class timetable
- Teacher timetable
- Exam timetable
- Room allocation
- Event calendar
- Assembly periods
- Break periods
- Substitute teacher assignment
- Teacher conflict detection
- Room conflict detection

### 12. Transport Management

- Routes
- Buses
- Drivers
- Pickup/drop-off points
- Student transport subscription
- Bus attendance
- Optional GPS tracking
- Transport fee billing
- Bus maintenance tracking

### 13. Hostel / Boarding Management

- Hostel blocks
- Rooms
- Bed allocation
- House parents
- Hostel attendance
- Hostel fees
- Visitor records
- Exit permissions
- Incident reports

### 14. Library Management

- Book catalog
- Borrowing
- Returns
- Fines
- Lost book tracking
- Student reading history
- Barcode/QR support

### 15. Inventory and Asset Management

- School assets
- Stock items
- Uniforms
- Books
- Stationery
- Lab equipment
- Procurement
- Supplier records
- Stock alerts
- Asset depreciation tracking

### 16. Clinic and Health Records

- Student medical profile
- Allergies
- Blood group
- Immunization record
- Sickbay visits
- Medication log
- Emergency contacts
- Incident reports

### 17. Discipline and Behavior Management

- Incident records
- Merits/demerits
- Detention/sanctions
- Counseling notes
- Parent notification
- Behavior reports

### 18. Reporting and Analytics

Dashboards:

- Enrollment dashboard
- Revenue dashboard
- Outstanding fees
- Attendance rate
- Academic performance
- Staff productivity
- Class performance
- Gender distribution
- Dropout/withdrawal trends
- Parent engagement
- Expense vs income

Reports:

- Student list
- Class list
- Debtors list
- Payment report
- Attendance report
- Result broadsheet
- Payroll report
- Government/statutory reports

## Automation Engine

The platform should include a rule-based automation engine.

Example rules:

```text
WHEN student is absent
IF parent has phone number
THEN send SMS to parent
```

```text
WHEN invoice is overdue by 7 days
THEN send payment reminder
```

```text
WHEN payment is confirmed
THEN generate receipt and update student balance
```

```text
WHEN result is approved
THEN publish to parent portal
```

Automation components:

- Event listeners
- Job queue
- Notification service
- Rule engine
- Scheduler/cron jobs
- Audit logs

## Recommended Architecture

Start with a modular monolith. It is easier to build, deploy, and maintain at the early stage than microservices.

```text
Web App / Mobile App
        |
        v
API Gateway / Backend
        |
        v
Tenant Resolver
        |
        v
Application Modules
        |
        v
Database / Cache / Queue / Storage
        |
        v
Payment, SMS, Email, WhatsApp Providers
```

Suggested backend domains:

```text
Platform
- tenants
- subscriptions
- plans
- billing
- support

School Core
- schools
- campuses
- sessions
- terms
- classes
- subjects

People
- students
- guardians
- staff
- users
- roles

Academics
- attendance
- exams
- scores
- report_cards
- promotions

Finance
- fee_structures
- invoices
- payments
- receipts
- expenses

Communication
- notifications
- sms_logs
- email_logs
- whatsapp_logs

Automation
- automation_rules
- scheduled_jobs
- audit_logs
```

## Recommended Technology Stack

Frontend:

- React / Next.js for web
- React Native or Flutter for mobile
- Tailwind CSS or another design system

Backend:

- Laravel, NestJS, Django, or Spring Boot
- REST API or GraphQL
- Background jobs

Database:

- PostgreSQL or MySQL
- Redis for cache and queues
- Object storage for files

Infrastructure:

- Docker
- Nginx
- AWS, Azure, Google Cloud, or DigitalOcean
- S3-compatible storage
- CDN for static assets

Notifications:

- SMS providers by country
- WhatsApp Business API
- Email provider
- Firebase push notifications

Payments:

- Paystack
- Flutterwave
- Monnify
- M-Pesa
- Mobile Money integrations

## Database Strategy

For the first version, use a single database with a shared schema and strict tenant isolation.

Every major table should include:

```text
tenant_id
school_id
campus_id
created_by
updated_by
```

Example student table:

```text
students
  id
  tenant_id
  school_id
  campus_id
  admission_no
  first_name
  last_name
  class_id
  status
```

Important entities:

```text
tenants
plans
subscriptions
schools
campuses
academic_sessions
terms
classes
class_arms
subjects
students
guardians
student_guardians
staff
roles
permissions
teacher_subjects
attendances
fee_structures
invoices
invoice_items
payments
expenses
exams
assessments
scores
results
report_cards
notifications
automation_rules
audit_logs
documents
transport_routes
hostels
library_books
inventory_items
```

## Tenant Resolution

The system should identify the current tenant using:

- Subdomain: `schoolname.gradequest.com`
- Custom domain: `portal.schoolname.com`
- Authenticated user tenant
- API token tenant

Best approach:

```text
Subdomain + authenticated user tenant check
```

## Security Design

Required security features:

- Role-based access control
- Tenant-level data isolation
- School-level data isolation
- Multi-factor authentication for admins
- Password reset by phone/email
- Audit logs
- Encrypted sensitive data
- Payment webhook verification
- Rate limiting
- Backups
- Activity logs
- Permission per module
- Parent can only access linked children
- Teacher can only access assigned classes/subjects

## Offline-First Design

Offline-capable features:

- Attendance
- Score entry
- Student lookup
- Timetable
- Assignment viewing
- Fee receipt viewing

Approach:

- Mobile app stores data locally
- Sync queue records offline actions
- Backend resolves conflicts
- Sync status is visible to user
- Last synced timestamp is shown

## SaaS Payment Flow

For schools paying GradeQuest:

```text
School subscribes
  -> Selects plan
  -> Pays subscription
  -> Tenant becomes active
  -> System renews subscription
  -> Failed payment triggers reminders
  -> Grace period starts
  -> Tenant is suspended if unpaid
```

For parents paying schools:

```text
Parent pays school fees
  -> Payment provider confirms
  -> Student invoice updates
  -> Receipt is generated
  -> Parent and school get notified
```

These two payment flows should be separated:

- SaaS billing: schools pay GradeQuest.
- School finance: parents pay schools.

## Example SaaS Plans

### Starter

- Up to 300 students
- Students
- Attendance
- Fees
- Results
- SMS pay-as-you-go

### Growth

- Up to 1,000 students
- Parent portal
- Online payments
- Report cards
- Staff management
- SMS and email notifications

### Premium

- Unlimited students
- Multi-campus
- Payroll
- Inventory
- Transport
- Hostel
- Advanced analytics

### Enterprise

- Dedicated deployment
- Custom integrations
- Government reports
- Priority support
- Dedicated support manager

## MVP Recommendation

Start with the modules schools will pay for fastest.

Phase 1:

- Tenant signup
- School onboarding
- User roles
- Student management
- Staff management
- Class/session/term setup
- Fee setup
- Invoice/payment tracking
- Attendance
- Results/report cards
- Parent portal
- SMS notifications
- SaaS subscription billing
- Super admin dashboard

Phase 2:

- Admissions
- Timetable
- Assignments
- Payroll
- Inventory
- Transport
- Library
- Hostel

Phase 3:

- AI assistant
- Government reporting
- Offline local server
- Advanced analytics
- Multi-country compliance
- Biometric/QR integrations

## AI and Smart Automation Features

Optional future features:

- Predict students likely to fail
- Detect fee default risk
- Generate teacher comments
- Summarize student performance
- Auto-create lesson notes
- Parent chatbot
- Voice-based school assistant
- Smart timetable generation
- Fraud detection for payments
- Attendance anomaly detection

## Deployment Model

Support:

- Cloud SaaS for most schools
- Dedicated cloud instance for large school groups
- Offline local server for rural or government deployments

## Final Recommendation

GradeQuest should be built as a multi-tenant, mobile-first, offline-aware SaaS platform with strong finance, attendance, results, and parent communication features.

For the first version, use a modular backend with a shared database and strict `tenant_id` isolation. This gives the best balance of speed, cost, and scalability for African school adoption.
