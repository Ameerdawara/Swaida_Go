<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\AppFundController;
use App\Http\Controllers\CharityFundController;
use App\Http\Controllers\FundTransferController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminHistoricalPaymentController;
use App\Models\Payment;
use App\Models\User;
use App\Services\FCMService;
Route::get('/test-notification-public', function () {
    
    // ابحث عن حسابك الشخصي حصراً لتتأكد من وصول الإشعار لجهازك
    $user = User::where('email', 'admin@gmail.com')->first(); // ضع إيميلك هنا
    
    if (!$user || !$user->fcm_token) {
        return response()->json([
            'status' => 'error',
            'message' => 'مستخدمك الحالي ليس لديه توكن مسجل في قاعدة البيانات!'
        ], 404);
    }

    $fcm = app(FCMService::class);
    $result = $fcm->sendToDevice(
        $user->fcm_token,
        'وصلتني يا أمير! 🚀',
        'هذا الإشعار مرسل لحسابك الشخصي للتأكد من الربط.'
    );

    return response()->json([
        'status' => 'success',
        'sent_to' => $user->full_name,
        'fcm_result' => $result
    ]);
});
/*
|--------------------------------------------------------------------------
| API Routes - Swaida Go
|--------------------------------------------------------------------------
*/

// -----------------------------------------------------------
// 1. المسارات العامة (Public Routes)
// -----------------------------------------------------------

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
    $user = User::findOrFail($id);
    if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
        return response()->json(['message' => 'رابط التوثيق غير صالح أو منتهي الصلاحية.'], 403);
    }
    if ($user->hasVerifiedEmail()) {
        return response()->json(['message' => 'البريد الإلكتروني موثق مسبقاً.'], 200);
    }
    if ($user->markEmailAsVerified()) {
        event(new \Illuminate\Auth\Events\Verified($user));
    }
    return response()->json(['message' => 'تم توثيق البريد الإلكتروني بنجاح.'], 200);
})->middleware(['signed'])->name('verification.verify');

Route::get('/auth/email-verification-status', [AuthController::class, 'checkEmailVerificationStatus']);
Route::post('/auth/resend-email-verification', [AuthController::class, 'resendEmailVerification']);
Route::post('/verify-otp',      [AuthController::class, 'verifyOtp']);
Route::post('/auth/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/payment/webhook', [PaymentController::class, 'webhook']);

// ── Mock payment — للتطوير المحلي فقط ──────────────────────
// هذه المسارات عامة (بدون auth) لأن الـ WebView يفتحها كمتصفح
if (app()->environment('local')) {

    // STEP 1: يعرض صفحة تأكيد HTML — المستخدم يختار تأكيد أو إلغاء
    Route::get('/payment/mock-success', function (Request $request) {
        $paymentIds = $request->query('payment_ids', '');
        $ids        = array_filter(explode(',', $paymentIds));

        if (empty($ids)) {
            return response()->json(['message' => 'لا توجد معرّفات دفعات'], 400);
        }

        return response('
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>تأكيد الدفع</title>
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body {
      font-family: Arial, sans-serif; background: #f4f7f5;
      display:flex; align-items:center; justify-content:center;
      min-height:100vh; padding:24px;
    }
    .card {
      background:white; border-radius:20px; padding:36px 28px;
      max-width:380px; width:100%;
      box-shadow:0 4px 24px rgba(0,0,0,0.08); text-align:center;
    }
    .icon { font-size:56px; margin-bottom:16px; }
    h2   { color:#1a3a2a; font-size:20px; margin-bottom:8px; }
    p    { color:#6b7280; font-size:14px; margin-bottom:28px; line-height:1.6; }
    .btn {
      display:block; width:100%; padding:14px;
      background:linear-gradient(135deg,#2d6a4f,#40916c);
      color:white; border:none; border-radius:12px;
      font-size:16px; font-weight:bold; cursor:pointer;
      text-decoration:none; margin-bottom:12px;
    }
    .btn-cancel { background:none; color:#ef4444; border:1.5px solid #ef4444; }
    .badge {
      margin-top:20px; font-size:11px; color:#9ca3af;
      display:flex; align-items:center; justify-content:center; gap:4px;
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">💳</div>
    <h2>تأكيد الدفع التجريبي</h2>
    <p>هذه بيئة اختبار محلية.<br>اضغط "تأكيد الدفع" لمحاكاة إتمام العملية.</p>
    <a href="/api/payment/mock-confirm?payment_ids=' . htmlspecialchars($paymentIds) . '" class="btn">
      ✅ تأكيد الدفع
    </a>
    <a href="/api/payment/cancel" class="btn btn-cancel">
      ❌ إلغاء
    </a>
    <div class="badge">🔒 بيئة تطوير محلية فقط</div>
  </div>
</body>
</html>
        ', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    });

    // STEP 2: ينفّذ الدفع فعلاً بعد تأكيد المستخدم
    Route::get('/payment/mock-confirm', function (Request $request) {
        $paymentIds = array_filter(explode(',', $request->query('payment_ids', '')));

        if (empty($paymentIds)) {
            return response()->json(['message' => 'لا توجد معرّفات دفعات'], 400);
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $payments = \App\Models\Payment::whereIn('id', $paymentIds)
                ->whereNull('paid_at')
                ->with('subscription.user')
                ->get();

            $totalPaid = 0;

            foreach ($payments as $payment) {
                $payment->status              = 'paid';
                $payment->paid_at             = \Carbon\Carbon::now();
                $payment->payment_gateway_ref = 'MOCK-' . strtoupper(\Illuminate\Support\Str::random(8));
                $payment->save();
                $totalPaid += $payment->amount;
            }

            $appFund = \App\Models\AppFund::firstOrCreate(['id' => 1]);
            $appFund->balance     += $totalPaid;
            $appFund->last_updated = \Carbon\Carbon::now();
            $appFund->save();

            \Illuminate\Support\Facades\DB::commit();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['message' => 'فشل: ' . $e->getMessage()], 500);
        }

        // ── إرسال إشعار للأدمن بعد commit ✅ ──────────────────
        try {
            $admin = User::where('email', 'admin@gmail.com')->first();

            if ($admin && $admin->fcm_token) {
                $payer     = $payments->first()->subscription?->user;
                $payerName = $payer?->full_name ?? 'مستخدم مجهول';

                $fcm = app(FCMService::class);
                $fcm->sendToDevice(
                    $admin->fcm_token,
                    'إشعار إداري: دفع جديد 💰',
                    "قام {$payerName} بدفع مبلغ " . number_format($totalPaid, 2) . ' دولار.',
                    ['type' => 'admin_payment_received']
                );

                \Illuminate\Support\Facades\Log::info('[MOCK] تم إرسال إشعار الأدمن', [
                    'payer'      => $payerName,
                    'total_paid' => $totalPaid,
                ]);
            } else {
                \Illuminate\Support\Facades\Log::warning('[MOCK] الأدمن غير موجود أو التوكن فارغ');
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('[MOCK] فشل إشعار الأدمن: ' . $e->getMessage());
        }
        // ────────────────────────────────────────────────────────

        // Flutter يكتشف /payment/success ويعمل go('/confirmation')
        return redirect(url('/api/payment/success?session_id=mock_' . time()));
    });

    // مسار الإلغاء — Flutter يكتشف /payment/cancel ويعود لـ /home
    Route::get('/payment/cancel', function () {
        return response(
            '<h3 style="text-align:center;margin-top:40px;font-family:Arial;color:#ef4444">تم إلغاء الدفع</h3>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    });
}
// ───────────────────────────────────────────────────────────


// -----------------------------------------------------------
// 2. المسارات المحمية - للمشتركين (Authenticated Users)
// -----------------------------------------------------------

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', function (Request $request) {
        $user = $request->user()->load(['subscription.payments']);

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

    Route::post('/update-fcm-token', [AuthController::class, 'updateFcmToken']);

    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'تم تسجيل الخروج بنجاح.'], 200);
    });

    Route::prefix('subscription')->group(function () {
        Route::post('/', [SubscriptionController::class, 'store']);
        Route::get('/',  [SubscriptionController::class, 'show']);
    });

    Route::prefix('payment')->group(function () {
        Route::post('/initiate', [PaymentController::class, 'initiate']);
    });

    Route::get('/settings/commission', [SettingController::class, 'getCommission']);

    // -----------------------------------------------------------
    // 3. مسارات الإدارة - للمدير فقط
    // -----------------------------------------------------------

    Route::middleware('can:admin-only')->prefix('admin')->group(function () {
        Route::get('/dashboard-stats',    [AdminController::class, 'dashboardStats']);
        Route::get('/subscribers-report', [AdminController::class, 'subscribersReport']);
        Route::get('/fund-summary',       [AdminController::class, 'fundSummary']);
        Route::get('/fund-transfers',     [AdminController::class, 'fundTransfers']);
        Route::post('/fund-transfers',    [FundTransferController::class, 'store']);
        Route::get('/app-fund',           [AppFundController::class, 'index']);
        Route::get('/charity-fund',       [CharityFundController::class, 'index']);
        Route::get('/transfers-history',  [FundTransferController::class, 'index']);
   Route::get('/subscribers-list', [AdminHistoricalPaymentController::class, 'subscribersList']);
    Route::get('/subscriber-payments/{userId}', [AdminHistoricalPaymentController::class, 'subscriberPayments']);
    Route::post('/mark-historical-paid', [AdminHistoricalPaymentController::class, 'markHistoricalPaid']);
        });

    Route::middleware('IsAdmin')->prefix('admin')->group(function () {
        Route::post('/settings/commission', [SettingController::class, 'updateCommission']);
        Route::post('/payments/mark-manual-paid', [PaymentController::class, 'markPreviousMonthsAsPaid']);
    });
});