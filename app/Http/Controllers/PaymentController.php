<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\AppFund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PaymentController extends Controller
{
    // تهيئة جلسة الدفع (تُستدعى من تطبيق الـ Flutter)
    public function initiate(Request $request)
    {
        $request->validate([
            'months' => 'required|array', // مصفوفة بأرقام الأشهر المراد دفعها (مثال: [1, 2, 3])[cite: 1]
            'months.*' => 'integer|min:1|max:12'
        ]);

        $user = $request->user();
        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json(['message' => 'لا يوجد اشتراك نشط'], 404);
        }

        // جلب الدفعات المطلوبة والتأكد أنها غير مدفوعة
        $payments = Payment::where('subscription_id', $subscription->id)
            ->whereIn('month_number', $request->months)
            ->whereNull('paid_at')
            ->get();

        if ($payments->isEmpty()) {
            return response()->json(['message' => 'لم يتم العثور على دفعات مستحقة للأشهر المحددة'], 400);
        }

        $total_amount = $payments->sum('amount');
        $payment_ids = $payments->pluck('id')->toArray();

        // هُنا نقوم بالتخاطب مع PaymentGatewayService (Paddle / Stripe)[cite: 1]
        // في هذه المرحلة سنقوم بمحاكاة (Mock) للرابط الخاص ببوابة الدفع
        
        $mock_checkout_url = "https://checkout.paddle.com/mock-session-12345";
        $mock_session_id = "session_12345_" . uniqid();

        // يمكنك تخزين $mock_session_id في الدفعات أو في جدول وسيط لربط الـ Webhook لاحقاً

        return response()->json([
            'message' => 'تم إنشاء جلسة الدفع',
            'checkout_url' => $mock_checkout_url,
            'session_id' => $mock_session_id,
            'total_amount' => $total_amount,
            'payment_ids' => $payment_ids // نحتفظ بها لتحديثها عند نجاح الدفع
        ], 200);
    }

    // استقبال تأكيد الدفع من البوابة (Webhook)
    public function webhook(Request $request)
    {
        // 1. يجب التحقق من توقيع البوابة (Signature Verification) أولاً لضمان الأمان[cite: 1]
        // سنفترض أن التوقيع صحيح في هذا الكود المبدئي

        $payment_ids = $request->input('payment_ids'); // يتم إرسالها ضمن الـ Metadata من البوابة
        $gateway_ref = $request->input('transaction_id'); // رقم المعاملة من البوابة

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