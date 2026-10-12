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
        'annual_amount' => 'required|numeric|min:60|max:1200',
    ], [
        'annual_amount.required' => 'مبلغ الاشتراك السنوي مطلوب',
        'annual_amount.numeric'  => 'مبلغ الاشتراك يجب أن يكون رقمًا',
        'annual_amount.min'      => 'مبلغ الاشتراك السنوي يجب ألا يقل عن 60',
        'annual_amount.max'      => 'مبلغ الاشتراك السنوي يجب ألا يزيد عن 1200',
    ]);

    $user = $request->user();

    if (Subscription::where('user_id', $user->id)->where('status', 'active')->exists()) {
        return response()->json(['message' => 'لديك اشتراك نشط بالفعل'], 400);
    }

    $monthly_amount = $request->annual_amount / 12;
    $currentYear = Carbon::now()->year;

    DB::beginTransaction();
    try {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'annual_amount' => $request->annual_amount,
            'monthly_amount' => $monthly_amount,
            'start_date' => Carbon::now()->toDateString(),
            'status' => 'active',
        ]);

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
            'subscription' => $subscription->load('payments')
        ], 201);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['message' => 'حدث خطأ أثناء إنشاء الاشتراك', 'error' => $e->getMessage()], 500);
    }
}
}