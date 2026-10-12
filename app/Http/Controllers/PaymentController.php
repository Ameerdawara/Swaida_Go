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
    // Helper: إرسال إشعار للأدمن + حفظه في جدول notifications
    // ─────────────────────────────────────────────────────────────────────────
    private function notifyAdmin(float $totalPaid, string $payerName): void
    {
        try {
            $admin = \App\Models\User::where('email', 'admin@gmail.com')->first();

            if (!$admin) {
                Log::warning('[FCM] الأدمن غير موجود في قاعدة البيانات بهذا الإيميل.');
                return;
            }

            $title   = 'إشعار دفع جديد 💰';
            $message = "قام {$payerName} بدفع مبلغ " . number_format($totalPaid, 2) . ' دولار.';

            if (!empty($admin->fcm_token)) {
                $fcm    = app(\App\Services\FCMService::class);
                $result = $fcm->sendToDevice(
                    $admin->fcm_token,
                    $title,
                    $message,
                    ['type' => 'admin_payment_received']
                );
                Log::info('[FCM] نتيجة إشعار الأدمن', [
                    'success'    => $result ? 'ناجح' : 'فشل',
                    'payer'      => $payerName,
                    'total_paid' => $totalPaid,
                ]);
            } else {
                Log::warning('[FCM] توكن الأدمن فارغ.', ['admin_id' => $admin->id]);
            }

            $admin->notifications()->create([
                'id'   => \Illuminate\Support\Str::uuid(),
                'type' => 'admin_payment_received',
                'data' => [
                    'title'      => $title,
                    'body'       => $message,
                    'type'       => 'admin_payment_received',
                    'payer_name' => $payerName,
                    'amount'     => $totalPaid,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[notifyAdmin] خطأ: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: معالجة الدفع الفعلية
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
            $payment->status              = 'paid';
            $payment->paid_at             = Carbon::now();
            $payment->payment_gateway_ref = $gatewayRef ?? 'tap_' . uniqid();
            $payment->save();
            $totalPaid += $payment->amount;
        }

        $appFund               = AppFund::firstOrCreate(['id' => 1]);
        $appFund->balance     += $totalPaid;
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
    // createTapCheckout — إنشاء جلسة دفع عبر Tap Payment
    // ─────────────────────────────────────────────────────────────────────────
    private function createTapCheckout(float $amount, array $paymentIds, int $userId): string
    {
        $user = \App\Models\User::find($userId);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.tap.secret_key'),
            'Content-Type'  => 'application/json',
        ])->post('https://api.tap.company/v2/charges', [
            'amount'       => round($amount, 2),
            'currency'     => 'USD',
            'threeDSecure' => true,
            'description'  => 'اشتراك - ' . count($paymentIds) . ' شهر',
            'metadata'     => [
                'payment_ids' => implode(',', $paymentIds),
                'user_id'     => $userId,
            ],
            'customer' => [
                'first_name' => $user?->full_name ?? 'مستخدم',
                'email'      => $user?->email ?? '',
            ],
            'source'   => ['id' => 'src_all'],
            'post'     => ['url' => url('/api/payment/webhook')],
            'redirect' => ['url' => url('/api/payment/success')],
        ]);

        Log::info('Tap API Response', [
            'status' => $response->status(),
            'body'   => $response->json(),
        ]);

        if (!$response->successful()) {
            Log::error('Tap Error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \Exception('فشل Tap: ' . $response->body());
        }

        $json        = $response->json();
        $checkoutUrl = $json['transaction']['url'] ?? null;

        if (!$checkoutUrl) {
            throw new \Exception('لم يرجع checkout URL من Tap: ' . json_encode($json));
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
            $checkoutUrl = $this->createTapCheckout(
                amount: $totalToPay,
                paymentIds: $paymentIds,
                userId: $user->id
            );
        } catch (\Exception $e) {
            Log::error('Tap Checkout Error', ['error' => $e->getMessage()]);
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
    // mockSuccess — يحاكي نجاح الدفع في بيئة local
    // ─────────────────────────────────────────────────────────────────────────
    public function mockSuccess(Request $request)
    {
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
            Log::error('[MOCK] فشل: ' . $e->getMessage());
            return response()->json(['message' => 'فشل', 'error' => $e->getMessage()], 500);
        }

        $this->notifyAdmin($result['total_paid'], $result['payer_name']);

        return response()->json([
            'message'    => '[MOCK] تم معالجة الدفع بنجاح',
            'total_paid' => round($result['total_paid'], 2),
            'payer'      => $result['payer_name'],
        ], 200);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // webhook — استقبال تأكيد الدفع من Tap
    // ─────────────────────────────────────────────────────────────────────────
    public function webhook(Request $request)
{
    Log::info('[Tap Webhook] طلب وارد', [
        'ip'      => $request->ip(),
        'body'    => $request->all(),
        'headers' => $request->headers->all(),
    ]);

    $status     = $request->input('status');
    $id         = $request->input('id');
    $metadata   = $request->input('metadata', []);
    $paymentIds = array_filter(explode(',', $metadata['payment_ids'] ?? ''));

    if ($status !== 'CAPTURED') {
        Log::info('[Tap Webhook] تجاهل', ['status' => $status]);
        return response()->json(['message' => 'تم الاستقبال'], 200);
    }

    if (empty($paymentIds)) {
        return response()->json(['message' => 'بيانات ناقصة'], 400);
    }

    DB::beginTransaction();
    try {
        $result = $this->processPayments(array_map('intval', $paymentIds), $id);
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('[Tap Webhook] فشل: ' . $e->getMessage());
        return response()->json(['message' => 'فشل'], 500);
    }

    $this->notifyAdmin($result['total_paid'], $result['payer_name']);
    return response()->json(['message' => 'تم بنجاح'], 200);
}

    // ─────────────────────────────────────────────────────────────────────────
    // latestReceipt — آخر دفعة مكتملة للمستخدم الحالي
    // ─────────────────────────────────────────────────────────────────────────
    public function latestReceipt(Request $request)
    {
        $user         = $request->user();
        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json(['message' => 'لا يوجد اشتراك'], 404);
        }

        $payment = Payment::where('subscription_id', $subscription->id)
            ->whereNotNull('paid_at')
            ->orderBy('paid_at', 'desc')
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'لا توجد دفعات مكتملة'], 404);
        }

        return response()->json([
            'id'             => $payment->id,
            'months'         => [$payment->month_number],
            'amount'         => (float) $payment->amount,
            'paid_at'        => $payment->paid_at,
            'receipt_number' => $payment->payment_gateway_ref
                ?? 'REC-' . str_pad($payment->id, 6, '0', STR_PAD_LEFT),
        ]);
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