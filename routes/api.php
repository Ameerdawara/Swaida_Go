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

/*
|--------------------------------------------------------------------------
| API Routes - Swaida Go
|--------------------------------------------------------------------------
*/

// -----------------------------------------------------------
// 1. المسارات العامة (Public Routes)
// -----------------------------------------------------------

// مسارات المصادقة والتوثيق
Route::post('/register', [AuthController::class, 'register']);     // تسجيل حساب جديد
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']); // توثيق الهاتف عبر OTP
Route::post('/login', [AuthController::class, 'login']);           // تسجيل الدخول

// مسار استقبال تأكيدات الدفع من البوابات الخارجية (Webhooks)[cite: 1]
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

    // إدارة الاشتراكات[cite: 1]
    Route::prefix('subscription')->group(function () {
        Route::post('/', [SubscriptionController::class, 'store']); // إنشاء اشتراك (سنوي)
        Route::get('/', [SubscriptionController::class, 'show']);   // عرض حالة الـ 12 شهراً
    });

    // عمليات الدفع[cite: 1]
    Route::prefix('payment')->group(function () {
        Route::post('/initiate', [PaymentController::class, 'initiate']); // بدء عملية دفع لأشهر محددة
    });


    // -----------------------------------------------------------
    // 3. مسارات الإدارة - للمدير فقط (Admin Only)
    // -----------------------------------------------------------
    
    // نستخدم "can:admin-only" التي عرفناها في الـ Gate داخل AppServiceProvider[cite: 1]
    Route::middleware('can:admin-only')->prefix('admin')->group(function () {
        
        // عرض أرصدة الصناديق[cite: 1]
        Route::get('/app-fund', [AppFundController::class, 'index']);      // رصيد التطبيق الإلكتروني
        Route::get('/charity-fund', [CharityFundController::class, 'index']); // رصيد الجمعية المحول

        // إدارة تحويلات الأموال[cite: 1]
        Route::post('/transfer', [FundTransferController::class, 'store']);        // إجراء تحويل جديد للجمعية
        Route::get('/transfers-history', [FundTransferController::class, 'index']); // سجل جميع التحويلات
        
    });
    Route::post('/update-fcm-token', [AuthController::class, 'updateFcmToken']);
    // مسار جلب العمولة (متاح للمستخدمين والمدير لكي يعرضها التطبيق أثناء الدفع)
    
    Route::get('/settings/commission', [SettingController::class, 'getCommission']);

    // مسارات المدير (Admin Only)
    Route::middleware('IsAdmin')->prefix('admin')->group(function () {
        
        // مسار تعديل العمولة (خاص بالمدير فقط)
        Route::post('/settings/commission', [SettingController::class, 'updateCommission']);
        
    });
});