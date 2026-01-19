<?php

use App\Http\Controllers\ActivationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Backend\DashboardController;
use App\Http\Controllers\Backend\DepartmentController;
use App\Http\Controllers\Backend\ProductController;
use App\Http\Controllers\Backend\ResultController;
use App\Http\Controllers\Backend\SessionController;
use App\Http\Controllers\Backend\StudentClassController;
use App\Http\Controllers\Backend\StudentController;
use App\Http\Controllers\Backend\SubjectController;
use App\Http\Controllers\Backend\SuperAdminController;
use App\Http\Controllers\Backend\TeacherController;
use App\Http\Controllers\Backend\AttendanceController;
use App\Http\Controllers\Backend\TermController;
use App\Http\Controllers\Backend\TestimonialController;
use App\Http\Controllers\Backend\BlogController;
// use App\Http\Controllers\Backend\QrcodeController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SchoolLogoController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\WalletController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use App\Http\Controllers\Backend\TimetableController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Response;
use App\Http\Controllers\QrController;
use App\Http\Controllers\Backend\BiometricQRController;
use App\Http\Controllers\Backend\TeacherSubjectController;
use App\Http\Controllers\Backend\SectionController;
use App\Http\Controllers\Backend\FeePaymentController;
use App\Http\Controllers\Backend\FeeTypeController;
use App\Http\Controllers\Backend\StudentFeeController;
use App\Http\Controllers\Backend\SubscriptionPlanController;
use App\Http\Controllers\Backend\SubscriptionController;
use App\Http\Controllers\Backend\PaymentGatewayController;
use App\Http\Controllers\Backend\ParentController;
use App\Http\Controllers\Backend\FinancialReportController;
use App\Http\Controllers\Backend\ReceiptController;
use App\Http\Controllers\Backend\BusarController;
use App\Http\Controllers\Backend\PublicResultController;
use App\Http\Controllers\Backend\ResultPinController;








Route::middleware('guest')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/check-subdomain/{subdomain}', [AuthController::class, 'checkSubdomain']);
    Route::post('/forgot-password', [AuthController::class, 'sendAdminResetLink']);
Route::post('/reset-password', [AuthController::class, 'resetAdminPassword']);
Route::post('/send-contact', [ContactController::class, 'send']);
Route::get('/testimonials', [TestimonialController::class, 'index']);

Route::get('/frontend/subscription-plans', function () {
    return \App\Models\SubscriptionPlan::where('is_active', 1)->get();
    });

     Route::post('/verify-result', [ResultController::class, 'verifyResult']);
  Route::get('/frontend-blogs', [BlogController::class, 'index']);
   Route::get('/blog/{slug}', [BlogController::class, 'showBlogs']);
   
  Route::get('/public/active-term-session', [PublicResultController::class, 'getActiveTermSession']);



 Route::get('/result/{studentId}', [ResultController::class, 'showStudentResult']);
 
Route::get('/public/check-result', [PublicResultController::class, 'checkWithPin']);
 
     Route::get('/result/{studentId}', [ResultController::class, 'showStudentResult']);
});





Route::middleware('auth:sanctum')->group(function () {
    
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
        ->unreadNotifications() // unread only
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
        ->notifications() // all notifications, read + unread
        ->take(50) // or whatever limit you want
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





    //route for super-admin
    Route::get('/admin-users', [SuperAdminController::class, 'getAdminUsers']);
    Route::get('/admin-users/view/{id}', [SuperAdminController::class, 'showAdmin']);
    Route::get('/user/features', [SuperAdminController::class, 'getUserFeatures']);
    Route::get('/admin/subscriptions', [SuperAdminController::class, 'getSubscribers']);

    Route::get('/admin-users/{id}', [SuperAdminController::class, 'edit']);
    Route::put('/admin-users/{id}', [SuperAdminController::class, 'update']);
    Route::delete('/admin-users/{id}', [SuperAdminController::class, 'destroy']);
    Route::get('/platform-logs', [SuperAdminController::class, 'getLogs']);
    Route::post('/send-marketing-emails', [SuperAdminController::class, 'sendMarketingEmail']);
    Route::get('/mail/admin-users', [SuperAdminController::class, 'mailAdminUsers']);
    // Route::get('/admin-stats', [SuperAdminController::class, 'getAdminStats']);
    Route::get('/monthly-revenue-stats', [SuperAdminController::class, 'monthlyRevenueStats']);
    Route::post('/platform-logs/delete-multiple', [SuperAdminController::class, 'deleteMultiple']);
    
      Route::post('/create-blog', [BlogController::class, 'store']);
       Route::get('/blogs', [BlogController::class, 'index']);
       Route::get('/edit-blog/{id}', [BlogController::class, 'edit']);
    Route::post('/update-blog/{id}', [BlogController::class, 'update']);
    Route::delete('/blogs/{id}', [BlogController::class, 'destroy']);
   
   
    
     Route::get('/financial-report', [FinancialReportController::class, 'schoolFinancialReport']);
     Route::get('/filters', [FinancialReportController::class, 'getFilters']);

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

  Route::post('/parents/register', [ParentController::class, 'register']);
    Route::get('/parents', [ParentController::class, 'allParents']);
    Route::post('/parents/assign-child', [ParentController::class, 'assignChild']);
    Route::get('/parents/{parentId}', [ParentController::class, 'viewParent']);
    Route::delete('/parents/remove-child', [ParentController::class, 'removeChild']);
    Route::delete('/delete-parent/{id}', [ParentController::class, 'destroy']);
Route::post('/students/by-classes', [ParentController::class, 'getByClasses']);
// Parent Edit & Update Routes
Route::get('/parents/{id}/edit', [ParentController::class, 'edit']);
Route::put('/parents/{id}', [ParentController::class, 'update']);
Route::get('/parent/children/{id}', [ParentController::class, 'getChild']);



//route for attendace


Route::get('/attendance', [AttendanceController::class, 'index']);  
Route::post('/attendance', [AttendanceController::class, 'store']);  
Route::get('/student-report', [AttendanceController::class, 'report']);
Route::get('/all-classes', [AttendanceController::class, 'getClassForStudentReport']);


Route::post('/timetable/generate', [TimetableController::class, 'generate']);
 Route::post('/timetable/generate', [TimetableController::class, 'generate']);
    Route::get('/timetable/recent', [TimetableController::class, 'getRecentTimetable']);
    
    Route::get('/all-result-pins', [ResultPinController::class, 'index']);
    Route::post('/result-pins', [ResultPinController::class, 'store']);
    Route::put('/result-pins/{pin}', [ResultPinController::class, 'update']);
Route::delete('/result-pins/{pin}', [ResultPinController::class, 'destroy']);
  Route::get('/get-terms', [ResultPinController::class, 'getTerms']);
    Route::get('/get-academic-sessions', [ResultPinController::class, 'getSessions']);



//route for product
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::put('/products/{id}', [ProductController::class, 'update']);

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
 Route::get('/receipts', [ReceiptController::class, 'listReceipts']);
    Route::put('/payment-status/{id}', [ReceiptController::class, 'updateStatus']);
Route::get('/school/{schoolId}/account-details', [ReceiptController::class, 'getAccountDetails']);


     



    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/users/{id}/toggle-status', [AuthController::class, 'toggleStatus']);
     Route::get('/bursar-dashboard', [DashboardController::class, 'getBursarStats']);
    //resources for the dashboard
    Route::get('/dashboard/counts', [DashboardController::class, 'getDashboardCounts']);
    Route::get('/payment-status', [DashboardController::class, 'getPaymentStatus']);
    Route::get('/school-setting', [DashboardController::class, 'schoolDomain']);
    Route::get('/performance-stats', [DashboardController::class, 'getPerformanceStats']);
    Route::get('/top-performing-students', [DashboardController::class, 'getTopPerformingStudents']);
    Route::get('/current-session-term', [DashboardController::class, 'getCurrentSessionAndTerm']);
    // routes/api.php
Route::get('/parent-stats', [DashboardController::class, 'parentDetails']);


    //end of dashboard reasources

    //route for profile

    Route::get('/user-profile', [ProfileController::class, 'show']);
    Route::put('/user-profile/update', [ProfileController::class, 'update']);
    Route::post('/user/update-password', [ProfileController::class, 'updatePassword']);
    Route::get('/student/my-subjects', [StudentController::class, 'mySubjects']);
    //resouces for students
    Route::get('/all-students', [StudentController::class, 'AllStudents']);
    Route::get('/students/show/{id}', [StudentController::class, 'ViewStudent']);
    Route::Post('/students/store', [StudentController::class, 'StoreAllStudent']);
    Route::delete('/students/{id}', [StudentController::class, 'DeleteStudent']);
    Route::get('/students/edit/{id}', [StudentController::class, 'EditStudents']);
    Route::put('/students/update/{id}', [StudentController::class, 'UpdateStudent']);
    Route::get('/students/{id}/performance', [StudentController::class, 'getStudentPerformance']);
        
   
     Route::get('/student-fees', [StudentFeeController::class, 'index']);
    Route::post('/fees/pay', [StudentFeeController::class, 'payFee']);
    Route::get('/student-fees/search/{reg_no}', [StudentFeeController::class, 'searchByRegNo']);
  Route::get('/student/my-fees', [StudentFeeController::class, 'MyFee']);

Route::get('/school/{school}/fee-info', [StudentFeeController::class, 'feeInfo']);

 Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
    Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store']);
    Route::put('/subscription-plans/{id}', [SubscriptionPlanController::class, 'update']);
    Route::delete('/subscription-plans/{id}', [SubscriptionPlanController::class, 'destroy']);


 //endstudent route
 
 //Route for SectionController
 Route::prefix('sections')->group(function () {
    Route::get('/', [SectionController::class, 'index']);
    Route::post('/', [SectionController::class, 'store']);
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

    //Route for entering class

    Route::get('/levels', [StudentClassController::class, 'index']);
    Route::post('/levels', [StudentClassController::class, 'store']);
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
    


    Route::put('/update-result/{studentId}/{session}/{classId}/{term}', [ResultController::class, 'updateStudentResult'])->where('session', '.*')
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
    Route::post('/departments', [DepartmentController::class, 'store']);
    Route::get('/departments/{id}', [DepartmentController::class, 'show']);
    Route::put('/departments/{id}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{id}', [DepartmentController::class, 'destroy']);

    //route for subjects
    Route::get('/departments/{id}/subjects', [SubjectController::class, 'getAllSubjects']);
    Route::post('/subjects/assign-section', [SubjectController::class, 'assignSection']);

Route::get('/sections', [SubjectController::class, 'getSections']);

    Route::post('/departments/{id}/subjects', [SubjectController::class, 'storeSubject']);
    Route::get('/subjects/{id}', [SubjectController::class, 'edit']);
    Route::put('/subjects/{id}', [SubjectController::class, 'update']);

    Route::delete('/subjects/{id}', [SubjectController::class, 'destroy']);


    //Route foracademic sessions
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::post('/sessions', [SessionController::class, 'store']);
    Route::put('/sessions/{id}', [SessionController::class, 'update']);
    Route::get('/sessions/{id}', [SessionController::class, 'show']);
    Route::delete('/sessions/{id}', [SessionController::class, 'destroy']);
    Route::post('/sessions/set-current/{id}', [SessionController::class, 'setCurrent']);


    //route for terms
    Route::get('/terms', [TermController::class, 'index']);
    Route::post('/terms', [TermController::class, 'store']);
    Route::get('/terms/{id}', [TermController::class, 'show']);
    Route::put('/terms/{id}', [TermController::class, 'update']);
    Route::put('/terms/{id}/status', [TermController::class, 'updateStatus']);
    Route::delete('/terms/{id}', [TermController::class, 'destroy']);


    //route for promoting student
    Route::get('/getclasses', [StudentController::class, 'getClasses']);
    Route::get('/students-by-class', [StudentController::class, 'getStudentsByClass']);
    Route::post('/promote-students', [StudentController::class, 'promoteStudents']);


  

//route for assigning subjects to teacher

Route::get('/teacher-subjects', [TeacherSubjectController::class, 'index']);
Route::get('/subjects', [TeacherSubjectController::class, 'allSubjects']);
Route::get('/teachers', [TeacherSubjectController::class, 'allTeachers']);
Route::post('/teacher-subjects', [TeacherSubjectController::class, 'store']);
Route::delete('/teacher-subjects/{teacher_id}/{subject_id}', [TeacherSubjectController::class, 'destroy']);

//end route

//Route for teachers
Route::post('/register-teacher', [TeacherController::class, 'StoreTeacher']);
Route::get('/all-teachers', [TeacherController::class, 'getAllTeachers']);
Route::delete('/teachers/{id}', [TeacherController::class, 'deleteTeacher']);
Route::put('/teachers/{id}', [TeacherController::class, 'updateTeacher']);
Route::get('/teachers/edit/{id}', [TeacherController::class, 'editTeacher']);
Route::get('/teachers/view/{id}', [TeacherController::class, 'viewTeacher']);
//End route




Route::prefix('biometric-qr')->group(function () {
    Route::post('/find-teacher', [BiometricQRController::class, 'findTeacher']);
    Route::post('/generate', [BiometricQRController::class, 'generateForTeacher']);
    Route::get('/all', [BiometricQRController::class, 'show']);
Route::delete('/{id}', [BiometricQRController::class, 'destroy']);


});



    
      Route::get('/fee-types', [FeeTypeController::class, 'index']);
    Route::post('/fee-types', [FeeTypeController::class, 'store']);
    Route::get('/fee-types/{id}', [FeeTypeController::class, 'show']);
    Route::put('/fee-types/{id}', [FeeTypeController::class, 'update']);
    Route::delete('/fee-types/{id}', [FeeTypeController::class, 'destroy']);


    Route::get('/fees/structure/{sectionId}/{sessionId}', [FeePaymentController::class, 'getFeeStructure']);
     Route::get('/students/search', [StudentController::class, 'search']); 
    Route::post('/fees/assign', [FeePaymentController::class, 'assignStudentFee']); 
  Route::get('/fees/student/details', [FeePaymentController::class, 'studentFeeDetails']);

   Route::post('/fees/fetch-types', [FeePaymentController::class, 'fetchFeeTypes'])
        ->name('fees.fetch.types');

   Route::get('/students/{studentId}/fees', [FeePaymentController::class, 'showAssignedFees']);
Route::delete('/student-fees/{studentFeeId}', [FeePaymentController::class, 'removeAssignedFee']);

    Route::post('/fees/assign', [FeePaymentController::class, 'assignStudentFee'])
        ->name('fees.assign');





//Route for app settings
Route::post('/save-settings', [SettingController::class, 'saveSettings']);
Route::get('/get-settings', [SettingController::class, 'getSettings']);

Route::post('/settings/auto-admission', [SettingController::class,  'updateAutoAdmission']);
    //Route for checkout
Route::post('/initialize-payment', [WalletController::class, 'initialize']);
Route::get('/verify-payment/{reference}', [WalletController::class, 'verify']);

Route::get('/product/student-slot', [WalletController::class, 'getStudentProduct']);
Route::get('/user/transactions', [WalletController::class, 'userTransactions']);

Route::get('/user/transaction-summary/{id}', [WalletController::class, 'singleTransactionSummary']);

//Route for wallet balance
Route::get('/user/wallet', [WalletController::class, 'getUserBalance']);





Route::post('/subscription/initialize', [SubscriptionController::class, 'initialize']);

Route::post('/subscription/renewal-source', [SubscriptionController::class, 'updateRenewalSource']);

Route::get('/subscription/verify/{reference}', [SubscriptionController::class, 'verify']);



Route::get('/subscription/user', [SubscriptionController::class, 'profile']);

    
   Route::post('/subscription/cancel', [SubscriptionController::class, 'cancelSubscription']);
   
   



Route::get('/user/subscription', [SubscriptionController::class, 'getUserSubscription']);

Route::get('/user/subscription/details', [SubscriptionController::class, 'getUserSubscriptionDetails']);
      
      Route::post('/payment/wallet-charge', [SubscriptionController::class, 'walletCharge']);





});

Route::post('/biometric-validate', [BiometricQRController::class, 'validateCode']);

Route::middleware(['customdomain'])->group(function () {
    // Routes that need custom domain resolution
    Route::get('/login', [AuthController::class, 'login']);
});


Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});



Route::middleware('auth:sanctum')->post('/terms/bulk-create', [TermController::class, 'bulkCreate']);




Route::post('/paystack/webhook', [SubscriptionController::class, 'handleWebhook'])->name('paystack.webhook');



