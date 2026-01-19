// <?php

// use Illuminate\Support\Facades\Route;
// use App\Http\Controllers\Auth\PasswordController;
// use App\Http\Controllers\Auth\NewPasswordController;
// use App\Http\Controllers\Auth\VerifyEmailController;
// use App\Http\Controllers\Auth\ForgotPasswordController;
// use App\Http\Controllers\Auth\RegisteredUserController;
// use App\Http\Controllers\Auth\PasswordResetLinkController;
// use App\Http\Controllers\Auth\ConfirmablePasswordController;
// use App\Http\Controllers\Auth\AuthenticatedSessionController;
// use App\Http\Controllers\Auth\EmailVerificationPromptController;
// use App\Http\Controllers\Auth\EmailVerificationNotificationController;

// Route::middleware('guest')->group(function () {
//     Route::get('register', [RegisteredUserController::class, 'create'])
//         ->name('register');

//     Route::post('register', [RegisteredUserController::class, 'store']);



//     Route::get('login', [AuthenticatedSessionController::class, 'create'])
//         ->name('login');

//     Route::post('login', [AuthenticatedSessionController::class, 'store']);



//     // Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
//     //     ->name('password.email');

//     Route::get('forgot-password', [ForgotPasswordController::class, 'showForgetPasswordForm'])
//         ->name('password.request');

//     // Route to handle the submission of email/registration number
//     Route::post('password/email', [ForgotPasswordController::class, 'sendResetCode'])
//         ->name('password.email');

//     Route::post('/resend-verification-code', [ForgotPasswordController::class, 'resendVerificationCode'])->name('resend_verification_code');
//     // Route to show the reset password form with reset code
//     Route::get('password/reset', [ForgotPasswordController::class, 'showResetForm'])->name('password.resetForm');

//     // Route to handle password reset submission
//     Route::post('password/reset', [ForgotPasswordController::class, 'resetPassword'])->name('password_update');

//     // Route to handle reset code submission and validation
//     Route::post('password/code-verify', [ForgotPasswordController::class, 'verifyResetCode'])->name('password.codeVerify');

//     // Route to display the form for entering the reset code
//     Route::get('password/code', [ForgotPasswordController::class, 'showCodeVerificationForm'])->name('password.codeform');

//     Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
//         ->name('password.reset');

//     Route::post('reset-password', [NewPasswordController::class, 'store'])
//         ->name('password.store');
// });

// Route::middleware('auth')->group(function () {
//     Route::get('verify-email', EmailVerificationPromptController::class)
//         ->name('verification.notice');

//     Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
//         ->middleware(['signed', 'throttle:6,1'])
//         ->name('verification.verify');

//     Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
//         ->middleware('throttle:6,1')
//         ->name('verification.send');

//     Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
//         ->name('password.confirm');

//     Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

//     Route::put('password', [PasswordController::class, 'update'])->name('password.update');

//     Route::get('logout', [AuthenticatedSessionController::class, 'destroy'])
//         ->name('logout');
// });
