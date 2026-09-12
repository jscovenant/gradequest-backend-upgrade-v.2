<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>SchoolProfit - Official Marketing Brochure</title>
<style>
  @page {
    margin: 10mm 12mm;
    size: a4 portrait;
  }
  * {
    box-sizing: border-box;
    -webkit-print-color-adjust: exact;
  }
  body {
    font-family: 'DejaVu Sans', Arial, sans-serif;
    color: #1e293b;
    font-size: 10px;
    line-height: 1.45;
    margin: 0;
    padding: 0;
    background: #ffffff;
  }
  .page {
    width: 100%;
    page-break-after: always;
    position: relative;
    padding-bottom: 25px;
  }
  .page:last-child {
    page-break-after: avoid;
  }
  .page-footer {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    border-top: 1px solid #e2e8f0;
    padding-top: 6px;
    font-size: 8px;
    color: #94a3b8;
    display: table;
    width: 100%;
  }
  .page-footer-left {
    display: table-cell;
    text-align: left;
  }
  .page-footer-right {
    display: table-cell;
    text-align: right;
  }

  /* Colors */
  .c-dark { color: #0f172a; }
  .c-navy { color: #0F2744; }
  .c-magenta { color: #d300b0; }
  .c-gold { color: #b45309; }
  .c-muted { color: #64748b; }
  .c-green { color: #15803d; }

  /* Header Banner */
  .hero-banner {
    background: #0F2744;
    color: #ffffff;
    border-radius: 10px;
    padding: 20px 24px;
    margin-bottom: 16px;
  }
  .brand-table {
    width: 100%;
    border-collapse: collapse;
  }
  .brand-title {
    font-size: 24px;
    font-weight: 900;
    letter-spacing: 0.5px;
    color: #ffffff;
  }
  .brand-title span {
    color: #ffc857;
  }
  .brand-badge {
    display: inline-block;
    background: rgba(211, 0, 176, 0.25);
    border: 1px solid #d300b0;
    color: #fbcfe8;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 9px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.8px;
  }
  .hero-tagline {
    font-size: 14px;
    font-weight: bold;
    color: #ffc857;
    margin-top: 10px;
    margin-bottom: 4px;
  }
  .hero-desc {
    font-size: 9.5px;
    color: #cbd5e1;
    line-height: 1.5;
    margin-top: 4px;
  }

  /* Grid Layouts using Tables */
  .table-grid {
    width: 100%;
    border-collapse: separate;
    border-spacing: 10px;
    margin-left: -10px;
    margin-right: -10px;
  }
  .grid-cell {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px 14px;
    vertical-align: top;
  }
  .card-title {
    font-size: 11px;
    font-weight: bold;
    color: #0F2744;
    margin-bottom: 4px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 4px;
  }
  .card-body {
    font-size: 9px;
    color: #475569;
    line-height: 1.45;
  }
  .card-body ul {
    margin: 4px 0 0 0;
    padding-left: 14px;
  }
  .card-body li {
    margin-bottom: 3px;
  }

  /* Section Title */
  .section-heading {
    border-left: 4px solid #d300b0;
    padding-left: 8px;
    font-size: 13px;
    font-weight: bold;
    color: #0F2744;
    margin-top: 14px;
    margin-bottom: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  /* Key Metrics Strip */
  .metrics-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 8px;
    margin: 10px 0;
  }
  .metric-box {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    text-align: center;
    vertical-align: top;
  }
  .metric-val {
    font-size: 16px;
    font-weight: 900;
    color: #0F2744;
  }
  .metric-label {
    font-size: 8px;
    color: #64748b;
    text-transform: uppercase;
    font-weight: bold;
    margin-top: 2px;
  }

  /* Table styles */
  .comparison-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
    font-size: 9px;
  }
  .comparison-table th {
    background: #0F2744;
    color: #ffffff;
    padding: 7px 10px;
    text-align: left;
    font-weight: bold;
  }
  .comparison-table td {
    padding: 7px 10px;
    border-bottom: 1px solid #e2e8f0;
    color: #334155;
  }
  .comparison-table tr:nth-child(even) td {
    background: #f8fafc;
  }
  .badge-check {
    color: #15803d;
    font-weight: bold;
  }
  .badge-cross {
    color: #94a3b8;
    font-weight: normal;
  }

  /* Call to Action Box */
  .cta-box {
    background: #0F2744;
    color: #ffffff;
    border-radius: 8px;
    padding: 14px 18px;
    margin-top: 14px;
  }
  .cta-title {
    font-size: 13px;
    font-weight: bold;
    color: #ffc857;
    margin-bottom: 4px;
  }
  .cta-desc {
    font-size: 9px;
    color: #e2e8f0;
    line-height: 1.4;
  }
  .rep-box {
    background: #ffffff;
    border: 2px dashed #0F2744;
    border-radius: 8px;
    padding: 12px 16px;
    margin-top: 12px;
    color: #0F2744;
  }
</style>
</head>
<body>

  <!-- ==========================================
       PAGE 1: COVER & EXECUTIVE OVERVIEW
       ========================================== -->
  <div class="page">
    <div class="hero-banner">
      <table class="brand-table">
        <tr>
          <td>
            <div class="brand-title">SCHOOL<span>PROFIT</span></div>
            <div style="font-size: 10px; color: #94a3b8; letter-spacing: 1px; margin-top: 2px;">SMART EDUTECH &amp; FINANCIAL ENGINE</div>
          </td>
          <td style="text-align: right; vertical-align: middle;">
            <div class="brand-badge">Official Prospectus &amp; Solution Guide</div>
          </td>
        </tr>
      </table>

      <div class="hero-tagline">
        Transforming School Administration, Academic Excellence &amp; Revenue Growth
      </div>
      <div class="hero-desc">
        SchoolProfit is Nigeria’s foremost enterprise educational portal engineered for Nursery, Primary, and Secondary Schools. We unite automated cognitive result computation, AI-driven curriculum planning, hybrid CBT examinations, and automated direct-split school fees collection into one high-performance cloud platform.
      </div>
    </div>

    <div class="section-heading">Why Leading Schools Partner With SchoolProfit</div>

    <table class="table-grid">
      <tr>
        <td class="grid-cell" style="width: 50%;">
          <div class="card-title c-magenta">🎯 100% Automated Result Computation</div>
          <div class="card-body">
            Eliminate errors, manual math, and endless spreadsheet headaches. Compute termly scores, weighted continuous assessments (CA1, CA2), exams, grade boundaries, student rankings, and cumulative CGPAs in seconds.
          </div>
        </td>
        <td class="grid-cell" style="width: 50%;">
          <div class="card-title c-navy">💳 Direct Bank Split-Fee Collection</div>
          <div class="card-body">
            Stop revenue leakage and bad school fee debts. Parents pay directly via automated virtual accounts, cards, or bank transfer (powered by Paystack &amp; Wema Bank). Funds settle instantly in your school's bank account with zero manual reconciliation.
          </div>
        </td>
      </tr>
      <tr>
        <td class="grid-cell" style="width: 50%;">
          <div class="card-title c-gold">🤖 AI Teacher Assistant &amp; Lesson Planner</div>
          <div class="card-body">
            Empower your teaching staff with artificial intelligence. Auto-generate standard NERDC-aligned Schemes of Work, weekly lesson plans, instructional objectives, and classroom quizzes in under 30 seconds.
          </div>
        </td>
        <td class="grid-cell" style="width: 50%;">
          <div class="card-title c-green">📝 Hybrid CBT Engine (Online &amp; LAN Offline)</div>
          <div class="card-body">
            Conduct seamless Computer-Based Tests without internet vulnerabilities. Run exams in cloud mode or deploy on a local offline school server with instant scoring and automatic push to termly report cards.
          </div>
        </td>
      </tr>
    </table>

    <div class="section-heading">Proven Institutional Impact</div>

    <table class="metrics-table">
      <tr>
        <td class="metric-box" style="width: 25%;">
          <div class="metric-val c-magenta">95%+</div>
          <div class="metric-label">Fee Recovery Rate</div>
        </td>
        <td class="metric-box" style="width: 25%;">
          <div class="metric-val c-navy">15+ Hrs</div>
          <div class="metric-label">Saved Per Teacher/Wk</div>
        </td>
        <td class="metric-box" style="width: 25%;">
          <div class="metric-val c-gold">100%</div>
          <div class="metric-label">Computation Accuracy</div>
        </td>
        <td class="metric-box" style="width: 25%;">
          <div class="metric-val c-green">24 Hours</div>
          <div class="metric-label">Complete Deployment</div>
        </td>
      </tr>
    </table>

    <div style="margin-top: 14px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 12px 16px;">
      <strong style="color: #1e3a8a; font-size: 10px;">🌟 Zero Technical Burden for School Leaders:</strong>
      <div style="font-size: 9px; color: #1e40af; margin-top: 3px; line-height: 1.4;">
        Our dedicated onboarding team imports all your students, classes, arms, and grading schemes so your school becomes fully operational within 24 hours without server installations or expensive hardware investments.
      </div>
    </div>

    <div class="page-footer">
      <div class="page-footer-left">SchoolProfit Technologies • https://schoolprofit.ng</div>
      <div class="page-footer-right">Page 1 of 4 • Executive Overview</div>
    </div>
  </div>


  <!-- ==========================================
       PAGE 2: CORE MODULES & FEATURES
       ========================================== -->
  <div class="page">
    <div style="border-bottom: 2px solid #0F2744; padding-bottom: 6px; margin-bottom: 12px;">
      <table style="width: 100%;">
        <tr>
          <td><strong style="font-size: 15px; color: #0F2744;">Core Platform Modules &amp; Academic Intelligence</strong></td>
          <td style="text-align: right; color: #64748b; font-size: 9px;">Enterprise Features</td>
        </tr>
      </table>
    </div>

    <table class="table-grid">
      <tr>
        <td class="grid-cell" style="width: 50%;">
          <div class="card-title c-navy">📊 Comprehensive Academic Result Engine</div>
          <div class="card-body">
            <ul>
              <li><strong>Broadsheet Generator:</strong> 1-click comprehensive broadsheets with class rankings, highest/lowest scores, subject averages, and positions.</li>
              <li><strong>Custom Report Card Designer:</strong> Branded report sheets featuring your school logo, watermarks, principal digital signature, and stamps.</li>
              <li><strong>Domain Assessments:</strong> Built-in 5-point rating scales for Psychomotor skills and Affective behavioral traits.</li>
              <li><strong>Parent Scratch Card / PIN Portal:</strong> Secure online student result checking portal with optional PIN protection.</li>
            </ul>
          </div>
        </td>

        <td class="grid-cell" style="width: 50%;">
          <div class="card-title c-magenta">💰 Automated Split Billing &amp; Debt Recovery</div>
          <div class="card-body">
            <ul>
              <li><strong>Direct Bank Settlement:</strong> Parent payments settle directly into your school’s account via Wema Bank &amp; Paystack.</li>
              <li><strong>Automated Invoicing:</strong> Termly digital invoices, installment tracking, balance reminders, and instant tamper-proof receipts.</li>
              <li><strong>Student-Level Access Control:</strong> Automatically restrict termly report card downloads for uncleared fee balances without affecting school administration.</li>
              <li><strong>Zero Reconciliation Stress:</strong> Real-time Bursary dashboard with daily payment audits and exportable financial reports.</li>
            </ul>
          </div>
        </td>
      </tr>

      <tr>
        <td class="grid-cell" style="width: 50%;">
          <div class="card-title c-gold">🤖 AI Teacher Assistant &amp; Curriculum Engine</div>
          <div class="card-body">
            <ul>
              <li><strong>Instant Scheme of Work:</strong> AI generates full 12-week structured schemes for any subject and grade in seconds.</li>
              <li><strong>Smart Lesson Note Generator:</strong> Formats detailed lesson notes complete with instructional materials, teacher activities, and evaluation guides.</li>
              <li><strong>Note-to-Exam Synthesis:</strong> Upload a teacher note or lesson topic, and AI automatically creates balanced objective &amp; theory questions.</li>
              <li><strong>Multi-Curriculum Support:</strong> Pre-configured for Nigerian National Curriculum, British, and blended international syllabi.</li>
            </ul>
          </div>
        </td>

        <td class="grid-cell" style="width: 50%;">
          <div class="card-title c-green">📱 WhatsApp Automation &amp; SMS Broadcasts</div>
          <div class="card-body">
            <ul>
              <li><strong>Direct Result Delivery:</strong> Push student results and PDF report sheets directly to parents’ WhatsApp numbers.</li>
              <li><strong>Automated Fee Reminders:</strong> Send polite, automated WhatsApp fee notices containing 1-click payment links.</li>
              <li><strong>Attendance Notifications:</strong> Notify parents instantly on WhatsApp when their child arrives or is absent from school.</li>
              <li><strong>Targeted School Broadcasts:</strong> Reach specific classes, arms, or all parents simultaneously with zero deliverability issues.</li>
            </ul>
          </div>
        </td>
      </tr>
    </table>

    <div class="section-heading">Operational &amp; Security Highlights</div>

    <table class="table-grid">
      <tr>
        <td class="grid-cell" style="width: 33.3%;">
          <div class="card-title c-navy">🕒 QR &amp; Biometric Attendance</div>
          <div class="card-body">
            Geofenced QR-code scanning and biometric clock-in for staff and students. Enforces punctuality and generates monthly attendance logs.
          </div>
        </td>
        <td class="grid-cell" style="width: 33.3%;">
          <div class="card-title c-magenta">🌐 Custom School Domain</div>
          <div class="card-body">
            Brand your portal with your own unique domain (e.g. <em>portal.yourschool.com</em>) complete with automatic free SSL security certificates.
          </div>
        </td>
        <td class="grid-cell" style="width: 33.3%;">
          <div class="card-title c-gold">🔒 Multi-Role Security</div>
          <div class="card-body">
            Granular permissions for Admins, Principals, Teachers, Bursars, Exam Officers, and Parents. Complete audit trails on every financial action.
          </div>
        </td>
      </tr>
    </table>

    <div class="page-footer">
      <div class="page-footer-left">SchoolProfit Technologies • https://schoolprofit.ng</div>
      <div class="page-footer-right">Page 2 of 4 • Platform Capabilities</div>
    </div>
  </div>


  <!-- ==========================================
       PAGE 3: HYBRID CBT & EDITIONS
       ========================================== -->
  <div class="page">
    <div style="border-bottom: 2px solid #0F2744; padding-bottom: 6px; margin-bottom: 12px;">
      <table style="width: 100%;">
        <tr>
          <td><strong style="font-size: 15px; color: #0F2744;">Hybrid CBT Engine &amp; Edition Breakdown</strong></td>
          <td style="text-align: right; color: #64748b; font-size: 9px;">Technology &amp; Packages</td>
        </tr>
      </table>
    </div>

    <div class="section-heading">Dual-Engine Computer Based Testing (CBT)</div>

    <table class="table-grid">
      <tr>
        <td class="grid-cell" style="width: 50%;">
          <div class="card-title c-navy">☁️ Online Cloud CBT Mode</div>
          <div class="card-body">
            <ul>
              <li>Students take exams, mock tests, and entrance screenings from any browser, laptop, or tablet.</li>
              <li>Supports Single Choice, Multiple Selection, True/False, Fill in the Blanks, and Theory questions.</li>
              <li>Anti-cheat controls: fullscreen lock, tab-switching detection, randomized question ordering, and option shuffling.</li>
              <li>Instant grading and real-time score analytics for teachers.</li>
            </ul>
          </div>
        </td>
        <td class="grid-cell" style="width: 50%;">
          <div class="card-title c-magenta">🏢 Local Offline LAN CBT Server</div>
          <div class="card-body">
            <ul>
              <li><strong>Zero Internet Required:</strong> Run large-scale termly exams inside your computer lab without buffering or network failure.</li>
              <li>One-click encrypted bundle download from the cloud to your local computer lab server.</li>
              <li>Instant exam synchronization back to the cloud once network connectivity is restored.</li>
              <li>Protects the school from erratic ISP downtimes during critical exams.</li>
            </ul>
          </div>
        </td>
      </tr>
    </table>

    <div class="section-heading">Edition Comparison Matrix</div>

    <table class="comparison-table">
      <thead>
        <tr>
          <th style="width: 48%;">Feature &amp; Capability</th>
          <th style="width: 26%; text-align: center;">Basic Result Edition</th>
          <th style="width: 26%; text-align: center; background: #d300b0;">Full CBT + AI Edition</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Student &amp; Class Profile Management</td>
          <td style="text-align: center;"><span class="badge-check">✓ Included</span></td>
          <td style="text-align: center;"><span class="badge-check">✓ Included</span></td>
        </tr>
        <tr>
          <td>Result Computation, Broadsheet &amp; Report Cards</td>
          <td style="text-align: center;"><span class="badge-check">✓ Included</span></td>
          <td style="text-align: center;"><span class="badge-check">✓ Included</span></td>
        </tr>
        <tr>
          <td>Custom Report Card Designer with School Branding</td>
          <td style="text-align: center;"><span class="badge-check">✓ Included</span></td>
          <td style="text-align: center;"><span class="badge-check">✓ Included</span></td>
        </tr>
        <tr>
          <td>WhatsApp Notification Engine (Fee Reminders &amp; Results)</td>
          <td style="text-align: center;"><span class="badge-check">✓ Available via Units</span></td>
          <td style="text-align: center;"><span class="badge-check">✓ Available via Units</span></td>
        </tr>
        <tr>
          <td>AI Teacher Assistant (Lesson Notes &amp; Schemes of Work)</td>
          <td style="text-align: center;"><span class="badge-check">✓ Available via Units</span></td>
          <td style="text-align: center;"><span class="badge-check">✓ Full AI Access</span></td>
        </tr>
        <tr>
          <td>Online Computer-Based Testing (CBT)</td>
          <td style="text-align: center;"><span class="badge-cross">—</span></td>
          <td style="text-align: center;"><span class="badge-check">✓ Included</span></td>
        </tr>
        <tr>
          <td>Local LAN Offline CBT Server Support</td>
          <td style="text-align: center;"><span class="badge-cross">—</span></td>
          <td style="text-align: center;"><span class="badge-check">✓ Included</span></td>
        </tr>
        <tr>
          <td>AI CBT Question Generator from Lesson Notes</td>
          <td style="text-align: center;"><span class="badge-cross">—</span></td>
          <td style="text-align: center;"><span class="badge-check">✓ Included</span></td>
        </tr>
        <tr>
          <td>Direct Bank Split Fee Collection (Paystack + Wema)</td>
          <td style="text-align: center;"><span class="badge-cross">—</span></td>
          <td style="text-align: center;"><span class="badge-check">✓ Included</span></td>
        </tr>
        <tr>
          <td>Biometric &amp; Staff QR Punctuality Attendance</td>
          <td style="text-align: center;"><span class="badge-cross">—</span></td>
          <td style="text-align: center;"><span class="badge-check">✓ Included</span></td>
        </tr>
        <tr>
          <td>Custom School Domain (e.g. portal.yourschool.edu)</td>
          <td style="text-align: center;"><span class="badge-cross">—</span></td>
          <td style="text-align: center;"><span class="badge-check">✓ Free Setup &amp; SSL</span></td>
        </tr>
      </tbody>
    </table>

    <div style="margin-top: 14px; background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; padding: 10px 14px;">
      <strong style="color: #854d0e; font-size: 9.5px;">💡 Unmatched Return On Investment:</strong>
      <div style="font-size: 8.5px; color: #713f12; margin-top: 2px; line-height: 1.4;">
        By eliminating paper exam sheets, report booklet printing, and bad school fee debts, schools save between <strong>₦250,000 to ₦1,200,000 every single academic term</strong> while elevating their institutional prestige among parents.
      </div>
    </div>

    <div class="page-footer">
      <div class="page-footer-left">SchoolProfit Technologies • https://schoolprofit.ng</div>
      <div class="page-footer-right">Page 3 of 4 • Technology &amp; Editions</div>
    </div>
  </div>


  <!-- ==========================================
       PAGE 4: ONBOARDING & CONTACT
       ========================================== -->
  <div class="page">
    <div style="border-bottom: 2px solid #0F2744; padding-bottom: 6px; margin-bottom: 12px;">
      <table style="width: 100%;">
        <tr>
          <td><strong style="font-size: 15px; color: #0F2744;">Simple 4-Step Onboarding &amp; Sales Contact</strong></td>
          <td style="text-align: right; color: #64748b; font-size: 9px;">Fast Launch</td>
        </tr>
      </table>
    </div>

    <div class="section-heading">How We Launch Your School in 24 Hours</div>

    <table class="table-grid">
      <tr>
        <td class="grid-cell" style="width: 25%;">
          <div style="font-size: 18px; font-weight: 900; color: #d300b0; margin-bottom: 2px;">01</div>
          <div class="card-title c-navy">Register Account</div>
          <div class="card-body">
            Visit <strong>schoolprofit.ng</strong> and create your school portal profile in 2 minutes.
          </div>
        </td>
        <td class="grid-cell" style="width: 25%;">
          <div style="font-size: 18px; font-weight: 900; color: #0F2744; margin-bottom: 2px;">02</div>
          <div class="card-title c-navy">Upload Records</div>
          <div class="card-body">
            Use our 1-click Excel template to import your students, classes, subjects, and teachers.
          </div>
        </td>
        <td class="grid-cell" style="width: 25%;">
          <div style="font-size: 18px; font-weight: 900; color: #b45309; margin-bottom: 2px;">03</div>
          <div class="card-title c-navy">Configure Setup</div>
          <div class="card-body">
            Add your school logo, custom grading scale, bank settlement account, and term dates.
          </div>
        </td>
        <td class="grid-cell" style="width: 25%;">
          <div style="font-size: 18px; font-weight: 900; color: #15803d; margin-bottom: 2px;">04</div>
          <div class="card-title c-navy">Go Live!</div>
          <div class="card-body">
            Start printing results, running CBT exams, and collecting fees seamlessly!
          </div>
        </td>
      </tr>
    </table>

    <div class="cta-box">
      <div class="cta-title">🎁 Special Promotional Offer for New Partner Schools</div>
      <div class="cta-desc">
        Sign up this month and receive an instant <strong>₦5,000 Welcome Wallet Bonus</strong> credited directly to your school account. Activate and lock in your bonus permanently by adding initial float funds within your first 30 days!
      </div>
    </div>

    <div class="section-heading">Authorized Sales &amp; Implementation Partner</div>

    <div class="rep-box">
      <table style="width: 100%; border-collapse: collapse;">
        <tr>
          <td style="width: 60%; vertical-align: top;">
            <div style="font-size: 9px; text-transform: uppercase; color: #64748b; font-weight: bold; letter-spacing: 0.5px;">Your Dedicated Representative</div>
            <div style="font-size: 14px; font-weight: 900; color: #0F2744; margin-top: 3px;">
              {{ $rep_name ?? 'Official SchoolProfit Representative' }}
            </div>
            <div style="font-size: 10px; color: #334155; margin-top: 4px;">
              <strong>Phone / WhatsApp:</strong> {{ $rep_phone ?? '+234 (0) 800-SCHOOLPROFIT' }}
            </div>
            <div style="font-size: 10px; color: #334155; margin-top: 2px;">
              <strong>Email:</strong> {{ $rep_email ?? 'admin@schoolprofit.ng' }}
            </div>
            @if(!empty($rep_code))
            <div style="font-size: 10px; color: #d300b0; font-weight: bold; margin-top: 3px;">
              <strong>Referral / Partner Code:</strong> {{ $rep_code }}
            </div>
            @endif
          </td>
          <td style="width: 40%; vertical-align: top; text-align: right; border-left: 1px solid #e2e8f0; padding-left: 14px;">
            <div style="font-size: 9px; text-transform: uppercase; color: #64748b; font-weight: bold;">Head Office &amp; Portal</div>
            <div style="font-size: 11px; font-weight: bold; color: #0F2744; margin-top: 3px;">SchoolProfit Technologies</div>
            <div style="font-size: 9.5px; color: #475569; margin-top: 2px;">🌐 https://schoolprofit.ng</div>
            <div style="font-size: 9.5px; color: #475569; margin-top: 2px;">📅 Book Demo: schoolprofit.ng/book-demo</div>
            <div style="font-size: 9.5px; color: #475569; margin-top: 2px;">✉️ support@gradequest.com</div>
          </td>
        </tr>
      </table>
    </div>

    <div style="text-align: center; margin-top: 14px; font-size: 8.5px; color: #94a3b8;">
      © {{ date('Y') }} SchoolProfit Technologies. All rights reserved. Secure Cloud Infrastructure hosted on AWS.
    </div>

    <div class="page-footer">
      <div class="page-footer-left">SchoolProfit Technologies • https://schoolprofit.ng</div>
      <div class="page-footer-right">Page 4 of 4 • Onboarding &amp; Representative Info</div>
    </div>
  </div>

</body>
</html>
