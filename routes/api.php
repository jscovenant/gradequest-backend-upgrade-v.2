<?php

use App\Http\Controllers\ActivationController;
use App\Http\Controllers\Api\AdminAcademicAlertController;
use App\Http\Controllers\Api\AdminMonitoringController;
use App\Http\Controllers\Api\AdminResultDeadlineController;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Backend\DepartmentController;
use App\Http\Controllers\Backend\StaffAttendanceController;
use App\Http\Controllers\Backend\ResultController;
use App\Http\Controllers\Backend\SessionController;
use App\Http\Controllers\Backend\StudentClassController;
use App\Http\Controllers\Backend\StudentController;
use App\Http\Controllers\Backend\SubjectController;
use App\Http\Controllers\Backend\SuperAdminController;
use App\Http\Controllers\Backend\PlatformStaffController;
use App\Http\Controllers\Backend\SalesRepresentativeController;
use App\Http\Controllers\Backend\SalesPayoutController;
use App\Http\Controllers\Backend\SuperAdminTwilioController;
use App\Http\Controllers\Backend\TeacherController;
use App\Http\Controllers\Backend\AttendanceController;
use App\Http\Controllers\Backend\TermController;
use App\Http\Controllers\Backend\TestimonialController;
use App\Http\Controllers\Backend\BlogController;

use App\Http\Controllers\ContactController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SchoolLogoController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\Backend\SchoolBankAccountController;
use App\Http\Controllers\Backend\SchoolBillingController;
use Illuminate\Http\Request;

use App\Http\Controllers\Backend\TimetableController;

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Backend\TeacherSubjectController;
use App\Http\Controllers\Backend\WhatsAppSettingsController;
use App\Http\Controllers\Backend\WhatsAppBroadcastController;
use App\Http\Controllers\Backend\SectionController;
use App\Http\Controllers\Backend\FeePaymentController;
use App\Http\Controllers\Backend\FeeTypeController;
use App\Http\Controllers\Backend\StudentFeeController;
use App\Http\Controllers\Backend\SubscriptionPlanController;
use App\Http\Controllers\Backend\SubscriptionController;
use App\Http\Controllers\Backend\PaymentGatewayController;
use App\Http\Controllers\Backend\ParentController;
use App\Http\Controllers\Backend\ParentContactValidationController;
use App\Http\Controllers\Backend\SchoolFeesReportController;
use App\Http\Controllers\Backend\FinancialRecordController;
use App\Http\Controllers\Backend\ReceiptController;
use App\Http\Controllers\Backend\BusarController;
use App\Http\Controllers\Backend\PublicResultController;
use App\Http\Controllers\Backend\ResultPinController;
use App\Http\Controllers\Backend\FinanceDashboardController;
use App\Http\Controllers\Backend\FinancialCategoryController;
use App\Http\Controllers\Api\ResultBatchController;
use App\Http\Controllers\Api\ResultTemplateSettingController;
use App\Http\Controllers\Api\StudentResultController;
use App\Http\Controllers\Api\FilterSetupController;
use App\Http\Controllers\Api\BroadsheetV2Controller;
use App\Http\Controllers\Api\CbtOnboardingController;
use App\Http\Controllers\Api\CbtExamController;
use App\Http\Controllers\Api\CbtOfflineLicenseController;
use App\Http\Controllers\Api\OfflineCbtServerController;
use App\Http\Controllers\Api\PublicCbtExamController;
use App\Http\Controllers\Api\CbtStudentExamController;

use App\Http\Controllers\Api\SchoolDomainController;

use App\Http\Controllers\Backend\AttendanceSettingController;
use App\Http\Controllers\Backend\AdminDashboardController;
use App\Http\Controllers\Backend\BursarDashboardController;
use App\Http\Controllers\Backend\FeeReminderAnalyticsController;
use App\Http\Controllers\Backend\FeeReminderSettingsController;
use App\Http\Controllers\Backend\FeeAccessPolicyController;
use App\Http\Controllers\Backend\InvoiceNotificationController;
use App\Http\Controllers\Backend\TeacherDashboardController;
use App\Http\Controllers\Backend\StudentDashboardController;
use App\Http\Controllers\Backend\ParentDashboardController;
use App\Http\Controllers\Backend\ParentStudentFeesController;
use App\Http\Controllers\Backend\PublicDemoBookingController;
use App\Http\Controllers\Backend\PublicFeePaymentController;
use App\Http\Controllers\Backend\GradequestInvoicePaymentController;
use App\Http\Controllers\Backend\GradequestBillingPolicyController;
use App\Http\Controllers\Frontend\HomeController;

    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/check-subdomain/{subdomain}', [AuthController::class, 'checkSubdomain']);
    Route::post('/forgot-password', [AuthController::class, 'sendAdminResetLink']);
    Route::post('/verify-reset-code', [AuthController::class, 'verifyResetCode']);
Route::post('/reset-password',    [AuthController::class, 'resetPassword']);

Route::post('/send-contact', [ContactController::class, 'send']);
Route::get('/testimonials', [TestimonialController::class, 'index']);
Route::get('/testimonials/{id}', [TestimonialController::class, 'edit']);     
Route::put('/testimonials/{id}', [TestimonialController::class, 'update']);
Route::post('/demo-bookings', [PublicDemoBookingController::class, 'store']);
Route::get('/public/fee-payment/school', [PublicFeePaymentController::class, 'school']);
Route::get('/public/fee-payment/student', [PublicFeePaymentController::class, 'student']);
Route::post('/public/fee-payment/initialize', [PublicFeePaymentController::class, 'initialize']);
Route::get('/public/fee-payment/verify/{reference}', [PublicFeePaymentController::class, 'verify']);
Route::post('/public/paystack/sales-payout-webhook', [\App\Http\Controllers\Backend\SalesPayoutWebhookController::class, 'handle']);
Route::get('/public/cbt/access/lookup', [PublicCbtExamController::class, 'lookup']);
Route::post('/public/cbt/access/start', [PublicCbtExamController::class, 'start']);
Route::post('/public/cbt/attempts/{token}/answers', [PublicCbtExamController::class, 'saveAnswer']);
Route::post('/public/cbt/attempts/{token}/events', [PublicCbtExamController::class, 'logEvent']);
Route::post('/public/cbt/attempts/{token}/submit', [PublicCbtExamController::class, 'submit']);
Route::prefix('offline-cbt')->group(function () {
    Route::get('/status', [OfflineCbtServerController::class, 'status']);
    Route::post('/bundle/import', [OfflineCbtServerController::class, 'importBundle']);
    Route::post('/students/lookup', [OfflineCbtServerController::class, 'lookupStudent']);
    Route::post('/exams/{examId}/start', [OfflineCbtServerController::class, 'startExam']);
    Route::post('/attempts/{uuid}/answers', [OfflineCbtServerController::class, 'saveAnswer']);
    Route::post('/attempts/{uuid}/events', [OfflineCbtServerController::class, 'logEvent']);
    Route::post('/attempts/{uuid}/submit', [OfflineCbtServerController::class, 'submitAttempt']);
    Route::get('/results/export', [OfflineCbtServerController::class, 'exportResults']);
});

Route::get('/frontend/subscription-plans', [HomeController::class, 'subscriptionPlans']);

     Route::post('/verify-result', [ResultController::class, 'verifyResult']);
  Route::get('/frontend-blogs', [BlogController::class, 'index']);
   Route::get('/blog/{slug}', [BlogController::class, 'showBlogs']);
   
 



 Route::get('/result/{studentId}', [ResultController::class, 'showStudentResult']);
 
Route::get('/public/check-result', [PublicResultController::class, 'checkWithPin']);
 
Route::get('/result/{studentId}', [ResultController::class, 'showStudentResult']);

 

 
Route::middleware(['auth:sanctum', 'tenant'])->post('/auth/change-initial-password', [AuthController::class, 'changeInitialPassword']);

Route::middleware(['auth:sanctum', 'tenant', 'school.billing.clearance'])->group(function () {
    
    //Route for activating account
Route::get('/user/onboarding-status', [ActivationController::class, 'onboardingStatus']);
Route::get('/user/onboarding-complete', [ActivationController::class, 'checkOnboardingComplete']);

Route::post('/verify-email-code', [ActivationController::class, 'verifyEmailCode']);
Route::post('/set-current-session', [ActivationController::class, 'setCurrentSession']);
Route::post('/create-all-terms', [ActivationController::class, 'createAllTerms']);
Route::post('/activate-bonus', [ActivationController::class, 'activateBonus']);
Route::post('/resend-email-code', [ActivationController::class, 'resendEmailCode']);
    
  Route::get('/notifications', function (Request $request) {
    $notifications = $request->user()
        ->unreadNotifications() 
        ->take(10)
        ->get()
        ->map(function ($note) {
            return [
                'id' => $note->id,
                'message' => $note->data['message'] ?? '',
                'type' => $note->data['type'] ?? 'info',
                'time' => $note->created_at->diffForHumans(),
                'action_url' => $note->data['action_url'] ?? null,
            ];
        });

    return response()->json($notifications);
});

Route::get('/notifications/all', function (Request $request) {
    $notifications = $request->user()
        ->notifications() 
        ->take(50) 
        ->get()
        ->map(function ($note) {
            return [
                'id' => $note->id,
                'message' => $note->data['message'] ?? '',
                'type' => $note->data['type'] ?? 'info',
                'time' => $note->created_at->diffForHumans(),
                'action_url' => $note->data['action_url'] ?? null,
                'read_at' => $note->read_at,
            ];
        });

    return response()->json($notifications);
});


Route::post('/notifications/read/{id}', function (Request $request, $id) {
    $notification = $request->user()->notifications()->find($id);
    if ($notification) $notification->markAsRead();
    return response()->json(['status' => 'ok']);
});

 Route::get('/admin/academic-alerts/summary', [AdminAcademicAlertController::class, 'summary']);
 
  Route::get(
        '/admin/result-batches',
        [AdminResultDeadlineController::class, 'index']
    );

    Route::post(
        '/admin/result-batches/{batch}/set-deadline',
        [AdminResultDeadlineController::class, 'setDeadline']
    );



Route::get('/admin/demo-bookings', [PublicDemoBookingController::class, 'index'])->middleware('superadmin.access:sales,support,owner');
    Route::get('/admin/demo-bookings/{id}', [PublicDemoBookingController::class, 'show'])->middleware('superadmin.access:sales,support,owner');
    Route::patch('/admin/demo-bookings/{id}/status', [PublicDemoBookingController::class, 'updateStatus'])->middleware('superadmin.access:sales,support,owner');

    //route for super-admin
    Route::get('/admin-users', [SuperAdminController::class, 'getAdminUsers'])->middleware('superadmin.access:support,billing,finance,owner');
    Route::get('/admin-users/view/{id}', [SuperAdminController::class, 'showAdmin'])->middleware('superadmin.access:support,billing,finance,owner');
    Route::get('/user/features', [SuperAdminController::class, 'getUserFeatures']);
    Route::get('/admin/subscriptions', [SuperAdminController::class, 'getSubscribers'])->middleware('superadmin.access:billing,finance,owner');

    Route::get('/admin-users/{id}', [SuperAdminController::class, 'edit'])->middleware('superadmin.access:support,owner');
    Route::put('/admin-users/{id}', [SuperAdminController::class, 'update'])->middleware('superadmin.access:support,owner');
    Route::delete('/admin-users/{id}', [SuperAdminController::class, 'destroy'])->middleware('superadmin.access:owner');
    Route::get('/platform-logs', [SuperAdminController::class, 'getLogs'])->middleware('superadmin.access:audit,owner');
    Route::post('/send-marketing-emails', [SuperAdminController::class, 'sendMarketingEmail'])->middleware('superadmin.access:marketing,owner');
    Route::get('/mail/admin-users', [SuperAdminController::class, 'mailAdminUsers'])->middleware('superadmin.access:marketing,owner');
    
    Route::get('/monthly-revenue-stats', [SuperAdminController::class, 'monthlyRevenueStats'])->middleware('superadmin.access:finance,billing,owner');
    Route::post('/platform-logs/delete-multiple', [SuperAdminController::class, 'deleteMultiple'])->middleware('superadmin.access:owner');
    Route::get('/superadmin/platform-staff', [PlatformStaffController::class, 'index'])->middleware('superadmin.access:staff,owner');
    Route::post('/superadmin/platform-staff', [PlatformStaffController::class, 'store'])->middleware('superadmin.access:owner');
    Route::put('/superadmin/platform-staff/{staff}', [PlatformStaffController::class, 'update'])->middleware('superadmin.access:owner');
    Route::delete('/superadmin/platform-staff/{staff}', [PlatformStaffController::class, 'destroy'])->middleware('superadmin.access:owner');
    Route::post('/superadmin/platform-staff/{staff}/send-login', [PlatformStaffController::class, 'sendLoginDetails'])->middleware('superadmin.access:owner');
    Route::get('/superadmin/sales-representatives', [SalesRepresentativeController::class, 'index'])->middleware('superadmin.access:sales,finance,owner');
    Route::post('/superadmin/sales-representatives', [SalesRepresentativeController::class, 'store'])->middleware('superadmin.access:sales,owner');
    Route::get('/superadmin/sales-representatives/{salesRepresentative}', [SalesRepresentativeController::class, 'show'])->middleware('superadmin.access:sales,finance,owner');
    Route::put('/superadmin/sales-representatives/{salesRepresentative}', [SalesRepresentativeController::class, 'update'])->middleware('superadmin.access:sales,owner');
    Route::post('/superadmin/sales-representatives/{salesRepresentative}/assign', [SalesRepresentativeController::class, 'assign'])->middleware('superadmin.access:sales,owner');
    Route::post('/superadmin/sales-representatives/{salesRepresentative}/send-login', [SalesRepresentativeController::class, 'sendLoginDetails'])->middleware('superadmin.access:sales,owner');
    Route::get('/superadmin/sales-leads', [SalesRepresentativeController::class, 'allLeads'])->middleware('superadmin.access:sales,owner');
    Route::patch('/superadmin/sales-leads/{lead}/stage', [SalesRepresentativeController::class, 'updateLeadStage'])->middleware('superadmin.access:sales,owner');
    Route::post('/superadmin/sales-leads/{lead}/convert', [SalesRepresentativeController::class, 'convertLeadToSchool'])->middleware('superadmin.access:sales,owner');
    Route::patch('/superadmin/sales-commissions/{commission}/status', [SalesRepresentativeController::class, 'updateCommissionStatus'])->middleware('superadmin.access:finance,owner');
    Route::get('/superadmin/sales-payouts', [SalesPayoutController::class, 'index'])->middleware('superadmin.access:finance,owner');
    Route::get('/superadmin/sales-payout-policy', [SalesPayoutController::class, 'policy'])->middleware('superadmin.access:finance,owner');
    Route::put('/superadmin/sales-payout-policy', [SalesPayoutController::class, 'updatePolicy'])->middleware('superadmin.access:owner');
    Route::get('/superadmin/sales-payouts/representatives', [SalesPayoutController::class, 'representatives'])->middleware('superadmin.access:finance,owner');
    Route::post('/superadmin/sales-payouts/approve-eligible', [SalesPayoutController::class, 'approveEligible'])->middleware('superadmin.access:finance,owner');
    Route::post('/superadmin/sales-payouts/monthly', [SalesPayoutController::class, 'createMonthly'])->middleware('superadmin.access:finance,owner');
    Route::post('/superadmin/sales-representatives/{salesRepresentative}/bank', [SalesPayoutController::class, 'saveBank'])->middleware('superadmin.access:finance,owner');
    Route::post('/superadmin/sales-representatives/{salesRepresentative}/commissions/approve', [SalesPayoutController::class, 'approvePending'])->middleware('superadmin.access:finance,owner');
    Route::post('/superadmin/sales-representatives/{salesRepresentative}/payouts', [SalesPayoutController::class, 'createBatch'])->middleware('superadmin.access:finance,owner');
    Route::post('/superadmin/sales-payouts/{batch}/initiate', [SalesPayoutController::class, 'initiate'])->middleware('superadmin.access:finance,owner');
    Route::post('/superadmin/sales-payouts/{batch}/mark-paid', [SalesPayoutController::class, 'markPaid'])->middleware('superadmin.access:finance,owner');
    Route::get('/sales/workspace', [SalesRepresentativeController::class, 'workspace']);
    Route::get('/sales/leads', [SalesRepresentativeController::class, 'myLeads']);
    Route::post('/sales/leads', [SalesRepresentativeController::class, 'storeMyLead']);
    Route::get('/sales/commissions', [SalesRepresentativeController::class, 'myCommissions']);
    Route::get('/sales/payout-profile', [SalesPayoutController::class, 'myProfile']);
    Route::post('/sales/payout-profile/bank', [SalesPayoutController::class, 'saveMyBank']);
    Route::get('/superadmin/billing-policy', [GradequestBillingPolicyController::class, 'index'])->middleware('superadmin.access:billing,finance,owner');
    Route::put('/superadmin/billing-policy', [GradequestBillingPolicyController::class, 'update'])->middleware('superadmin.access:owner');
    Route::get('/superadmin/billing-policy/schools', [GradequestBillingPolicyController::class, 'schools'])->middleware('superadmin.access:billing,finance,owner');
    Route::get('/superadmin/billing-periods', [GradequestBillingPolicyController::class, 'billingPeriods'])->middleware('superadmin.access:billing,finance,owner');
    Route::post('/superadmin/billing-periods/sync-current', [GradequestBillingPolicyController::class, 'syncSchoolCurrentBillingPeriod'])->middleware('superadmin.access:billing,owner');
    Route::put('/superadmin/billing-periods/{billingPeriod}', [GradequestBillingPolicyController::class, 'updateBillingPeriod'])->middleware('superadmin.access:billing,owner');
    Route::post('/superadmin/billing-temporary-access', [GradequestBillingPolicyController::class, 'grantTemporaryAccess'])->middleware('superadmin.access:billing,owner');
    Route::delete('/superadmin/billing-temporary-access/{temporaryAccess}', [GradequestBillingPolicyController::class, 'revokeTemporaryAccess'])->middleware('superadmin.access:billing,owner');
    Route::get('/superadmin/twilio-whatsapp/status', [SuperAdminTwilioController::class, 'status'])->middleware('superadmin.access:support,owner');
    Route::post('/superadmin/twilio-whatsapp/test', [SuperAdminTwilioController::class, 'test'])->middleware('superadmin.access:support,owner');
    
      Route::post('/create-blog', [BlogController::class, 'store'])->middleware('superadmin.access:content,marketing,owner');
       Route::get('/blogs', [BlogController::class, 'index'])->middleware('superadmin.access:content,marketing,owner');
       Route::get('/edit-blog/{id}', [BlogController::class, 'edit'])->middleware('superadmin.access:content,marketing,owner');
    Route::post('/update-blog/{id}', [BlogController::class, 'update'])->middleware('superadmin.access:content,marketing,owner');
    Route::delete('/blogs/{id}', [BlogController::class, 'destroy'])->middleware('superadmin.access:content,owner');
   

  //whatsapp settings route

    Route::get('/settings/whatsapp',             [WhatsAppSettingsController::class, 'show']);
    Route::post('/settings/whatsapp/toggle',     [WhatsAppSettingsController::class, 'toggle'])
        ->middleware('subscription.feature:whatsapp_notifications');
    Route::post('/settings/whatsapp/test',       [WhatsAppSettingsController::class, 'test'])
        ->middleware('subscription.feature:whatsapp_notifications');
    Route::get('/settings/whatsapp/queue-stats', [WhatsAppSettingsController::class, 'queueStats']);

    Route::post('/whatsapp/broadcast/results',       [WhatsAppBroadcastController::class, 'broadcastResults'])
        ->middleware('subscription.feature:whatsapp_notifications,usage');
    Route::post('/whatsapp/broadcast/fee-reminders', [WhatsAppBroadcastController::class, 'broadcastFeeReminders'])
        ->middleware('subscription.feature:whatsapp_notifications,usage');
    Route::post('/whatsapp/broadcast/custom',        [WhatsAppBroadcastController::class, 'customBroadcast'])
        ->middleware('subscription.feature:whatsapp_notifications,usage');






     Route::get   ('/settings/domain',         [SchoolDomainController::class, 'show']);
    Route::post  ('/settings/domain',         [SchoolDomainController::class, 'register']);
    Route::post  ('/settings/domain/verify',  [SchoolDomainController::class, 'verify']);
    Route::delete('/settings/domain/{id}',    [SchoolDomainController::class, 'remove']);

     // School admin endpoints (uses authenticated user's school_id)
  Route::get('/school/bank-accounts', [SchoolBankAccountController::class, 'index']);
  Route::post('/school/bank-accounts', [SchoolBankAccountController::class, 'store']);
  Route::put('/school/bank-accounts/{id}', [SchoolBankAccountController::class, 'update']);
  Route::delete('/school/bank-accounts/{id}', [SchoolBankAccountController::class, 'destroy']);
  Route::get('/banks', [SchoolBankAccountController::class, 'banks']);
  Route::get('/bank-account/verify', [SchoolBankAccountController::class, 'verifyAccount']);

  Route::get('/school/billing/dashboard', [SchoolBillingController::class, 'dashboard']);
  Route::get('/school/billing/settings', [SchoolBillingController::class, 'settings']);
  Route::put('/school/billing/settings', [SchoolBillingController::class, 'updateSettings']);
  Route::post('/school/billing/offline-invoice/generate', [SchoolBillingController::class, 'generateOfflineInvoice']);
  Route::post('/school/billing/offline-invoices/{invoice}/payments', [SchoolBillingController::class, 'recordInvoicePayment']);
  Route::get('/school/billing/invoices', [SchoolBillingController::class, 'invoices']);
  Route::get('/school/billing/audits', [SchoolBillingController::class, 'audits']);
  Route::get('/school/billing/invoices/{invoice}/payment', [GradequestInvoicePaymentController::class, 'show']);
  Route::post('/school/billing/invoices/{invoice}/payment/initialize', [GradequestInvoicePaymentController::class, 'initialize']);
  Route::get('/school/billing/invoice-payments/verify/{reference}', [GradequestInvoicePaymentController::class, 'verify']);

  // For parent dashboard: fetch school active bank accounts
  Route::get('/schools/{schoolId}/bank-accounts', [SchoolBankAccountController::class, 'activeForSchool']);



Route::get('/parent/students/{studentId}/fees', [ParentStudentFeesController::class, 'show']);
Route::get('/parent/students/{studentId}/bank-accounts', [ParentStudentFeesController::class, 'bankAccounts']);


Route::get('/parent/payments/summary', [ParentStudentFeesController::class, 'summary']); // ?reg_no=...
Route::get('/parent/payments/history', [ParentStudentFeesController::class, 'history']); // ?reg_no=...&term_id=&session_id=&fee_status=
     Route::get('/financial-report', [SchoolFeesReportController::class, 'schoolFinancialReport']);
     Route::get('/filters', [SchoolFeesReportController::class, 'getFilters']);

  // Get all bursars
    Route::get('/bursars', [BusarController::class, 'index']);

    // Register / create a bursar
    Route::post('/bursars', [BusarController::class, 'register']);

    // Get a single bursar
    Route::get('/bursars/{id}', [BusarController::class, 'show']);

    // Update a bursar
     Route::get('/bursars/{id}/edit', [BusarController::class, 'edit']);
    Route::put('/bursars/{id}', [BusarController::class, 'update']);

    // Delete a bursar
    Route::delete('/bursars/{id}', [BusarController::class, 'destroy']);
    Route::post('/bursars/decrypt-password', [BusarController::class, 'decryptPassword']);


    Route::get('/bursar-dashboard/summary', [BursarDashboardController::class, 'summary']);
Route::get('/bursar-dashboard/recent-payments', [BursarDashboardController::class, 'recentPayments']);
Route::get('/bursar-dashboard/payment-method-breakdown', [BursarDashboardController::class, 'paymentMethodBreakdown']);
Route::get('/bursar-dashboard/bank-details', [BursarDashboardController::class, 'bankDetails']);

Route::post('/parents/register', [ParentController::class, 'register']);
    Route::get('/parents/import/template', [ParentController::class, 'importTemplate']);
    Route::post('/parents/import/preview', [ParentController::class, 'previewImport']);
    Route::post('/parents/import', [ParentController::class, 'importParents']);
    Route::get('/parents', [ParentController::class, 'allParents']);
    Route::post('/parents/assign-child', [ParentController::class, 'assignChild']);
    Route::post('/parents/{parentId}/validate-phone', [ParentContactValidationController::class, 'validatePhone']);
    Route::post('/parents/{parentId}/whatsapp/send-code', [ParentContactValidationController::class, 'sendWhatsappCode']);
    Route::post('/parents/{parentId}/whatsapp/verify-code', [ParentContactValidationController::class, 'verifyWhatsappCode']);
    Route::get('/parents/{parentId}', [ParentController::class, 'viewParent']);
    Route::delete('/parents/remove-child', [ParentController::class, 'removeChild']);
    Route::get('/student-classes', [ParentController::class, 'getStudentClasses']);
    Route::delete('/delete-parent/{id}', [ParentController::class, 'destroy']);
Route::post('/students/by-classes', [ParentController::class, 'getByClasses']);
// Parent Edit & Update Routes
Route::get('/parents/{id}/edit', [ParentController::class, 'edit']);
Route::put('/parents/{id}', [ParentController::class, 'update']);
Route::get('/parent/children/{id}', [ParentController::class, 'getChild']);
  Route::get('/parent/children', [ParentController::class, 'myChildren']);



//route for attendace


Route::get('/attendance', [AttendanceController::class, 'index']);  
Route::get('/attendance/classes', [AttendanceController::class, 'classes']);
Route::post('/attendance', [AttendanceController::class, 'store'])
    ->middleware(['teacher.active', 'subscription.feature:attendance_management']);  
Route::get('/student-report', [AttendanceController::class, 'report']);



Route::post('/timetable/generate', [TimetableController::class, 'generate']);
 Route::post('/timetable/generate', [TimetableController::class, 'generate']);
    Route::get('/timetable/recent', [TimetableController::class, 'getRecentTimetable']);
    
    Route::get('/all-result-pins', [ResultPinController::class, 'index']);
    Route::post('/result-pins', [ResultPinController::class, 'store']);
    Route::put('/result-pins/{pin}', [ResultPinController::class, 'update']);
Route::delete('/result-pins/{pin}', [ResultPinController::class, 'destroy']);
  Route::get('/get-terms', [ResultPinController::class, 'getTerms']);
    Route::get('/get-academic-sessions', [ResultPinController::class, 'getSessions']);





//route for adding schools logo in the frontend
Route::get('/school-logos', [SchoolLogoController::class, 'getLogos']);
Route::post('/school-logos', [SchoolLogoController::class, 'saveLogo']);

//route for testimonial

Route::post('/testimonials', [TestimonialController::class, 'store']);
Route::delete('/testimonials/{id}', [TestimonialController::class, 'destroy']);

 Route::get('/payment-gateway', [PaymentGatewayController::class, 'show']);
    Route::post('/payment-gateway', [PaymentGatewayController::class, 'store']);
Route::get('/payment-url/{school_id}', [PaymentGatewayController::class, 'getGatewayForSchool']);


Route::post('/upload-receipts', [ReceiptController::class, 'uploadReceipts']);
Route::get('/my-receipts', [ReceiptController::class, 'myReceipts']); 
 Route::get('/receipts', [ReceiptController::class, 'listReceipts']);
    Route::put('/payment-status/{id}', [ReceiptController::class, 'updateStatus']);
Route::get('/school/{schoolId}/account-details', [ReceiptController::class, 'getAccountDetails']);



     



    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/users/{id}/toggle-status', [AuthController::class, 'toggleStatus']);
     Route::get('/bursar-dashboard', [AdminDashboardController::class, 'getBursarStats']);
    //resources for the dashboard
    Route::get('/dashboard/counts', [AdminDashboardController::class, 'getDashboardCounts']);
    Route::get('/payment-status', [AdminDashboardController::class, 'getPaymentStatus']);
    Route::get('/school-setting', [AdminDashboardController::class, 'schoolDomain']);
    Route::get('/performance-stats', [AdminDashboardController::class, 'getPerformanceStats']);
    Route::get('/top-performing-students', [AdminDashboardController::class, 'getTopPerformingStudents']);
    Route::get('/current-session-term', [AdminDashboardController::class, 'getCurrentSessionAndTerm']);
    // routes/api.php
Route::get('/parent-stats', [AdminDashboardController::class, 'parentDetails']);

    Route::prefix('cbt')->group(function () {
        Route::get('/exams', [CbtExamController::class, 'index'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::post('/exams', [CbtExamController::class, 'store'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::get('/exams/{exam}', [CbtExamController::class, 'show'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::put('/exams/{exam}', [CbtExamController::class, 'update'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::delete('/exams/{exam}', [CbtExamController::class, 'destroy'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::post('/exams/{exam}/publish', [CbtExamController::class, 'publish'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::post('/exams/{exam}/close', [CbtExamController::class, 'close'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::post('/exams/{exam}/reopen', [CbtExamController::class, 'reopen'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::get('/exams/{exam}/scores/export', [CbtExamController::class, 'exportScores'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::get('/exams/{exam}/questions/template/{format}', [CbtExamController::class, 'downloadQuestionTemplate'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::post('/exams/{exam}/questions/images', [CbtExamController::class, 'uploadQuestionImage'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::post('/exams/{exam}/sections', [CbtExamController::class, 'storeSection'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::post('/exams/{exam}/question-groups', [CbtExamController::class, 'storeGroup'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::post('/exams/{exam}/questions', [CbtExamController::class, 'storeQuestion'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::post('/exams/{exam}/questions/import', [CbtExamController::class, 'importQuestions'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::post('/exams/{exam}/questions/import-word', [CbtExamController::class, 'importWordQuestions'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::get('/offline/installer/download', [CbtExamController::class, 'downloadOfflineInstaller'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_offline']);
        Route::put('/questions/{question}', [CbtExamController::class, 'updateQuestion'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::delete('/questions/{question}', [CbtExamController::class, 'deleteQuestion'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);
        Route::post('/attempts/{attempt}/reset', [CbtExamController::class, 'resetAttempt'])
            ->middleware(['teacher.active', 'subscription.feature:cbt_online']);

        Route::get('/student/exams', [CbtStudentExamController::class, 'available'])
            ->middleware('subscription.feature:cbt_online');
        Route::post('/student/exams/{exam}/start', [CbtStudentExamController::class, 'start'])
            ->middleware('subscription.feature:cbt_online');
        Route::post('/student/attempts/{attempt}/answers', [CbtStudentExamController::class, 'saveAnswer'])
            ->middleware('subscription.feature:cbt_online');
        Route::post('/student/attempts/{attempt}/events', [CbtStudentExamController::class, 'logEvent'])
            ->middleware('subscription.feature:cbt_online');
        Route::post('/student/attempts/{attempt}/submit', [CbtStudentExamController::class, 'submit'])
            ->middleware('subscription.feature:cbt_online');

        Route::get('/offline/licenses', [CbtOfflineLicenseController::class, 'index'])
            ->middleware('subscription.feature:cbt_offline');
        Route::post('/offline/licenses', [CbtOfflineLicenseController::class, 'generate'])
            ->middleware('subscription.feature:cbt_offline');
        Route::post('/offline/licenses/{license}/revoke', [CbtOfflineLicenseController::class, 'revoke'])
            ->middleware('subscription.feature:cbt_offline');
        Route::get('/offline/licenses/{license}/bundle', [CbtOfflineLicenseController::class, 'exportBundle'])
            ->middleware('subscription.feature:cbt_offline');
        Route::post('/offline/licenses/{license}/sync-results', [CbtOfflineLicenseController::class, 'syncResults'])
            ->middleware('subscription.feature:cbt_offline');
    });


    Route::prefix('teacher')->group(function () {
        Route::get('/dashboard/counts', [TeacherDashboardController::class, 'counts']);
        Route::get('/performance-stats', [TeacherDashboardController::class, 'performanceStats']);
        Route::get('/access-stats', [TeacherDashboardController::class, 'accessStats']);
        Route::get('/action-center', [TeacherDashboardController::class, 'actionCenter']);
        Route::get('/student-performance', [TeacherDashboardController::class, 'studentPerformance']);
    });


    //end of dashboard reasources
    Route::get('/parent/dashboard', [ParentDashboardController::class, 'dashboard']);

    //route for profile

    Route::get('/user-profile', [ProfileController::class, 'show']);
    Route::put('/user-profile/update', [ProfileController::class, 'update']);
    Route::post('/user/update-password', [ProfileController::class, 'updatePassword']);
    Route::get('/student/my-subjects', [StudentController::class, 'mySubjects']);
    //resouces for students
    Route::get('/all-students', [StudentController::class, 'AllStudents']);
    Route::get('/students/import/template', [StudentController::class, 'studentImportTemplate']);
    Route::post('/students/import/preview', [StudentController::class, 'previewStudentImport']);
    Route::post('/students/import', [StudentController::class, 'importStudents'])
        ->middleware('subscription.feature:student_management,students');
    Route::get('/students/show/{id}', [StudentController::class, 'ViewStudent']);
    Route::patch('/students/{id}/lifecycle-status', [StudentController::class, 'updateStudentLifecycleStatus']);
    Route::post('/student/delete/{id}', [StudentController::class, 'DeleteStudent']);

    Route::Post('/students/store', [StudentController::class, 'StoreAllStudent'])
        ->middleware('subscription.feature:student_management,students');
    Route::delete('/students/{id}', [StudentController::class, 'DeleteStudent']);
    Route::get('/students/edit/{id}', [StudentController::class, 'EditStudents']);
    Route::put('/students/update/{id}', [StudentController::class, 'UpdateStudent']);
    Route::get('/students/{id}/performance', [StudentController::class, 'getStudentPerformance']);

   
     Route::get('/student-fees', [StudentFeeController::class, 'index']);
    Route::post('/fees/pay', [FeePaymentController::class, 'payFee']);
    // Route::get('/student-fees/search/{reg_no}', [StudentFeeController::class, 'searchByRegNo']);
//   Route::get('/student/my-fees', [StudentFeeController::class, 'MyFee']);

  Route::get('/student/my-fees', [StudentFeeController::class, 'MyFee']);

    // new student dashboard
    // Route::get('/student/dashboard', [StudentDashboardController::class, 'dashboard']);
    Route::get('/student/dashboard', [StudentDashboardController::class, 'dashboard']);
// Route::get('/school/{school}/fee-info', [StudentFeeController::class, 'feeInfo']);

 Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index'])->middleware('superadmin.access:billing,owner');
    Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store'])->middleware('superadmin.access:billing,finance,owner');
    Route::put('/subscription-plans/{id}', [SubscriptionPlanController::class, 'update'])->middleware('superadmin.access:billing,finance,owner');
    Route::delete('/subscription-plans/{id}', [SubscriptionPlanController::class, 'destroy'])->middleware('superadmin.access:billing,finance,owner');


 //endstudent route
 
 //Route for SectionController
 Route::prefix('sections')->group(function () {
    Route::get('/', [SectionController::class, 'index']);
    Route::post('/', [SectionController::class, 'store']);
    Route::post('/{id}/restore', [SectionController::class, 'restore']);
    Route::get('/{id}', [SectionController::class, 'show']);
    Route::put('/{id}', [SectionController::class, 'update']);
    Route::delete('/{id}', [SectionController::class, 'destroy']);
});
//end Route
    Route::get('/student-class', [StudentController::class, 'Level']);
    Route::get('/all-sections', [StudentController::class, 'Section']);
    Route::get('/student-department', [StudentController::class, 'Department']);

    //route for affective and psychomotor domain
    Route::post('/save-ratings', [StudentController::class, 'saveRatings']);
    Route::post('/decrypt-password', [StudentController::class, 'decryptPassword']);

    //Financial reports route
       Route::prefix('finance')->group(function () {

        Route::get('/dashboard', [FinanceDashboardController::class, 'index']);

        Route::apiResource('records', FinancialRecordController::class)
            ->middleware(['store' => 'subscription.feature:finance_management']);
            Route::get('/reports/income', [FinanceDashboardController::class, 'generateIncomeReport']);
    Route::get('/reports/income/export', [FinanceDashboardController::class, 'exportIncomeReport']);
    Route::apiResource('categories', FinancialCategoryController::class)
        ->only(['index', 'store', 'destroy'])
        ->middleware(['store' => 'subscription.feature:finance_management']);
        
    });



     // Batches
  Route::post('/result-batches/resolve', [ResultBatchController::class, 'resolve'])->middleware('teacher.active');
  Route::get('/result-batches/{batch}', [ResultBatchController::class, 'show']);
  Route::post('/result-batches/{batch}/compute', [ResultBatchController::class, 'compute']);
  Route::get('/result-batches/{batch}/result-import/template', [ResultBatchController::class, 'resultImportTemplate'])->middleware('teacher.active');
  Route::post('/result-batches/{batch}/result-import/preview', [ResultBatchController::class, 'previewResultImport'])->middleware('teacher.active');
  Route::post('/result-batches/{batch}/result-import/import', [ResultBatchController::class, 'importResults'])->middleware('teacher.active');
  Route::get('/result-batches/{batch}/review-summary', [ResultBatchController::class, 'reviewSummary']);
  Route::post('/result-batches/{batch}/approve', [ResultBatchController::class, 'approve']);
  Route::post('/result-batches/{batch}/publish', [ResultBatchController::class, 'publish']);
  Route::post('/result-batches/{batch}/reopen', [ResultBatchController::class, 'reopen']);
      Route::get('/result-batches/{batch}/students', [ResultBatchController::class, 'students']);
// routes/api.php











  // Student results in a batch
  Route::post('/result-batches/{batch}/students/{student}/upsert', [StudentResultController::class, 'upsert'])->middleware('teacher.active');
  Route::get('/result-batches/{batch}/students/{student}', [StudentResultController::class, 'showInBatch']);
     Route::get('/result-batches/{batch}/students/{student}/result-form', [ResultBatchController::class, 'resultForm'])->middleware('teacher.active');
  // Report card (v2-first, legacy fallback)
  Route::get('/result-template-settings', [ResultTemplateSettingController::class, 'show']);
  Route::put('/result-template-settings', [ResultTemplateSettingController::class, 'update']);
  Route::get('/report-card', [StudentResultController::class, 'reportCard']);
  // routes/api.php
Route::get('/students/{student}/carry-over-preview', [StudentResultController::class, 'carryOverPreview']);

  Route::get('/search-stu/{admissionNo}', [StudentResultController::class, 'findByAdmissionNo']);
  

    Route::get('/result-batches/{batch}/broadsheet', [BroadsheetV2Controller::class, 'index']);
    Route::post('/result-batches/{batch}/broadsheet/compute', [BroadsheetV2Controller::class, 'compute']);
    Route::get('/result-batches/{batch}/broadsheet/export', [BroadsheetV2Controller::class, 'export']);
    Route::get('/result-batches/{batch}/broadsheet/students/{student}', [BroadsheetV2Controller::class, 'student']);


    Route::get('/levels', [StudentClassController::class, 'index']);
    Route::post('/levels', [StudentClassController::class, 'store']);
    Route::post('/levels/{id}/restore', [StudentClassController::class, 'restore']);
    Route::get('/levels/{id}', [StudentClassController::class, 'show']);
    Route::put('/levels/{id}', [StudentClassController::class, 'update']);

    Route::delete('/levels/{id}', [StudentClassController::class, 'destroy']);

    //student result rout
    Route::get('/results', [ResultController::class, 'AllResults']);

    



    Route::get('/search-student/{admissionNo}', [ResultController::class, 'findByAdmissionNo']);
    Route::post('/results/store', [ResultController::class, 'saveResult']);
    // Fix route name to match frontend call
    Route::get('/edit-result/{studentId}/{session}/{classId}/{term}', [ResultController::class, 'getResultDataByStudent'])
        ->where('session', '.*')
        ->where('term', '.*');
    


    Route::put('/update-result/{studentId}/{session}/{classId}/{term}', [ResultController::class, 'updateStudentResult'])
        ->where('session', '.*')
        ->where('term', '.*');
    Route::delete('/delete-result/{studentId}/{session}/{classId}/{term}', [ResultController::class, 'deleteResult'])
        ->where('session', '.*')
        ->where('term', '.*');

    Route::get('/classes', [ResultController::class, 'getClasses']);
   
    
    Route::get('/broadsheet/fetch', [ResultController::class, 'fetchBroadsheet']);

    Route::get('/broadsheet/options', [ResultController::class, 'broadsheetOptions']);





    Route::put('/results/{id}', [ResultController::class, 'update']);

    //route for all department
    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::post('/departments', [DepartmentController::class, 'store'])
        ->middleware('subscription.feature:settings_management');
    Route::post('/departments/{id}/restore', [DepartmentController::class, 'restore']);
    Route::get('/departments/{id}', [DepartmentController::class, 'show']);
    Route::put('/departments/{id}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{id}', [DepartmentController::class, 'destroy']);

    //route for subjects
    Route::get('/departments/{id}/subjects', [SubjectController::class, 'getAllSubjects']);
    Route::post('/subjects/assign-section', [SubjectController::class, 'assignSection']);

Route::get('/sections', [SubjectController::class, 'getSections']);

    Route::post('/departments/{id}/subjects', [SubjectController::class, 'storeSubject'])
        ->middleware('subscription.feature:settings_management');
    Route::get('/subjects/{id}', [SubjectController::class, 'edit']);
    Route::put('/subjects/{id}', [SubjectController::class, 'update']);
    Route::post('/subjects/{id}/restore', [SubjectController::class, 'restore']);

    Route::delete('/subjects/{id}', [SubjectController::class, 'destroy']);


    //Route foracademic sessions
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::post('/sessions', [SessionController::class, 'store'])
        ->middleware('subscription.feature:settings_management');
    Route::post('/sessions/{id}/restore', [SessionController::class, 'restore']);
    Route::put('/sessions/{id}', [SessionController::class, 'update']);
    Route::get('/sessions/{id}', [SessionController::class, 'show']);
    Route::delete('/sessions/{id}', [SessionController::class, 'destroy']);
    Route::post('/sessions/set-current/{id}', [SessionController::class, 'setCurrent']);


    //route for terms
    Route::get('/terms', [TermController::class, 'index']);
    Route::post('/terms', [TermController::class, 'store'])
        ->middleware('subscription.feature:settings_management');
    Route::get('/terms/{id}', [TermController::class, 'show']);
    Route::put('/terms/{id}', [TermController::class, 'update']);
    Route::put('/terms/{id}/status', [TermController::class, 'updateStatus']);
    Route::post('/terms/{id}/restore', [TermController::class, 'restore']);
    Route::delete('/terms/{id}', [TermController::class, 'destroy']);


        Route::get('/fstudent-classes', [FilterSetupController::class, 'studentClasses']);
    Route::get('/fterms', [FilterSetupController::class, 'terms']);
    Route::get('/facademic-sessions', [FilterSetupController::class, 'academicSessions']);

    // Optional
    Route::get('/fdepartments', [FilterSetupController::class, 'departments']);
    Route::get('/fsections', [FilterSetupController::class, 'sections']);


    //route for promoting student
    Route::get('/getclasses', [StudentController::class, 'getClasses']);
    Route::get('/students-by-class', [StudentController::class, 'getStudentsByClass']);
    Route::post('/promote-students', [StudentController::class, 'promoteStudents']);
   



  

//route for assigning subjects to teacher

Route::get('/teacher-subjects', [TeacherSubjectController::class, 'index']);
Route::get('/subjects', [TeacherSubjectController::class, 'allSubjects']);
Route::get('/teachers', [TeacherSubjectController::class, 'allTeachers']);
Route::post('/teacher-subjects', [TeacherSubjectController::class, 'store'])
    ->middleware('subscription.feature:teacher_management');
Route::delete('/teacher-subjects/{teacher_id}/{subject_id}', [TeacherSubjectController::class, 'destroy']);

//end route

//Route for teachers
Route::post('/register-teacher', [TeacherController::class, 'StoreTeacher'])
    ->middleware('subscription.feature:teacher_management,teachers');
Route::get('/teachers/import/template', [TeacherController::class, 'importTemplate']);
Route::post('/teachers/import/preview', [TeacherController::class, 'previewImport']);
Route::post('/teachers/import', [TeacherController::class, 'importTeachers'])
    ->middleware('subscription.feature:teacher_management,teachers');
Route::get('/all-teachers', [TeacherController::class, 'getAllTeachers']);
Route::patch('/teachers/{id}/lifecycle-status', [TeacherController::class, 'updateLifecycleStatus']);
Route::delete('/teachers/{id}', [TeacherController::class, 'deleteTeacher']);
Route::put('/teachers/{id}', [TeacherController::class, 'updateTeacher']);
Route::get('/teachers/edit/{id}', [TeacherController::class, 'editTeacher']);
Route::get('/teachers/view/{id}', [TeacherController::class, 'viewTeacher']);
//End route




      Route::get('/fee-types', [FeeTypeController::class, 'index']);
    Route::post('/fee-types', [FeeTypeController::class, 'store'])
        ->middleware('subscription.feature:fee_management');
    Route::get('/fee-types/{id}', [FeeTypeController::class, 'show']);
    Route::put('/fee-types/{id}', [FeeTypeController::class, 'update']);
    Route::delete('/fee-types/{id}', [FeeTypeController::class, 'destroy']);


    Route::get('/fees/structure/{sectionId}/{sessionId}', [FeePaymentController::class, 'getFeeStructure']);
     Route::get('/students/search', [StudentController::class, 'search']); 
    Route::post('/fees/assign', [FeePaymentController::class, 'assignStudentFee'])
        ->middleware('subscription.feature:fee_management'); 
  Route::get('/fees/student/details', [FeePaymentController::class, 'studentFeeDetails']);

   Route::post('/fees/fetch-types', [FeePaymentController::class, 'fetchFeeTypes'])
        ->name('fees.fetch.types');

   Route::get('/students/{studentId}/fees', [FeePaymentController::class, 'showAssignedFees']);
Route::delete('/student-fees/{studentFeeId}', [FeePaymentController::class, 'removeAssignedFee']);

    Route::post('/fees/assign', [FeePaymentController::class, 'assignStudentFee'])
        ->middleware('subscription.feature:fee_management')
        ->name('fees.assign');

// Route::post('/fees/initialize', [OnlineFeePaymentController::class, 'initialize']);
//     Route::get('/fees/verify/{reference}', [OnlineFeePaymentController::class, 'verify']);

Route::post('/fees/online/initialize', [FeePaymentController::class, 'initializeOnlinePayment'])
    ->middleware('subscription.feature:online_payment');
Route::get('/fees/online/verify/{reference}', [FeePaymentController::class, 'verifyOnlinePayment']);

Route::post('/paystack/webhook', [FeePaymentController::class, 'paystackWebhook']); // outside auth:sanctum

//Route for app settings
Route::post('/save-settings', [SettingController::class, 'saveSettings'])
    ->middleware('subscription.feature:settings_management');
Route::get('/get-settings', [SettingController::class, 'getSettings']);
 Route::get('/settings/auto-admission-status', [SettingController::class, 'getAutoAdmissionStatus']);

Route::post('/settings/auto-admission', [SettingController::class,  'updateAutoAdmission'])
    ->middleware('subscription.feature:settings_management');
    //Route for checkout
Route::post('/initialize-payment', [WalletController::class, 'initialize']);
Route::get('/verify-payment/{reference}', [WalletController::class, 'verify']);

Route::get('/product/student-slot', [WalletController::class, 'getStudentProduct']);
Route::get('/user/transactions', [WalletController::class, 'userTransactions']);

Route::get('/user/transaction-summary/{id}', [WalletController::class, 'singleTransactionSummary']);

Route::get('/invoice-notifications/unread', [InvoiceNotificationController::class, 'unread']);
Route::get('/invoice-notifications', [InvoiceNotificationController::class, 'index']);
Route::get('/invoice-notifications/{id}', [InvoiceNotificationController::class, 'show']);
Route::post('/invoice-notifications/{id}/read', [InvoiceNotificationController::class, 'markRead']);
Route::get('/invoice-notifications/{id}/bank-details', [InvoiceNotificationController::class, 'bankDetails']);
  Route::get('/analytics/fee-reminders', [FeeReminderAnalyticsController::class, 'summary']);

    // audit logs
    Route::get('/fee-reminders/logs', [FeeReminderAnalyticsController::class, 'reminderLogs']);
//Route for wallet balance
Route::get('/user/wallet', [WalletController::class, 'getUserBalance']);

 Route::get('/settings/fee-reminders', [FeeReminderSettingsController::class, 'show']);
    Route::put('/settings/fee-reminders', [FeeReminderSettingsController::class, 'update']);
Route::get('/settings/fee-access-policy', [FeeAccessPolicyController::class, 'show']);
Route::put('/settings/fee-access-policy', [FeeAccessPolicyController::class, 'update']);

    Route::get('/analytics/fee-reminders', [FeeReminderAnalyticsController::class, 'summary']);



Route::post('/subscription/initialize', [SubscriptionController::class, 'initialize']);

Route::post('/subscription/renewal-source', [SubscriptionController::class, 'updateRenewalSource']);

Route::get('/subscription/verify/{reference}', [SubscriptionController::class, 'verify']);



Route::get('/subscription/user', [SubscriptionController::class, 'profile']);
Route::get('/subscription/billing', [SubscriptionController::class, 'billingHistory']);

Route::get('/subscription/plans', [SubscriptionController::class, 'availablePlans']);
Route::post('/subscription/cancel', [SubscriptionController::class, 'cancelSubscription']);



Route::get('/user/subscription', [SubscriptionController::class, 'getUserSubscription']);

Route::get('/user/subscription/details', [SubscriptionController::class, 'getUserSubscriptionDetails']);
      
      Route::post('/payment/wallet-charge', [SubscriptionController::class, 'walletCharge']);

Route::post('/paystack/webhook', [SubscriptionController::class, 'handleWebhook'])->name('paystack.webhook');

Route::prefix('staff-attendance')->group(function () {
    Route::get('/session', [StaffAttendanceController::class, 'currentSession'])
        ->middleware('subscription.feature:attendance_management');
    Route::post('/session', [StaffAttendanceController::class, 'generateSession'])
        ->middleware('subscription.feature:attendance_management');
    Route::post('/mark', [StaffAttendanceController::class, 'mark'])
        ->middleware(['teacher.active', 'subscription.feature:attendance_management']);
     Route::get('/logs', [StaffAttendanceController::class, 'logs']);
});
Route::get('/attendance-settings', [AttendanceSettingController::class, 'show']);
Route::put('/attendance-settings', [AttendanceSettingController::class, 'update']);










});



   
// Route::middleware(['customdomain'])->group(function () {
//     // Routes that need custom domain resolution
//     Route::post('/login', [AuthController::class, 'login']);
// });


Route::middleware(['auth:sanctum', 'tenant'])->get('/user', function (Request $request) {
    $user = $request->user();

    return array_merge($user->toArray(), [
        'super_admin_type_label' => $user->isSuperAdminUser() ? $user->superAdminTypeLabel() : null,
        'super_admin_permissions' => $user->superAdminPermissions(),
    ]);
});



Route::middleware(['auth:sanctum', 'tenant'])->post('/terms/bulk-create', [TermController::class, 'bulkCreate']);
// Route::post('/paystack/webhook', [OnlineFeePaymentController::class, 'webhook']);
