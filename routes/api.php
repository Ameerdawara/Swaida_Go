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
use App\Http\Controllers\AdminController;
use App\Models\Payment;
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

// ── Mock payment success — للتطوير المحلي فقط ──────────────
// هذا المسار عام (بدون auth) لأن الـ WebView يفتحه كمتصفح بدون token
if (app()->environment('local')) {
    Route::get('/payment/mock-success', function (Request $request) {
        $paymentIds = array_filter(explode(',', $request->query('payment_ids', '')));

        if (empty($paymentIds)) {
            return response()->json(['message' => 'لا توجد معرّفات دفعات'], 400);
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $payments = \App\Models\Payment::whereIn('id', $paymentIds)
                ->whereNull('paid_at')
                ->get();

            foreach ($payments as $payment) {
                $payment->status              = 'paid';
                $payment->paid_at             = \Carbon\Carbon::now();
                $payment->payment_gateway_ref = 'MOCK-' . strtoupper(\Illuminate\Support\Str::random(8));
                $payment->save();
            }

            $appFund = \App\Models\AppFund::firstOrCreate(['id' => 1]);
            $appFund->balance     += $payments->sum('amount');
            $appFund->last_updated = \Carbon\Carbon::now();
            $appFund->save();

            \Illuminate\Support\Facades\DB::commit();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['message' => 'فشل: ' . $e->getMessage()], 500);
        }

        // redirect لنفس الـ pattern الذي يستمع له PaymentWebViewScreen
        return redirect(url('/api/payment/success?session_id=mock_' . time()));
    });
}
// ───────────────────────────────────────────────────────────


// -----------------------------------------------------------
// 2. المسارات المحمية - للمشتركين (Authenticated Users)
// -----------------------------------------------------------

Route::middleware('auth:sanctum')->group(function () {

    // إدارة الملف الشخصي
    Route::get('/user', function (Request $request) {
        $user = $request->user()->load([
            'subscription.payments',
        ]);

        $monthNames = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
        ];

        return response()->json([
            'id'        => $user->id,
            'full_name' => $user->full_name,
            'email'     => $user->email,
            'phone'     => $user->phone,
            'joined_at' => $user->created_at->toDateString(),

            'subscription' => $user->subscription ? [
                'id'             => $user->subscription->id,
                'annual_amount'  => (float) $user->subscription->annual_amount,
                'monthly_amount' => (float) $user->subscription->monthly_amount,
                'start_date'     => $user->subscription->start_date,

                'months' => $user->subscription->payments->map(fn($p) => [
                    'month_number' => $p->month_number,
                    'month_name'   => ($monthNames[$p->month_number] ?? 'شهر') . ' ' . $p->year,
                    'status'       => Payment::getStatus($p->month_number, $p->year, $p->paid_at),
                    'amount'       => (float) $p->amount,
                    'paid_at'      => $p->paid_at,
                ])->values(),
            ] : null,

            'payment_history' => $user->subscription
                ? $user->subscription->payments
                    ->filter(fn($p) => $p->paid_at !== null)
                    ->map(fn($p) => [
                        'id'             => $p->id,
                        'months'         => [$p->month_number],
                        'amount'         => (float) $p->amount,
                        'paid_at'        => $p->paid_at,
                        'receipt_number' => $p->payment_gateway_ref ?? 'REC-' . str_pad($p->id, 6, '0', STR_PAD_LEFT),
                    ])->values()
                : [],
        ]);
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

        // ── Dashboard ─────────────────────────────────────────
        Route::get('/dashboard-stats', [AdminController::class, 'dashboardStats']);

        // ── تقارير المشتركين ──────────────────────────────────
        // GET /api/admin/subscribers-report?month=&year=
        Route::get('/subscribers-report', [AdminController::class, 'subscribersReport']);

        // ── تقارير الصناديق ───────────────────────────────────
        // GET /api/admin/fund-summary?type=&month=&year=
        Route::get('/fund-summary', [AdminController::class, 'fundSummary']);

        // ── تحويلات الأموال ───────────────────────────────────
        // GET  /api/admin/fund-transfers?type=&month=&year=
        // POST /api/admin/fund-transfers
        Route::get('/fund-transfers',  [AdminController::class, 'fundTransfers']);
        Route::post('/fund-transfers', [FundTransferController::class, 'store']);

        // ── الصناديق (القديمة — محتفظ بها للتوافقية) ──────────
        Route::get('/app-fund',          [AppFundController::class, 'index']);
        Route::get('/charity-fund',      [CharityFundController::class, 'index']);
        Route::get('/transfers-history', [FundTransferController::class, 'index']);

    });

    // مسارات المدير (Admin Only) — IsAdmin middleware
    Route::middleware('IsAdmin')->prefix('admin')->group(function () {

        // مسار تعديل العمولة (خاص بالمدير فقط)
        Route::post('/settings/commission', [SettingController::class, 'updateCommission']);

    });
});
