<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\AppFund;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: إرسال إشعار للأدمن (مشترك بين webhook الحقيقي والـ mock)
    // ─────────────────────────────────────────────────────────────────────────
    private function notifyAdmin(float $totalPaid, string $payerName): void
    {
        try {
            $admin = \App\Models\User::where('email', 'admin@gmail.com')->first();

            if (!$admin) {
                Log::warning('[FCM] الأدمن غير موجود في قاعدة البيانات بهذا الإيميل.');
                return;
            }

            if (empty($admin->fcm_token)) {
                Log::warning('[FCM] توكن الأدمن فارغ.', ['admin_id' => $admin->id]);
                return;
            }

            $fcm    = app(\App\Services\FCMService::class);
            $result = $fcm->sendToDevice(
                $admin->fcm_token,
                'إشعار إداري: دفع جديد 💰',
                "قام {$payerName} بدفع مبلغ " . number_format($totalPaid, 2) . ' دولار.',
                ['type' => 'admin_payment_received']
            );

            Log::info('[FCM] نتيجة إشعار الأدمن', [
                'success'    => $result ? 'ناجح' : 'فشل',
                'payer'      => $payerName,
                'total_paid' => $totalPaid,
            ]);

        } catch (\Exception $e) {
            Log::error('[FCM] خطأ أثناء إرسال إشعار الأدمن: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: معالجة الدفع الفعلية (مشتركة بين webhook الحقيقي والـ mock)
    // ─────────────────────────────────────────────────────────────────────────
    private function processPayments(array $paymentIds, ?string $gatewayRef = null): array
    {
        $payments = Payment::whereIn('id', $paymentIds)
            ->whereNull('paid_at')
            ->with('subscription.user')
            ->get();

        if ($payments->isEmpty()) {
            throw new \Exception('الدفعات مدفوعة مسبقاً أو غير موجودة');
        }

        $totalPaid = 0;

        foreach ($payments as $payment) {
            $payment->status               = 'paid';
            $payment->paid_at              = Carbon::now();
            $payment->payment_gateway_ref  = $gatewayRef ?? 'mock_' . uniqid();
            $payment->save();

            $totalPaid += $payment->amount;
        }

        // تحديث صندوق التطبيق
        $appFund              = AppFund::firstOrCreate(['id' => 1]);
        $appFund->balance    += $totalPaid;
        $appFund->last_updated = Carbon::now();
        $appFund->save();

        $payer     = $payments->first()->subscription?->user;
        $payerName = $payer?->full_name ?? 'مستخدم مجهول';

        return [
            'total_paid' => $totalPaid,
            'payer_name' => $payerName,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // createPaddleCheckout
    // ─────────────────────────────────────────────────────────────────────────
    private function createPaddleCheckout(float $amount, array $paymentIds, int $userId): string
    {
        $isSandbox = config('services.paddle.env', 'sandbox') === 'sandbox';
        $baseUrl   = $isSandbox
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';

        $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.paddle.secret_key'),
                'Content-Type'  => 'application/json',
            ])
            ->post("{$baseUrl}/transactions", [
                'items' => [[
                    'price' => [
                        'description' => 'رسوم الاشتراك الشهري',
                        'unit_price'  => ['amount' => (string) round($amount * 100), 'currency_code' => 'USD'],
                        'tax_mode'    => 'exclusive',
                    ],
                    'quantity' => 1,
                ]],
                'custom_data' => [
                    'payment_ids' => $paymentIds,
                    'user_id'     => $userId,
                ],
                'success_url' => url('/payment/success?session_id={checkout.id}'),
                'cancel_url'  => url('/payment/cancel'),
            ]);

        Log::info('Paddle API Response', [
            'status' => $response->status(),
            'body'   => $response->json(),
        ]);

        if (!$response->successful()) {
            throw new \Exception(
                'فشل الاتصال بـ Paddle: ' . $response->status() . ' — ' . $response->body()
            );
        }

        $json = $response->json();

        $checkoutUrl = $json['data']['checkout']['url']
            ?? $json['data']['url']
            ?? null;

        if (!$checkoutUrl) {
            throw new \Exception(
                'لم يتم الحصول على رابط الدفع من Paddle. الاستجابة: ' . json_encode($json)
            );
        }

        return $checkoutUrl;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // initiate — تهيئة جلسة الدفع
    // ─────────────────────────────────────────────────────────────────────────
    public function initiate(Request $request)
    {
        $request->validate([
            'months'   => 'required|array',
            'months.*' => 'integer|min:1|max:12',
        ]);

        $user         = $request->user();
        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json(['message' => 'لا يوجد اشتراك نشط'], 404);
        }

        $payments = Payment::where('subscription_id', $subscription->id)
            ->whereIn('month_number', $request->months)
            ->whereNull('paid_at')
            ->get();

        if ($payments->isEmpty()) {
            return response()->json(['message' => 'لم يتم العثور على دفعات مستحقة'], 400);
        }

        $baseAmount           = $payments->sum('amount');
        $setting              = Setting::first();
        $commissionPercentage = $setting ? $setting->commission_percentage : 0;
        $commissionAmount     = $baseAmount * ($commissionPercentage / 100);
        $fixedFee             = 0.5;
        $totalToPay           = $baseAmount + $commissionAmount + $fixedFee;
        $paymentIds           = $payments->pluck('id')->toArray();

        // ── Mock Mode للتطوير المحلي ──────────────────────────────────────────
        if (app()->environment('local')) {
            $mockUrl = url('/api/payment/mock-success?payment_ids=' . implode(',', $paymentIds));

            return response()->json([
                'message'      => '[MOCK] تم حساب إجمالي الرسوم',
                'details'      => [
                    'base_amount'  => round($baseAmount, 2),
                    'commission'   => round($commissionAmount, 2),
                    'fixed_fee'    => $fixedFee,
                    'total_to_pay' => round($totalToPay, 2),
                ],
                'payment_ids'  => $paymentIds,
                'checkout_url' => $mockUrl,
                'total_amount' => round($totalToPay, 2),
                'months'       => $request->months,
            ], 200);
        }
        // ─────────────────────────────────────────────────────────────────────

        try {
            $checkoutUrl = $this->createPaddleCheckout(
                amount: $totalToPay,
                paymentIds: $paymentIds,
                userId: $user->id
            );
        } catch (\Exception $e) {
            Log::error('Paddle Checkout Error', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'فشل إنشاء جلسة الدفع: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message'      => 'تم حساب إجمالي الرسوم',
            'details'      => [
                'base_amount'  => round($baseAmount, 2),
                'commission'   => round($commissionAmount, 2),
                'fixed_fee'    => $fixedFee,
                'total_to_pay' => round($totalToPay, 2),
            ],
            'payment_ids'  => $paymentIds,
            'checkout_url' => $checkoutUrl,
            'total_amount' => round($totalToPay, 2),
            'months'       => $request->months,
        ], 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // mockSuccess — يحاكي نجاح الدفع في بيئة local بدون Paddle
    // الـ Route: GET /api/payment/mock-success?payment_ids=1,2,3
    // ─────────────────────────────────────────────────────────────────────────
    public function mockSuccess(Request $request)
    {
        // تأكد أننا في بيئة local فقط
        if (!app()->environment('local')) {
            return response()->json(['message' => 'غير متاح'], 403);
        }

        $ids = array_filter(explode(',', $request->query('payment_ids', '')));

        if (empty($ids)) {
            return response()->json(['message' => 'payment_ids مطلوبة'], 400);
        }

        DB::beginTransaction();
        try {
            $result = $this->processPayments(array_map('intval', $ids), 'mock_ref_' . uniqid());
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[MOCK] فشل معالجة الدفع: ' . $e->getMessage());
            return response()->json(['message' => 'فشل معالجة الدفع', 'error' => $e->getMessage()], 500);
        }

        // إرسال إشعار الأدمن بعد commit ✅
        $this->notifyAdmin($result['total_paid'], $result['payer_name']);

        return response()->json([
            'message'    => '[MOCK] تم معالجة الدفع بنجاح',
            'total_paid' => round($result['total_paid'], 2),
            'payer'      => $result['payer_name'],
        ], 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // webhook — استقبال تأكيد الدفع من Paddle
    // ─────────────────────────────────────────────────────────────────────────
    public function webhook(Request $request)
    {
        Log::info('[Webhook] طلب وارد من Paddle', [
            'ip'      => $request->ip(),
            'headers' => $request->headers->all(),
        ]);

        $signature = $request->header('Paddle-Signature');
        $body      = $request->getContent();

        // التحقق من التوقيع
        $parts = [];
        foreach (explode(';', $signature) as $part) {
            [$k, $v] = explode('=', $part, 2);
            $parts[$k] = $v;
        }

        $ts     = $parts['ts'] ?? '';
        $h1     = $parts['h1'] ?? '';
        $signed = $ts . ':' . $body;

        // ✅ استخدام webhook_secret وليس secret_key
        $expected = hash_hmac('sha256', $signed, config('services.paddle.webhook_secret'));

        if (!hash_equals($expected, $h1)) {
            Log::warning('[Webhook] توقيع غير صحيح', ['received' => $h1]);
            return response()->json(['message' => 'توقيع غير صحيح'], 401);
        }

        $paymentIds = $request->input('data.custom_data.payment_ids');
        $gatewayRef = $request->input('data.id');

        if (!$paymentIds || !is_array($paymentIds)) {
            Log::warning('[Webhook] payment_ids مفقودة أو غير صحيحة');
            return response()->json(['message' => 'بيانات غير مكتملة'], 400);
        }

        DB::beginTransaction();
        try {
            $result = $this->processPayments($paymentIds, $gatewayRef);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Webhook] فشل معالجة الدفع: ' . $e->getMessage());
            return response()->json(['message' => 'فشل معالجة الدفع', 'error' => $e->getMessage()], 500);
        }

        // إرسال إشعار الأدمن بعد commit ✅
        $this->notifyAdmin($result['total_paid'], $result['payer_name']);

        return response()->json(['message' => 'تم معالجة الدفع بنجاح وتحديث الصندوق'], 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // markPreviousMonthsAsPaid
    // ─────────────────────────────────────────────────────────────────────────
    public function markPreviousMonthsAsPaid(Request $request)
    {
        $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
            'up_to_month'     => 'required|integer|min:1|max:12',
            'up_to_year'      => 'required|integer|min:2024',
        ], [
            'subscription_id.required' => 'رقم الاشتراك مطلوب.',
            'subscription_id.exists'   => 'الاشتراك المحدد غير موجود.',
            'up_to_month.required'     => 'يرجى تحديد الشهر.',
            'up_to_year.required'      => 'يرجى تحديد السنة.',
        ]);

        $updatedCount = \App\Models\Payment::where('subscription_id', $request->subscription_id)
            ->where('status', '!=', 'paid')
            ->where(function ($query) use ($request) {
                $query->where('year', '<', $request->up_to_year)
                    ->orWhere(function ($q) use ($request) {
                        $q->where('year', $request->up_to_year)
                          ->where('month_number', '<=', $request->up_to_month);
                    });
            })
            ->update([
                'status'  => 'paid',
                'paid_at' => now(),
            ]);

        return response()->json([
            'message' => "تم بنجاح تحديث {$updatedCount} شهر كمدفوعة لهذا المشترك.",
        ], 200);
    }
}