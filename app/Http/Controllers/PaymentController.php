<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\AppFund;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PaymentController extends Controller
{
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

        // 1. جلب الدفعات المستحقة
        $payments = Payment::where('subscription_id', $subscription->id)
            ->whereIn('month_number', $request->months)
            ->whereNull('paid_at')
            ->get();

        if ($payments->isEmpty()) {
            return response()->json(['message' => 'لم يتم العثور على دفعات مستحقة'], 400);
        }

        // 2. حساب المبلغ الأساسي (مثلاً 50 دولار)
        $baseAmount = $payments->sum('amount');

        // 3. جلب نسبة العمولة من الإعدادات (مثلاً 5%)
        $setting = Setting::first();
        $commissionPercentage = $setting ? $setting->commission_percentage : 0;

        // 4. تطبيق المعادلة: (المبلغ + النسبة المئوية + 0.5 ثابتة)
        $commissionAmount = $baseAmount * ($commissionPercentage / 100);
        $fixedFee = 0.5;
        
        $totalToPay = $baseAmount + $commissionAmount + $fixedFee;

        // 5. تجهيز بيانات الدفع للإرسال
        return response()->json([
            'message' => 'تم حساب إجمالي الرسوم',
            'details' => [
                'base_amount' => round($baseAmount, 2),        // المبلغ الأصلي
                'commission' => round($commissionAmount, 2),  // قيمة النسبة المئوية
                'fixed_fee' => $fixedFee,                     // 0.5
                'total_to_pay' => round($totalToPay, 2)       // المبلغ النهائي المطلوب دفعه
            ],
            'payment_ids' => $payments->pluck('id'),
            // هنا يوضع رابط بوابة الدفع بعد تمرير $totalToPay لها
            'checkout_url' => "https://checkout.example.com/pay?amount=" . $totalToPay 
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