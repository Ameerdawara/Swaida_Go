<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SubscriptionController extends Controller
{
    // عرض تفاصيل الاشتراك الحالي للمستخدم مع حالة الأشهر الـ 12
    public function show(Request $request)
    {
        $subscription = Subscription::with('payments')->where('user_id', $request->user()->id)->first();

        if (!$subscription) {
            return response()->json(['message' => 'لا يوجد اشتراك حالي'], 404);
        }

        // تحديث حالة الدفعات ديناميكياً قبل إرجاعها (للتأكد من حالات overdue و pending)
        $subscription->payments->each(function ($payment) {
            $payment->status = Payment::getStatus($payment->month_number, $payment->year, $payment->paid_at);
        });

        return response()->json(['subscription' => $subscription], 200);
    }

    // إنشاء اشتراك جديد
    public function store(Request $request)
    {
        $request->validate([
            'annual_amount' => 'required|numeric|min:12', // أقل مبلغ للاشتراك مثلاً 12 دولار
        ]);

        $user = $request->user();

        // التأكد من أن المستخدم وثّق حسابه (هاتف)
        if (!$user->phone_verified_at) {
            return response()->json(['message' => 'يجب توثيق رقم الهاتف أولاً'], 403);
        }

        // التأكد من عدم وجود اشتراك مسبق نشط
        if (Subscription::where('user_id', $user->id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'لديك اشتراك نشط بالفعل'], 400);
        }

        $monthly_amount = $request->annual_amount / 12; // حساب القسط الشهري
        $currentYear = Carbon::now()->year;

        DB::beginTransaction();
        try {
            // 1. إنشاء سجل الاشتراك
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'annual_amount' => $request->annual_amount,
                'monthly_amount' => $monthly_amount,
                'start_date' => Carbon::now()->toDateString(),
                'status' => 'active',
            ]);

            // 2. توليد 12 سجل دفع تلقائياً لهذه السنة
            $payments = [];
            for ($month = 1; $month <= 12; $month++) {
                $payments[] = [
                    'subscription_id' => $subscription->id,
                    'month_number' => $month,
                    'year' => $currentYear,
                    'amount' => $monthly_amount,
                    'status' => 'pending',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];
            }
            Payment::insert($payments);

            DB::commit();

            return response()->json([
                'message' => 'تم تفعيل الاشتراك بنجاح وتوليد الدفعات',
                'subscription' => $subscription->load('payments') // إرجاع الاشتراك مع الدفعات
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'حدث خطأ أثناء إنشاء الاشتراك', 'error' => $e->getMessage()], 500);
        }
    }
}