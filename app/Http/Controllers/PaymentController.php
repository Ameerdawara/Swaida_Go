<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\AppFund;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{

    private function createPaddleCheckout(float $amount, array $paymentIds, int $userId): string
    {
        $isSandbox = config('services.paddle.env', 'sandbox') === 'sandbox';
        $baseUrl   = $isSandbox
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';

        $response = Http::withHeaders([
                'Authorization' => 'Bearer ' .config('services.paddle.secret_key'),
                'Content-Type'  => 'application/json',
            ])
            ->post("{$baseUrl}/transactions", [
                'items' => [[
                    'price' => [
                        'description' => 'رسوم الاشتراك الشهري',
                        'unit_price'  => ['amount' => (string)round($amount * 100), 'currency_code' => 'USD'],
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

        // تسجيل الاستجابة الكاملة لتسهيل التشخيص
        \Illuminate\Support\Facades\Log::info('Paddle API Response', [
            'status' => $response->status(),
            'body'   => $response->json(),
        ]);

        if (!$response->successful()) {
            throw new \Exception(
                'فشل الاتصال بـ Paddle: ' . $response->status() . ' — ' . $response->body()
            );
        }

        $json = $response->json();

        // Paddle يرجع رابط الـ checkout في: data.checkout.url
        // وأحياناً في: data.url — نجرب الاثنين
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
    // تهيئة جلسة الدفع (تُستدعى من تطبيق الـ Flutter)
public function initiate(Request $request)
{
    $request->validate([
        'months' => 'required|array',
        'months.*' => 'integer|min:1|max:12'
    ]);

    $user = $request->user();
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

    $baseAmount = $payments->sum('amount');
    $setting = Setting::first();
    $commissionPercentage = $setting ? $setting->commission_percentage : 0;
    $commissionAmount = $baseAmount * ($commissionPercentage / 100);
    $fixedFee = 0.5;
    $totalToPay = $baseAmount + $commissionAmount + $fixedFee;

    // ── Mock Mode للتطوير المحلي ──────────────────────────
    if (app()->environment('local')) {
        return response()->json([
            'message'      => '[MOCK] تم حساب إجمالي الرسوم',
            'details'      => [
                'base_amount'  => round($baseAmount, 2),
                'commission'   => round($commissionAmount, 2),
                'fixed_fee'    => $fixedFee,
                'total_to_pay' => round($totalToPay, 2),
            ],
            'payment_ids'  => $payments->pluck('id'),
            'checkout_url' => url('/api/payment/mock-success?payment_ids=' . implode(',', $payments->pluck('id')->toArray())),
            'total_amount' => round($totalToPay, 2),
            'months'       => $request->months,
        ], 200);
    }
    // ─────────────────────────────────────────────────────

    try {
        $checkoutUrl = $this->createPaddleCheckout(
            amount: $totalToPay,
            paymentIds: $payments->pluck('id')->toArray(),
            userId: $user->id
        );
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('Paddle Checkout Error', ['error' => $e->getMessage()]);
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
        'payment_ids'  => $payments->pluck('id'),
        'checkout_url' => $checkoutUrl,
        'total_amount' => round($totalToPay, 2),
        'months'       => $request->months,
    ], 200);
}
    // استقبال تأكيد الدفع من البوابة (Webhook)
    public function webhook(Request $request)
    {
        // 1. يجب التحقق من توقيع البوابة (Signature Verification) أولاً لضمان الأمان[cite: 1]
        // سنفترض أن التوقيع صحيح في هذا الكود المبدئي
        $signature = $request->header('Paddle-Signature');
        $body      = $request->getContent();

        // Paddle يرسل توقيع HMAC-SHA256
        $parts = [];
foreach (explode(';', $signature) as $part) {
    [$k, $v] = explode('=', $part, 2);
    $parts[$k] = $v;
}
$ts = $parts['ts'] ?? '';
$h1 = $parts['h1'] ?? '';
$signed = $ts . ':' . $body;
$expected = hash_hmac('sha256', $signed, config('services.paddle.secret_key'));
if (!hash_equals($expected, $h1)) {
    return response()->json(['message' => 'توقيع غير صحيح'], 401);
}
        // ✅ صحيح — Paddle يرسل البيانات داخل data.custom_data
        $payment_ids = $request->input('data.custom_data.payment_ids');
        $gateway_ref = $request->input('data.id');
        if (!$payment_ids || !is_array($payment_ids)) {
            return response()->json(['message' => 'بيانات غير مكتملة'], 400);
        }

        DB::beginTransaction();
        try {
            // جلب الدفعات
            $payments = Payment::whereIn('id', $payment_ids)->whereNull('paid_at')->get();

            if ($payments->isEmpty()) {
                throw new \Exception('الدفعات مدفوعة مسبقاً أو غير موجودة');
            }

            $total_paid = 0;

            // تحديث حالات الدفع[cite: 1]
            foreach ($payments as $payment) {
                $payment->status = 'paid';
                $payment->paid_at = Carbon::now();
                $payment->payment_gateway_ref = $gateway_ref;
                $payment->save();

                $total_paid += $payment->amount;
            }

            // إضافة المبلغ الإجمالي إلى صندوق التطبيق بطريقة آمنة[cite: 1]
            $appFund = AppFund::firstOrCreate(['id' => 1]); // إنشاء الصندوق إن لم يكن موجوداً
            $appFund->balance += $total_paid;
            $appFund->last_updated = Carbon::now();
            $appFund->save();

            DB::commit();

            return response()->json(['message' => 'تم معالجة الدفع بنجاح وتحديث الصندوق'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'فشل معالجة الدفع', 'error' => $e->getMessage()], 500);
        }
    }
}
