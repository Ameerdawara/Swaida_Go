<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// استيراد جميع المتحكمات (Controllers)
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\AppFundController;
use App\Http\Controllers\CharityFundController;
use App\Http\Controllers\FundTransferController;
use App\Http\Controllers\SettingController;
use App\Models\User;
/*
|--------------------------------------------------------------------------
| API Routes - Swaida Go
|--------------------------------------------------------------------------
*/

// -----------------------------------------------------------
// 1. المسارات العامة (Public Routes)
// -----------------------------------------------------------

// مسارات المصادقة والتوثيق
Route::post('/register', [AuthController::class, 'register']);     // تسجيل حساب جديد → يُرسل رابط البريد + OTP الهاتف
Route::post('/login', [AuthController::class, 'login']);           // تسجيل الدخول (phone + password)

// ── توثيق البريد الإلكتروني ────────────────────────────────

// رابط التوثيق الذي يصل للمستخدم عبر البريد (signed URL يُولّده Laravel)
// المستخدم يضغطه من بريده → Laravel يضع email_verified_at تلقائياً
Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {

    // 1. البحث عن المستخدم عبر معرفه
    $user = User::findOrFail($id);

    // 2. التحقق من أن الـ Hash المرسل يطابق بريد المستخدم
    if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
        return response()->json(['message' => 'رابط التوثيق غير صالح أو منتهي الصلاحية.'], 403);
    }

    // 3. التحقق إذا كان البريد موثقاً بالفعل
    if ($user->hasVerifiedEmail()) {
        return response()->json(['message' => 'البريد الإلكتروني موثق مسبقاً.'], 200);
    }

    // 4. تنفيذ التوثيق
    if ($user->markEmailAsVerified()) {
        event(new \Illuminate\Auth\Events\Verified($user));
    }

    return response()->json([
        'message' => 'تم توثيق البريد الإلكتروني بنجاح.'
    ], 200);

})->middleware(['signed'])->name('verification.verify');
// Flutter يستدعيه كل 5 ثوانٍ (Polling) لمعرفة هل وثّق المستخدم بريده
// مثال: GET /api/auth/email-verification-status?email=user@example.com
Route::get('/auth/email-verification-status', [AuthController::class, 'checkEmailVerificationStatus']);

// إعادة إرسال رابط التوثيق (زر "إعادة الإرسال" في شاشة EmailVerificationScreen)
Route::post('/auth/resend-email-verification', [AuthController::class, 'resendEmailVerification']);

// ── توثيق رقم الهاتف ───────────────────────────────────────

// يشترط توثيق البريد مسبقاً (Backend يرفض بـ 403 إن لم يكن كذلك)
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);

// إعادة إرسال OTP الهاتف
Route::post('/auth/resend-otp', [AuthController::class, 'resendOtp']);

// مسار استقبال تأكيدات الدفع من البوابات الخارجية (Webhooks)
// ملاحظة: هذا المسار يجب أن يكون مستثنى من حماية CSRF
Route::post('/payment/webhook', [PaymentController::class, 'webhook']);


// -----------------------------------------------------------
// 2. المسارات المحمية - للمشتركين (Authenticated Users)
// -----------------------------------------------------------

Route::middleware('auth:sanctum')->group(function () {

    // إدارة الملف الشخصي
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // تحديث رمز إشعارات Firebase
    Route::post('/update-fcm-token', [AuthController::class, 'updateFcmToken']);

    // تسجيل الخروج
    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'تم تسجيل الخروج بنجاح.'], 200);
    });

    // إدارة الاشتراكات
    Route::prefix('subscription')->group(function () {
        Route::post('/', [SubscriptionController::class, 'store']); // إنشاء اشتراك (سنوي)
        Route::get('/', [SubscriptionController::class, 'show']);   // عرض حالة الـ 12 شهراً
    });

    // عمليات الدفع
    Route::prefix('payment')->group(function () {
        Route::post('/initiate', [PaymentController::class, 'initiate']); // بدء عملية دفع لأشهر محددة
    });

    // مسار جلب العمولة (متاح للمستخدمين والمدير لكي يعرضها التطبيق أثناء الدفع)
    Route::get('/settings/commission', [SettingController::class, 'getCommission']);


    // -----------------------------------------------------------
    // 3. مسارات الإدارة - للمدير فقط (Admin Only)
    // -----------------------------------------------------------

    // نستخدم "can:admin-only" التي عرفناها في الـ Gate داخل AppServiceProvider
    Route::middleware('can:admin-only')->prefix('admin')->group(function () {

        // عرض أرصدة الصناديق
        Route::get('/app-fund', [AppFundController::class, 'index']);         // رصيد التطبيق الإلكتروني
        Route::get('/charity-fund', [CharityFundController::class, 'index']); // رصيد الجمعية المحول

        // إدارة تحويلات الأموال
        Route::post('/transfer', [FundTransferController::class, 'store']);         // إجراء تحويل جديد للجمعية
        Route::get('/transfers-history', [FundTransferController::class, 'index']); // سجل جميع التحويلات

    });

    // مسارات المدير (Admin Only) — IsAdmin middleware
    Route::middleware('IsAdmin')->prefix('admin')->group(function () {

        // مسار تعديل العمولة (خاص بالمدير فقط)
        Route::post('/settings/commission', [SettingController::class, 'updateCommission']);

    });
});
